<?php
declare(strict_types=1);
namespace CodeGraft;
use PDO;
use Throwable;

/**
 * Owner-only companion panel. The standalone admin script authenticates BEFORE
 * calling this class. The UI is independent of project controllers/routes.
 */
final class ControlCenter
{
    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function rollback(Vault $vault, string $id, string $confirmation, string $password): string
    {
        if ($confirmation !== 'RESTAURER' || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Identifiant ou confirmation de restauration invalide.');
        }
        $admin = $vault->json('admin.json');
        if ($password === '' || !password_verify($password, (string)($admin['password_hash'] ?? ''))) {
            throw new \RuntimeException('Mot de passe propriétaire incorrect.');
        }
        // Workspace rechecks live policy + per-file SHA before restoring all files.
        $result = (new Workspace($vault))->rollback($id, true);
        return 'Version restaurée : ' . (string)($result['id'] ?? $id) .
            '. La base SQL n’est pas restaurée.';
    }

    /** Read-only OAuth client summary; never return hashes/tokens. */
    public static function clients(Vault $vault): array
    {
        $path = $vault->directory . '/oauth.sqlite';
        if (is_link($path) || !is_file($path)) return [];
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $db->query('SELECT c.client_id, c.redirect_uri, c.created_at,
          COUNT(t.id) AS active_tokens FROM mcp_dcr_clients c
          LEFT JOIN mcp_dcr_tokens t ON t.client_id = c.client_id
            AND t.revoked_at IS NULL AND t.refresh_expires_at > ' . (int)time() . '
          GROUP BY c.client_id, c.redirect_uri, c.created_at
          ORDER BY c.created_at DESC LIMIT 30')->fetchAll();
    }

    public static function revokeClient(Vault $vault, string $clientId, string $password): string
    {
        if (!preg_match('/^mcp_[A-Za-z0-9_-]{10,150}$/D', $clientId)) {
            throw new \InvalidArgumentException('Client OAuth invalide.');
        }
        $owner = $vault->json('admin.json');
        if ($password === '' || !password_verify($password, (string)($owner['password_hash'] ?? ''))) {
            throw new \RuntimeException('Mot de passe propriétaire incorrect.');
        }
        $path = $vault->directory . '/oauth.sqlite';
        if (is_link($path) || !is_file($path)) throw new \RuntimeException('OAuth non initialisé.');
        $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT 1 FROM mcp_dcr_clients WHERE client_id=?');
            $check->execute([$clientId]);
            if (!$check->fetchColumn()) throw new \RuntimeException('Client introuvable.');
            $stmt = $db->prepare('UPDATE mcp_dcr_tokens SET revoked_at=? WHERE client_id=? AND revoked_at IS NULL');
            $stmt->execute([time(), $clientId]);
            $db->commit();
            return 'Jetons OAuth révoqués pour le client sélectionné.';
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function appTools(Vault $vault): array
    {
        if (!defined('CGF_ROOT')) return ['tools' => [], 'error' => 'Framework non chargé'];
        $file = CGF_ROOT . '/app/actions.php';
        if (!is_file($file) || is_link($file)) return ['tools' => [], 'error' => 'Registre applicatif absent'];
        try {
            $cfg = \CGF\Kernel::config();
            $registry = \CGF\ActionLoader::load(new \CGF\Database($cfg['database']));
            $items = [];
            foreach ($registry->all() as $name => $entry) {
                $items[] = [
                    'tool' => 'app_' . $name,
                    'mode' => (string)($entry['mode'] ?? ''),
                    'description' => (string)($entry['description'] ?? ''),
                    'http' => 'POST /actions/' . $name,
                ];
            }
            return ['tools' => array_slice($items, 0, 150), 'error' => null];
        } catch (Throwable $e) {
            error_log('CodeGraft action inventory unavailable: ' . $e->getMessage());
            return ['tools' => [], 'error' => 'Le registre applicatif est indisponible : vérifiez app/actions.php'];
        }
    }

    private static function history(Vault $vault): array
    {
        $policy = $vault->policy();
        if (empty($policy['enabled']) || empty($policy['permissions']['history'])) return [];
        return (new Workspace($vault))->history(30)['releases'] ?? [];
    }

    /** Show before and current deployed source ONLY if read+history rights allow it. */
    private static function diff(Vault $vault, string $id, string $path): string
    {
        $p = $vault->policy();
        if (empty($p['enabled']) || empty($p['permissions']['read']) ||
            empty($p['permissions']['history'])) return '<p>Activez lecture et historique pour voir les différences.</p>';
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !Policy::isRelative($path)) {
            return '<p>Version ou fichier invalide.</p>';
        }
        Policy::path($p, $path);
        $release = $vault->json('releases/' . $id . '.json', []);
        foreach ($release['files'] ?? [] as $item) {
            if (!is_array($item) || ($item['path'] ?? '') !== $path) continue;
            if (($item['operation'] ?? '') === 'delete') {
                $file = $vault->root . '/' . $path;
                if (file_exists($file) || is_link($file)) {
                    return '<p>Ce fichier a été recréé depuis sa suppression : aperçu historique refusé.</p>';
                }
                $before = base64_decode((string)($item['before_base64'] ?? ''), true);
                if (!is_string($before) || strlen($before) > 50000
                    || str_contains($before, "\0")
                    || !hash_equals((string)($item['before_sha256'] ?? ''), hash('sha256', $before))) {
                    return '<p>Ancienne version indisponible ou invalide.</p>';
                }
                $lines = explode("\n", str_replace("\r\n", "\n", $before));
                $html = '<p><strong>Fichier supprimé par CodeGraft.</strong> Restauration possible si la version est encore éligible.</p><pre class="cg-diff">';
                foreach (array_slice($lines, 0, 120) as $line) {
                    $html .= '<span class="removed">− ' . self::esc($line) . '</span>' . "\n";
                }
                if (count($lines) > 120) $html .= '<span>… contenu tronqué</span>';
                return $html . '</pre>';
            }
            $file = $vault->root . '/' . $path;
            $resolved = realpath($file);
            $root = realpath($vault->root);
            if (is_link($file) || $resolved === false || $root === false ||
                !Policy::under($resolved, $root) || !is_file($resolved)) {
                return '<p>Fichier absent, hors du projet ou lien symbolique : comparaison refusée.</p>';
            }
            // Every ancestor must also be a real directory, not a symlink.
            $segments = explode('/', $path);
            $parent = $vault->root;
            foreach (array_slice($segments, 0, -1) as $segment) {
                $parent .= '/' . $segment;
                if (is_link($parent)) return '<p>Comparaison refusée : lien symbolique.</p>';
            }
            $size = filesize($resolved);
            if ($size === false || $size > 50000) return '<p>Comparaison limitée à 50 Ko.</p>';
            $current = file_get_contents($resolved);
            if (!is_string($current) || !hash_equals((string)($item['after_sha256'] ?? ''), hash('sha256', $current))) {
                // New versions must never be presented as the bytes of this release.
                return '<p>Le fichier a été modifié après cette version. Différentiel historique non disponible.</p>';
            }
            $before = !empty($item['existed']) ? base64_decode((string)($item['before_base64'] ?? ''), true) : '';
            if (!is_string($before) || strlen($before) > 50000 || strlen($current) > 50000 ||
                str_contains($before, "\0") || str_contains($current, "\0")) {
                return '<p>Comparaison non disponible pour ce fichier (taille/type).</p>';
            }
            $a = explode("\n", str_replace("\r\n", "\n", $before));
            $b = explode("\n", str_replace("\r\n", "\n", $current));
            // Bounded readable diff. Unchanged prefix/suffix are summarized.
            $head = 0;
            while ($head < min(count($a), count($b)) && $a[$head] === $b[$head]) $head++;
            $tail = 0;
            while ($tail < min(count($a), count($b)) - $head &&
                $a[count($a)-1-$tail] === $b[count($b)-1-$tail]) $tail++;
            $removed = array_slice($a, $head, max(0, count($a)-$head-$tail));
            $added = array_slice($b, $head, max(0, count($b)-$head-$tail));
            $view = '<p>Avant ↔ version déployée. Segments communs : ' . $head . ' ligne(s) au début, ' . $tail . ' à la fin.</p>';
            $view .= '<pre class="cg-diff">';
            foreach (array_slice($removed, 0, 120) as $line) $view .= '<span class="removed">− ' . self::esc($line) . '</span>' . "\n";
            if (count($removed) > 120) $view .= '<span>… changements retirés tronqués</span>' . "\n";
            foreach (array_slice($added, 0, 120) as $line) $view .= '<span class="added">+ ' . self::esc($line) . '</span>' . "\n";
            if (count($added) > 120) $view .= '<span>… changements ajoutés tronqués</span>' . "\n";
            return $view . '</pre>';
        }
        return '<p>Fichier absent de cette version.</p>';
    }

    public static function render(Vault $vault, string $postUrl, string $csrfField, string $message = '', string $settingsHtml = '', string $activeTab = 'versions'): string
    {
        $e = static fn(mixed $x): string => self::esc($x);
        $policy = $vault->policy();
        $allowedTabs = ['versions','tools','commands','audit','oauth','settings'];
        if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'versions';
        $html = '<section class="cg-cc" lt-tabs="' . self::esc($activeTab) . '"><style>' . self::css() . '</style>';
        $html .= '<div class="cg-cc-intro"><span class="cg-cc-eyebrow">CENTRE DE CONTRÔLE / CODEGRAFT</span>'
            . '<h2>Votre atelier IA.</h2><p>Versions, actions MCP, connexions et sécurité. Un seul espace, sans dépendance à l’application métier.</p></div>';
        // Anchor links work without JavaScript; lt-tab upgrades them to real tabs.
        $html .= '<nav class="cg-cc-nav" aria-label="Sections CodeGraft">'
            . '<a lt-tab="versions" href="#cg-history">Versions</a>'
            . '<a lt-tab="tools" href="#cg-tools">Outils MCP</a>'
            . '<a lt-tab="commands" href="#cg-commands">Commandes</a>'
            . '<a lt-tab="audit" href="#cg-log">Journal</a>'
            . '<a lt-tab="oauth" href="#cg-clients">OAuth</a>'
            . '<a lt-tab="settings" href="#cg-security">Paramètres</a></nav>';
        if ($message !== '') $html .= '<p role="status" class="cg-cc-note">' . $e($message) . '</p>';
        $html .= '<div lt-panel="versions" class="cg-cc-panel"><section id="cg-history"><h3>Historique des versions</h3>';
        try {
            $history = self::history($vault);
            if (!$history) $html .= '<p>Aucune version affichable. Activez « Consulter l’historique » ou effectuez un déploiement via CodeGraft.</p>';
            foreach ($history as $release) {
                $id = (string)($release['id'] ?? '');
                if (!preg_match('/^[a-f0-9]{32}$/D', $id)) continue;
                $html .= '<article class="cg-release"><header><strong>' . $e($release['summary'] ?? 'Version') . '</strong>'
                    . '<small>' . $e($release['created_at'] ?? '') . ' · ' . $e($release['status'] ?? '') . '</small>'
                    . '<code>' . $e($id) . '</code></header><ul>';
                foreach (array_slice($release['paths'] ?? [], 0, 12) as $path) {
                    if (!is_string($path)) continue;
                    $link = $postUrl . '?release=' . rawurlencode($id) . '&path=' . rawurlencode($path) . '#cg-diff';
                    $deleted = in_array($path, $release['deleted_paths'] ?? [], true);
                    $html .= '<li>' . ($deleted ? '<strong>Supprimé</strong> ' : '')
                        . '<code>' . $e($path) . '</code> <a href="' . $e($link) . '">Comparer</a></li>';
                }
                $html .= '</ul>';
                if (($release['status'] ?? null) === 'deployed') {
                    $html .= '<form method="post" action="' . $e($postUrl) . '">' . $csrfField
                        . '<input type="hidden" name="action" value="cc_rollback"><input type="hidden" name="release_id" value="' . $e($id) . '">'
                        . '<label>Confirmation <input type="text" name="confirmation" placeholder="RESTAURER" required autocomplete="off"></label>'
                        . '<label>Mot de passe propriétaire <input type="password" name="owner_password" required autocomplete="current-password"></label>'
                        . '<button type="submit">Restaurer cette version</button></form>';
                }
                $html .= '</article>';
            }
        } catch (Throwable $ex) {
            error_log('CodeGraft control center history: ' . $ex->getMessage());
            $html .= '<p>Historique momentanément indisponible.</p>';
        }
        $html .= '</section><section id="cg-diff"><h3>Comparaison des fichiers</h3>';
        $id = $_GET['release'] ?? null;
        $path = $_GET['path'] ?? null;
        if (is_string($id) && is_string($path)) {
            try { $html .= self::diff($vault, $id, $path); }
            catch (Throwable $error) { $html .= '<p>Comparaison refusée ou indisponible.</p>'; }
        } else $html .= '<p>Sélectionnez « Comparer » sur une version pour afficher les changements.</p>';
        $html .= '</section></div>';
        // Never keep a second, manually curated list of framework tools.
        // Both this page and the OAuth MCP endpoint use FrameworkMcp::frameworkDefinitions().
        $core = Mcp::definitions();
        $framework = class_exists(\CGF\FrameworkMcp::class)
            ? \CGF\FrameworkMcp::frameworkDefinitions() : [];
        $app = self::appTools($vault);
        $html .= '<div lt-panel="tools" class="cg-cc-panel"><section id="cg-tools"><h3>Catalogue MCP</h3>'
            . '<p>Catalogue du code PHP actuellement chargé. Les permissions sont contrôlées à chaque appel MCP, indépendamment de cet affichage.</p>';
        $groups = [
            ['title'=>'Moteur CodeGraft', 'tools'=>$core, 'category'=>'core'],
            ['title'=>'Framework PHP', 'tools'=>$framework, 'category'=>'framework'],
        ];
        foreach ($groups as $group) {
            $html .= '<h4>' . $e($group['title']) . ' (' . count($group['tools']) . ')</h4>'
                . '<table><thead><tr><th>Outil</th><th>Mode</th><th>Scopes OAuth requis</th><th>Description</th></tr></thead><tbody>';
            foreach ($group['tools'] as $tool) {
                $name = (string)($tool['name'] ?? '');
                $isWrite = $group['category'] === 'framework'
                    ? $name === 'cg_guarded_deploy'
                    : in_array($name, [
                        'cg_prepare_patch','cg_prepare_diff','cg_prepare_delete',
                        'cg_validate_patch','cg_deploy_patch','cg_rollback_patch','cg_command_run'
                    ], true);
                $scope = $group['category'] === 'framework'
                    ? ($isWrite ? 'codegraft:write + app:read (propriétaire)'
                        : 'app:read (propriétaire)')
                    : ($isWrite ? 'codegraft:write' : 'codegraft:read');
                $html .= '<tr><td><code>' . $e($name) . '</code></td>'
                    . '<td>' . ($isWrite ? 'écriture / contrôle' : 'lecture / contrôle') . '</td>'
                    . '<td><code>' . $e($scope) . '</code></td>'
                    . '<td>' . $e($tool['description'] ?? '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        if ($app['error'] !== null) {
            $html .= '<p class="cg-cc-note">' . $e($app['error']) . '</p>';
        } else {
            $html .= '<h4>Actions natives app_* (' . count($app['tools']) . ')</h4>'
                . '<table><thead><tr><th>Outil</th><th>Mode</th><th>Scopes OAuth requis</th><th>Contrat HTTP</th></tr></thead><tbody>';
            foreach ($app['tools'] as $tool) {
                $mode = $tool['mode'] === 'write' ? 'écriture' : 'lecture';
                $scope = $tool['mode'] === 'write' ? 'app:write (propriétaire)' : 'app:read (propriétaire)';
                $html .= '<tr><td><code>' . $e($tool['tool']) . '</code><br><small>'
                    . $e($tool['description']) . '</small></td><td>' . $e($mode)
                    . '</td><td><code>' . $e($scope) . '</code></td>'
                    . '<td><code>' . $e($tool['http']) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</section></div>';
        $html .= '<div lt-panel="commands" class="cg-cc-panel"><section id="cg-commands"><h3>Commandes techniques</h3><p>Activation dans Paramètres. Lancement via MCP seulement.</p><table><thead><tr><th>Commande</th><th>État</th><th>Risque</th></tr></thead><tbody>';
        foreach (CommandExecutor::COMMANDS as $id => $spec) {
            $on = !empty($policy['enabled']) && !empty($policy['permissions']['execute']) && !empty($policy['command_permissions'][$id]);
            $html .= '<tr><td><code>' . $e($id) . '</code><br><small>' . $e($spec['label']) . '</small></td><td>' . ($on ? 'Autorisée' : 'Désactivée') . '</td><td>' . ($spec['sensitive'] ? 'Confirmation' : 'Contrôlée') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        try { $last = (new CommandExecutor($vault))->status()['last']; }
        catch (Throwable $ignored) { $last = null; }
        if (is_array($last)) $html .= '<p>Dernière exécution : <code>' . $e($last['id'] ?? '') . '</code> · ' . (!empty($last['success']) ? 'OK' : 'Échec') . ' · ' . $e($last['at'] ?? '') . '</p>';
        $html .= '</section></div><div lt-panel="audit" class="cg-cc-panel"><section id="cg-log"><h3>Journal des appels MCP</h3><p>Conserve seulement date, nom d’outil, canal et résultat. Aucun argument, code source, identifiant de jeton ou secret.</p>';
        $logs = AuditLog::recent($vault);
        if (!$logs) $html .= '<p>Aucun appel enregistré depuis l’installation de ce journal.</p>';
        else {
            $html .= '<table><thead><tr><th>Date</th><th>Outil</th><th>Canal</th><th>Résultat</th></tr></thead><tbody>';
            foreach ($logs as $event) $html .= '<tr><td>' . $e($event['at']) . '</td><td><code>' . $e($event['tool']) . '</code></td><td>' . $e($event['channel']) . '</td><td>' . ($event['ok'] ? 'OK' : 'Erreur') . '</td></tr>';
            $html .= '</tbody></table>';
        }
        $html .= '</section></div><div lt-panel="oauth" class="cg-cc-panel"><section id="cg-clients"><h3>Connexions OAuth</h3>';
        try {
            $clients = self::clients($vault);
            if (!$clients) $html .= '<p>Aucun client OAuth enregistré.</p>';
            else foreach ($clients as $client) {
                $html .= '<article class="cg-client"><div><strong>' . $e($client['redirect_uri']) . '</strong><small>'
                    . $e(date('Y-m-d H:i', (int)$client['created_at'])) . ' · ' . (int)$client['active_tokens'] . ' jeton(s) actif(s)</small></div>'
                    . '<form method="post" action="' . $e($postUrl) . '">' . $csrfField . '<input type="hidden" name="action" value="cc_revoke_client">'
                    . '<input type="hidden" name="client_id" value="' . $e($client['client_id']) . '">'
                    . '<input type="password" name="owner_password" required placeholder="Mot de passe propriétaire" autocomplete="current-password">'
                    . '<button type="submit">Révoquer</button></form></article>';
            }
        } catch (Throwable $ex) {
            error_log('CodeGraft OAuth client listing failed: ' . $ex->getMessage());
            $html .= '<p>Liste des connexions temporairement inaccessible.</p>';
        }
        $html .= '</section></div><div lt-panel="settings" class="cg-cc-panel"><section id="cg-security"><h3>Permissions et sécurité</h3>'
            . '<p>Les autorisations sont gérées dans le panneau CodeGraft ci-dessous. '
            . 'La restauration exige la permission <code>rollback</code>, une confirmation, le mot de passe propriétaire et des empreintes SHA-256 inchangées. '
            . 'Elle ne restaure jamais les migrations SQL ni les modifications effectuées par FileZilla/GitHub.</p>'
            . '<p>État du moteur : <strong>' . (!empty($policy['enabled']) ? 'activé' : 'suspendu') . '</strong></p></section>'
            . '<div class="cg-embedded-settings">' . $settingsHtml . '</div></div></section>';
        return $html;
    }

    private static function css(): string
    {
        return <<<'CSS'
.cg-cc{max-width:1420px;margin:0 auto;padding:25px;font:14px/1.55 system-ui;color:#214632}
.cg-cc-intro{padding:8px 4px 14px}.cg-cc-eyebrow{font-size:11px;font-weight:850;letter-spacing:.14em;color:#51835d}.cg-cc-intro h2{margin:7px 0;font-size:clamp(30px,4vw,48px)}
.cg-cc [lt-panel]{min-width:0}.cg-cc [lt-panel][hidden]{display:none!important}
.cg-cc .cg-embedded-settings .cg-panel{padding:0;margin:0;max-width:none}
.cg-cc .cg-embedded-settings .cg-top,.cg-cc .cg-embedded-settings .cg-footer{display:none}
.cg-cc .cg-embedded-settings .cg-panel .cg-columns{grid-template-columns:minmax(0,1fr) 285px}
.cg-cc .cg-embedded-settings .cg-panel .cg-card{border:1px solid #dce6dc;box-shadow:none}
.cg-cc .cg-embedded-settings .cg-panel .cg-card h2{font-size:22px}
@media(max-width:940px){.cg-cc .cg-embedded-settings .cg-panel .cg-columns{grid-template-columns:1fr}}
.cg-cc > .cg-cc-panel > section{margin:18px 0;padding:26px;background:white;border:1px solid #dce6dc;border-radius:16px}
.cg-cc h2{font:normal 38px Georgia,serif;margin:10px 0}
.cg-cc h3{font:normal 26px Georgia,serif;margin:0 0 15px}
.cg-cc h4{font-size:17px}.cg-cc p{color:#647d6c}.cg-cc-nav{display:flex;gap:10px;flex-wrap:wrap}
.cg-cc-nav{position:sticky;top:0;z-index:3;padding:10px 0;background:#f6f8f3}
.cg-cc-nav a{display:inline-flex;align-items:center;padding:10px 15px;border-radius:9px;background:#edf4ed;color:#214632;font-weight:700;text-decoration:none;border:1px solid #d8e8da}
.cg-cc-nav a[aria-selected="true"]{background:#1c4833;color:white;border-color:#1c4833}
.cg-cc table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}
.cg-cc td,.cg-cc th{padding:10px 12px;border-bottom:1px solid #e1e9e1;text-align:left;vertical-align:top}
.cg-cc small{display:block;color:#678571}.cg-cc code{overflow-wrap:anywhere}
.cg-cc a{color:#1e6d43}.cg-cc .cg-cc-nav a[aria-selected="true"]{color:white}.cg-release,.cg-client{border:1px solid #e2ebe1;padding:14px;border-radius:10px;margin:12px 0}
.cg-release header{background:none;color:#214632;padding:0;font-size:14px;display:flex;gap:12px;flex-wrap:wrap}
.cg-release ul{columns:2}.cg-cc form{display:flex;flex-wrap:wrap;align-items:end;gap:10px;margin:10px 0}
.cg-cc label{display:grid;gap:4px}.cg-cc input{border:1px solid #cbdacc;padding:9px;border-radius:7px}
.cg-cc button{border:0;border-radius:8px;padding:10px 14px;background:#8e382c;color:white;font-weight:700;cursor:pointer}
.cg-cc-note{background:#f2f6e8;padding:10px 14px;border-radius:8px}
.cg-diff{max-height:450px;overflow:auto;background:#152820;color:#dcebdd;padding:20px;font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere}
.cg-diff span{display:block}.cg-diff .removed{background:#592c2f;color:#ffdcdf}.cg-diff .added{background:#244a36;color:#d0ffdf}
@media(max-width:650px){.cg-cc{padding:10px}.cg-cc section{padding:14px}.cg-release ul{columns:1}}
CSS;
    }
}
