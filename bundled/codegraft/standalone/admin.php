<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
use CodeGraft\Admin;
use CodeGraft\ControlCenter;
require_once dirname(__DIR__, 2) . '/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
// Latch tabs do not require dynamic expression evaluation. Keep unsafe-eval blocked.
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self' https://titomus.github.io 'nonce-" . $nonce . "'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('HTTPS required.');
    }
}
session_name('codegraft_admin');
session_set_cookie_params(['secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

$csrf = $_SESSION['cg_csrf'] ??= bin2hex(random_bytes(24));
$record = $codegraft['vault']->json('admin.json');
$passwordHash = $record['password_hash'] ?? null;
if (!is_string($passwordHash) || !str_starts_with($passwordHash, '$2')) {
    http_response_code(503);
    exit('CodeGraft administrator not initialized.');
}
$loggedIn = ($_SESSION['cg_authenticated'] ?? false) === true;
$notice = null;
$token = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!is_string($_POST['_csrf'] ?? null) || !hash_equals($csrf, $_POST['_csrf'])) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
    if (!$loggedIn && ($_POST['action'] ?? '') === 'login') {
        if (($_SESSION['attempts'] ?? 0) >= 10) {
            http_response_code(429);
            exit('Too many attempts in this session.');
        }
        $password = $_POST['password'] ?? '';
        if (is_string($password) && password_verify($password, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['cg_authenticated'] = true;
            $_SESSION['attempts'] = 0;
            $loggedIn = true;
        } else {
            $_SESSION['attempts'] = ($_SESSION['attempts'] ?? 0) + 1;
            $notice = 'Identifiants incorrects.';
        }
    } elseif ($loggedIn && ($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php', true, 303);
        exit;
    } elseif ($loggedIn) {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'cc_rollback') {
                $notice = ControlCenter::rollback(
                    $codegraft['vault'], (string)($_POST['release_id'] ?? ''),
                    (string)($_POST['confirmation'] ?? ''),
                    (string)($_POST['owner_password'] ?? '')
                );
            } elseif ($action === 'cc_revoke_client') {
                $notice = ControlCenter::revokeClient(
                    $codegraft['vault'], (string)($_POST['client_id'] ?? ''),
                    (string)($_POST['owner_password'] ?? '')
                );
            } else {
                $result = Admin::handle($codegraft['vault'], $_POST);
                $notice = $result['message'] ?? null;
                $token = $result['token'] ?? null;
            }
        } catch (Throwable $e) {
            $notice = $e->getMessage();
        }
    }
}
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$style = 'body{margin:0;background:#f6f8f3;color:#183a29;font:14px system-ui,sans-serif}'
    . '.cg-brand{padding:16px max(24px,calc((100vw - 1420px)/2));background:#163b2a;color:white;display:flex;justify-content:space-between;align-items:center;gap:14px;min-height:56px}'
    . '.cg-brand strong{font-size:19px;letter-spacing:-.025em}.cg-brand small{font-size:12px;color:#bbd7c4}'
    . '.cg-login{max-width:420px;margin:90px auto;padding:36px;background:white;border-radius:16px}'
    . '.cg-login h1{font:500 36px Georgia,serif}.cg-login input{display:block;width:100%;box-sizing:border-box;padding:15px;margin:17px 0;border:1px solid #d2ddcf;border-radius:9px}'
    . '.cg-login button{border:0;background:#183d2b;color:white;padding:13px 19px;border-radius:9px;cursor:pointer}'
    . '.cg-logout{margin:0}.cg-logout button{border:1px solid #749583;background:transparent;color:#e3f0e5;padding:9px 13px;border-radius:8px;cursor:pointer}';
echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>CodeGraft · Administration</title><style>' . $style . '</style></head><body><header class="cg-brand"><strong>✳ CodeGraft</strong><small>Administration privée · Atelier MCP</small>';
if (!$loggedIn) {
    echo '</header>';
    echo '<form method="post" class="cg-login"><h1>Administration privée.</h1>'
        . '<p>Mot de passe défini lors de l’installation en ligne de commande.</p>'
        . ($notice ? '<p role="alert">' . $esc($notice) . '</p>' : '')
        . '<input type="hidden" name="action" value="login">'
        . '<input type="hidden" name="_csrf" value="' . $esc($csrf) . '">'
        . '<input type="password" name="password" required autocomplete="current-password" placeholder="Mot de passe administrateur">'
        . '<button type="submit">Se connecter →</button></form>';
} else {
    echo '<form class="cg-logout" method="post"><input type="hidden" name="action" value="logout">'
        . '<input type="hidden" name="_csrf" value="' . $esc($csrf) . '">'
        . '<button type="submit">Se déconnecter</button></form></header>';
    // The mounted endpoint may live at /mcp/ while the standard route is
    // /codegraft/mcp/. Both use the same token, tool list and live policy.
    $mount = $codegraft['vault']->json('endpoint.json', []);
    $mountPath = $mount['path'] ?? null;
    $publicPrefix = rtrim(str_replace('\\', '/', dirname(dirname(
        (string) ($_SERVER['SCRIPT_NAME'] ?? '/codegraft/standalone/admin.php')
    ))), '/');
    $endpointUrls = [];
    if (is_string($mountPath) && preg_match('~^/[a-z][a-z0-9-]{0,39}/$~D', $mountPath)) {
        $endpointUrls['Chemin personnalisé (recommandé)'] = $mountPath;
    }
    $endpointUrls['Chemin propre sans réécriture'] = $publicPrefix . '/mcp/';
    $endpointUrls['URL historique de secours'] = $publicPrefix . '/standalone/mcp.php';
    $settings = Admin::render($codegraft['vault'], 'admin.php?tab=settings',
        '<input type="hidden" name="_csrf" value="' . $esc($csrf) . '">',
        $notice, $token, $endpointUrls);
    $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'versions';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if (in_array($action, ['save_policy','rotate_token','revoke_token'], true)) $tab = 'settings';
        elseif ($action === 'cc_revoke_client') $tab = 'oauth';
    }
    echo ControlCenter::render($codegraft['vault'], 'admin.php',
        '<input type="hidden" name="_csrf" value="' . $esc($csrf) . '">',
        '', $settings, $tab);
}
if ($loggedIn) {
    // Pinned local bundle is preferred; public GitHub Pages is a temporary
    // fallback until the release artifact is copied into this private admin.
    $scriptFile = __DIR__ . '/assets/latch.min.js';
    $source = is_file($scriptFile) && !is_link($scriptFile)
        ? './assets/latch.min.js'
        : 'https://titomus.github.io/latchjs/dist/latch.min.js';
    echo '<script defer src="' . $esc($source) . '"></script>';
    echo '<script nonce="' . $esc($nonce) . '">window.addEventListener("load",function(){if(window.Latch && typeof Latch.start==="function"){Latch.start();}});</script>';
}
echo '</body></html>';
