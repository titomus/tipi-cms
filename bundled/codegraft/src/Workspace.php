<?php
declare(strict_types=1);

namespace CodeGraft;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Multi-file, SHA-locked code workspace. No framework dependencies.
 * Safe defaults are READ/WRITE DENIED; only site owner can modify policy.
 *
 * This is not a transactional filesystem: on process death, recover() via CLI
 * is required. Backups are saved BEFORE the first live file is replaced.
 */
final class Workspace
{
    public function __construct(private readonly Vault $vault)
    {
    }

    public function policy(): array
    {
        return $this->vault->policy();
    }

    public function listFiles(int $limit = 80): array
    {
        $policy = $this->checked('list');
        $result = [];
        foreach ($this->walk($policy) as $path) {
            $full = $this->resolve($path, $policy);
            $result[] = [
                'path' => $path,
                'bytes' => filesize($full),
                'sha256' => hash_file('sha256', $full),
            ];
            if (count($result) >= max(1, min(200, $limit))) break;
        }
        return ['files' => $result, 'limit' => min(200, max(1, $limit))];
    }

    /**
     * Bounded structural overview for authorized code only.
     * Reuses BOTH list/read permissions; does not expose source lines.
     */
    public function projectMap(string $prefix = '', int $limit = 80): array
    {
        $policy = $this->checked('list');
        $this->checked('read');
        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            Policy::path($policy, $prefix);
        }
        $limit = max(1, min(100, $limit));
        $files = [];
        $scanned = 0;
        foreach ($this->walk($policy) as $path) {
            if ($prefix !== '' && !Policy::under($path, $prefix)) continue;
            if (count($files) >= $limit) break;
            $full = $this->resolve($path, $policy);
            $bytes = filesize($full);
            if ($bytes === false || $bytes > (int)$policy['max_file_bytes']) continue;
            $body = file_get_contents($full);
            if (!is_string($body) || str_contains($body, "\0")) continue;
            $scanned++;
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            $entry = ['path' => $path, 'type' => $ext, 'bytes' => $bytes];
            if ($ext === 'php') {
                $symbols = [];
                $tokens = token_get_all($body);
                $types = [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION];
                if (defined('T_ENUM')) $types[] = T_ENUM;
                foreach ($tokens as $i => $token) {
                    if (!is_array($token) || !in_array($token[0], $types, true)) continue;
                    for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                        $next = $tokens[$j];
                        if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) continue;
                        if (is_array($next) && $next[0] === T_STRING) {
                            $symbols[] = $next[1];
                        }
                        break;
                    }
                    if (count($symbols) >= 12) break;
                }
                $entry['symbols'] = array_values(array_unique($symbols));
                $signals = [];
                foreach (['wordpress_hooks' => '/\b(?:add_action|add_filter|add_shortcode)\s*\(/i',
                          'wordpress_rest' => '/\bregister_rest_route\s*\(/i',
                          'routing' => '/(?:Flight::route|Route::(?:get|post|put|delete)|->route)\s*\(/i'] as $tag => $pattern) {
                    if (preg_match($pattern, $body) === 1) $signals[] = $tag;
                }
                if ($signals !== []) $entry['signals'] = $signals;
            }
            $files[] = $entry;
        }
        return ['prefix' => $prefix, 'scanned' => $scanned, 'limit' => $limit,
            'files' => $files, 'truncated' => count($files) === $limit];
    }

    public function search(string $needle, int $limit = 30): array
    {
        $policy = $this->checked('search');
        if (strlen($needle) < 2 || strlen($needle) > 120 || str_contains($needle, "\0")) {
            throw new InvalidArgumentException('Search requires 2 to 120 text characters.');
        }
        $found = [];
        foreach ($this->walk($policy) as $path) {
            $full = $this->resolve($path, $policy);
            if (filesize($full) > (int) $policy['max_file_bytes']) continue;
            $data = file_get_contents($full);
            if (!is_string($data) || str_contains($data, "\0")) continue;
            $lines = explode("\n", $data);
            foreach ($lines as $idx => $line) {
                if (stripos($line, $needle) === false) continue;
                $found[] = ['path' => $path, 'line' => $idx + 1,
                    'excerpt' => mb_substr(trim($line), 0, 240)];
                if (count($found) >= max(1, min(100, $limit))) break 2;
            }
        }
        return ['matches' => $found, 'query' => $needle];
    }

    public function readFiles(array $paths): array
    {
        $policy = $this->checked('read');
        if (count($paths) < 1 || count($paths) > 20) {
            throw new InvalidArgumentException('Read between 1 and 20 files.');
        }
        $result = [];
        foreach (array_unique($paths) as $path) {
            if (!is_string($path)) throw new InvalidArgumentException('Invalid path.');
            $full = $this->resolve($path, $policy);
            if (!is_file($full)) throw new InvalidArgumentException('Missing file: ' . $path);
            if (filesize($full) > (int) $policy['max_file_bytes']) {
                throw new InvalidArgumentException('File exceeds allowed size: ' . $path);
            }
            $bytes = file_get_contents($full);
            if (!is_string($bytes) || str_contains($bytes, "\0")) {
                throw new RuntimeException('Non-text file cannot be read.');
            }
            $result[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'content' => $bytes];
        }
        return ['files' => $result];
    }

    /**
     * Prepare exact, non-ambiguous text hunks without asking the model to resend
     * whole files. Delegates all SHA, syntax, path and size controls to prepare().
     */
    public function prepareDiff(string $summary, array $files): array
    {
        $policy = $this->checked('prepare');
        if (count($files) < 1 || count($files) > (int)$policy['max_files_per_patch']) {
            throw new InvalidArgumentException('Invalid differential patch file count.');
        }
        $prepared = [];
        $seen = [];
        $totalHunks = 0;
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)
                || !is_string($file['expected_sha256'] ?? null)
                || !is_array($file['hunks'] ?? null)) {
                throw new InvalidArgumentException('Each diff requires path, SHA-256 and hunks.');
            }
            $path = $file['path'];
            if (isset($seen[$path])) throw new InvalidArgumentException('Repeated diff target.');
            $seen[$path] = true;
            $full = $this->resolve($path, $policy, true);
            if (!is_file($full)) throw new InvalidArgumentException('Diff target must exist.');
            if (filesize($full) > (int)$policy['max_file_bytes']) {
                throw new InvalidArgumentException('Diff target exceeds file limit.');
            }
            $body = file_get_contents($full);
            if (!is_string($body) || str_contains($body, "\0")) {
                throw new InvalidArgumentException('Diff target must be text.');
            }
            if (!hash_equals(hash('sha256', $body), $file['expected_sha256'])) {
                throw new RuntimeException('Stale file SHA-256: ' . $path);
            }
            $hunks = $file['hunks'];
            if (count($hunks) < 1 || count($hunks) > 12) {
                throw new InvalidArgumentException('Use between 1 and 12 hunks per file.');
            }
            $updated = $body;
            foreach ($hunks as $hunk) {
                if (!is_array($hunk) || !is_string($hunk['before'] ?? null)
                    || !is_string($hunk['after'] ?? null)
                    || $hunk['before'] === '' || strlen($hunk['before']) > 12000
                    || strlen($hunk['after']) > 20000) {
                    throw new InvalidArgumentException('Invalid or oversized diff hunk.');
                }
                $totalHunks++;
                if ($totalHunks > 48) throw new InvalidArgumentException('Too many diff hunks.');
                if (substr_count($updated, $hunk['before']) !== 1) {
                    throw new InvalidArgumentException('Diff hunk missing or ambiguous: ' . $path);
                }
                $updated = str_replace($hunk['before'], $hunk['after'], $updated);
            }
            $prepared[] = ['path' => $path, 'expected_sha256' => $file['expected_sha256'],
                'content' => $updated];
        }
        return $this->prepare($summary, $prepared);
    }

    public function prepare(string $summary, array $files): array
    {
        $policy = $this->checked('prepare');
        $this->checked('delete');
        if (mb_strlen(trim($summary)) < 8 || mb_strlen($summary) > 160
            || count($files) < 1 || count($files) > (int) $policy['max_files_per_patch']) {
            throw new InvalidArgumentException('Invalid patch summary or file count.');
        }
        $seen = [];
        $items = [];
        $total = 0;
        foreach ($files as $input) {
            if (!is_array($input) || !is_string($input['path'] ?? null)
                || !is_string($input['expected_sha256'] ?? null)
                || !is_string($input['content'] ?? null)) {
                throw new InvalidArgumentException('Each file needs path, expected_sha256, content.');
            }
            $path = $input['path'];
            if (isset($seen[$path])) throw new InvalidArgumentException('Repeated patch target.');
            $seen[$path] = true;
            $full = $this->resolve($path, $policy, true);
            $previous = is_file($full) ? file_get_contents($full) : null;
            if ($previous === false) throw new RuntimeException('Cannot read previous file.');
            $expected = $previous === null ? '' : hash('sha256', $previous);
            if (!hash_equals($expected, $input['expected_sha256'])) {
                throw new RuntimeException('Stale file SHA-256: ' . $path);
            }
            $body = $input['content'];
            $length = strlen($body);
            $total += $length;
            if ($length === 0 || $length > (int)$policy['max_file_bytes']
                || $total > (int)$policy['max_patch_bytes'] || str_contains($body, "\0")) {
                throw new InvalidArgumentException('Patch body too large or binary.');
            }
            $this->syntax($path, $body);
            if ($previous !== null && hash_equals($expected, hash('sha256', $body))) {
                throw new InvalidArgumentException('No changes in ' . $path);
            }
            $items[] = [
                'path' => $path,
                'before_sha256' => $expected,
                'after_sha256' => hash('sha256', $body),
                'content' => $body,
            ];
        }
        $id = bin2hex(random_bytes(16));
        $draft = ['id' => $id, 'status' => 'prepared', 'summary' => trim($summary),
            'created_at' => gmdate('c'), 'expires_at' => time() + 86400, 'files' => $items];
        $this->vault->put('drafts/' . $id . '.json', $draft);
        return ['id' => $id, 'status' => 'prepared', 'files' => array_map(
            static fn (array $f): array => [
                'path' => $f['path'], 'before_sha256' => $f['before_sha256'],
                'after_sha256' => $f['after_sha256'],
            ], $items),
            'warning' => 'Live PHP modifications can execute code. Validate and confirm deployment explicitly.',
        ];
    }

    /**
     * Prepare explicit removal of 1..N existing text files.
     * No empty-file workaround; a deletion is journaled and reversible.
     */
    public function prepareDelete(string $summary, array $files): array
    {
        $policy = $this->checked('prepare');
        if (mb_strlen(trim($summary)) < 8 || mb_strlen($summary) > 160
            || count($files) < 1 || count($files) > (int)$policy['max_files_per_patch']) {
            throw new InvalidArgumentException('Invalid deletion summary or file count.');
        }
        $seen = [];
        $items = [];
        $total = 0;
        foreach ($files as $input) {
            if (!is_array($input) || !is_string($input['path'] ?? null)
                || !is_string($input['expected_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $input['expected_sha256'])) {
                throw new InvalidArgumentException('Deletion requires path and existing SHA-256.');
            }
            $path = $input['path'];
            if (isset($seen[$path])) throw new InvalidArgumentException('Repeated deletion target.');
            $seen[$path] = true;
            $full = $this->resolve($path, $policy, true);
            if (!is_file($full)) throw new InvalidArgumentException('Deletion target does not exist: ' . $path);
            $length = filesize($full);
            if ($length === false || $length > (int)$policy['max_file_bytes']) {
                throw new InvalidArgumentException('Deletion target exceeds file limit.');
            }
            $total += $length;
            if ($total > (int)$policy['max_patch_bytes']) {
                throw new InvalidArgumentException('Deletion backup exceeds patch size limit.');
            }
            $body = file_get_contents($full);
            if (!is_string($body) || str_contains($body, "\0")) {
                throw new InvalidArgumentException('Only text files can be deleted.');
            }
            $sha = hash('sha256', $body);
            if (!hash_equals($sha, $input['expected_sha256'])) {
                throw new RuntimeException('Stale file SHA-256: ' . $path);
            }
            $items[] = ['operation' => 'delete', 'path' => $path,
                'before_sha256' => $sha, 'after_sha256' => ''];
        }
        $id = bin2hex(random_bytes(16));
        $draft = ['id' => $id, 'status' => 'prepared', 'summary' => trim($summary),
            'created_at' => gmdate('c'), 'expires_at' => time() + 86400,
            'files' => $items];
        $this->vault->put('drafts/' . $id . '.json', $draft);
        return ['id' => $id, 'status' => 'prepared',
            'files' => array_map(static fn(array $f): array => [
                'operation' => 'delete', 'path' => $f['path'],
                'before_sha256' => $f['before_sha256'], 'after_sha256' => '',
            ], $items),
            'warning' => 'Deletion requires validation and explicit deployment confirmation. References, routes and runtime use must be checked separately. Rollback restores files only, not SQL.',
        ];
    }

    public function validate(string $id): array
    {
        $policy = $this->checked('validate');
        $draft = $this->draft($id);
        $checks = [];
        foreach ($draft['files'] as $entry) {
            try {
                $full = $this->resolve((string)$entry['path'], $policy, true);
                $now = is_file($full) ? hash_file('sha256', $full) : '';
                if (!hash_equals((string)$entry['before_sha256'], (string)$now)) {
                    throw new RuntimeException('File changed since preparation.');
                }
                if (($entry['operation'] ?? 'replace') === 'delete') {
                    if (!is_file($full)) throw new RuntimeException('Deletion target missing.');
                    $checks[] = ['path' => $entry['path'], 'operation' => 'delete',
                        'valid' => true, 'security' => [],
                        'reason' => 'SHA matched; references and application behavior must be checked separately.'];
                    continue;
                }
                $this->syntax((string)$entry['path'], (string)$entry['content']);
                $before = is_file($full) ? file_get_contents($full) : '';
                if (!is_string($before)) throw new RuntimeException('Cannot read original.');
                $findings = $this->securityFindings((string)$entry['path'],
                    $before, (string)$entry['content']);
                $blocking = in_array('block', array_column($findings, 'severity'), true);
                $checks[] = ['path' => $entry['path'], 'valid' => !$blocking,
                    'security' => $findings,
                    'reason' => $blocking ? 'New eval() is blocked by security preflight.' : null];
            } catch (Throwable $e) {
                $checks[] = ['path' => $entry['path'], 'valid' => false, 'reason' => $e->getMessage()];
            }
        }
        return ['id' => $id, 'valid' => !in_array(false, array_column($checks, 'valid'), true),
            'checks' => $checks, 'warning' => 'PHP syntax validation is not an HTTP or functional test.'];
    }

    public function deploy(string $id, bool $confirmed): array
    {
        if (!$confirmed) throw new InvalidArgumentException('Explicit confirmation required.');
        return $this->vault->locked(function () use ($id): array {
            $policy = $this->checked('deploy');
            $draft = $this->draft($id);
            $backup = [];
            foreach ($draft['files'] as $entry) {
                $full = $this->resolve((string)$entry['path'], $policy, true);
                $original = is_file($full) ? file_get_contents($full) : null;
                if ($original === false) throw new RuntimeException('Cannot snapshot original.');
                $hash = $original === null ? '' : hash('sha256', $original);
                if (!hash_equals((string)$entry['before_sha256'], $hash)) {
                    throw new RuntimeException('SHA-256 conflict at deployment: ' . $entry['path']);
                }
                $delete = ($entry['operation'] ?? 'replace') === 'delete';
                if ($delete) {
                    $this->checked('delete');
                    if ($original === null || strlen($original) > (int)$policy['max_file_bytes']
                        || str_contains($original, "\0")) {
                        throw new RuntimeException('Deletion requires an existing bounded text file: ' . $entry['path']);
                    }
                } else {
                $this->syntax((string)$entry['path'], (string)$entry['content']);
                $findings = $this->securityFindings((string)$entry['path'],
                    $original ?? '', (string)$entry['content']);
                if (in_array('block', array_column($findings, 'severity'), true)) {
                    throw new RuntimeException('Security preflight blocked new eval(): ' . $entry['path']);
                }
                }
                $backup[] = [
                    'path' => $entry['path'],
                    'existed' => $original !== null,
                    'before_sha256' => $hash,
                    'before_base64' => $original === null ? null : base64_encode($original),
                    'operation' => $delete ? 'delete' : 'replace',
                    'after_sha256' => $entry['after_sha256'],
                ];
            }
            $release = ['id' => $id, 'status' => 'deploying',
                'summary' => $draft['summary'], 'created_at' => gmdate('c'), 'files' => $backup];
            $this->vault->put('releases/' . $id . '.json', $release);
            try {
                foreach ($draft['files'] as $entry) {
                    if (($entry['operation'] ?? 'replace') === 'delete') {
                        $target = $this->resolve((string)$entry['path'], $policy, true);
                        $current = is_file($target) ? hash_file('sha256', $target) : false;
                        if (!is_string($current)
                            || !hash_equals((string)$entry['before_sha256'], $current)) {
                            throw new RuntimeException('Deletion SHA-256 changed before unlink: ' . $entry['path']);
                        }
                        if (!unlink($target)) {
                            throw new RuntimeException('Cannot delete file: ' . $entry['path']);
                        }
                        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
                    } else {
                    $this->replace((string)$entry['path'], (string)$entry['content'], $policy);
                    }
                }
            } catch (Throwable $e) {
                $failed = false;
                foreach (array_reverse($backup) as $item) {
                    try {
                        $this->restore($item, $policy);
                    } catch (Throwable) {
                        $failed = true;
                    }
                }
                $release['status'] = $failed ? 'emergency_recovery_required' : 'failed_restored';
                $this->vault->put('releases/' . $id . '.json', $release);
                throw new RuntimeException('Deployment failed; recovery status: ' . $release['status'], 0, $e);
            }
            $release['status'] = 'deployed';
            $release['deployed_at'] = gmdate('c');
            $this->vault->put('releases/' . $id . '.json', $release);
            $draft['status'] = 'deployed';
            $draft['files'] = []; // Do not retain redundant new source after release.
            $this->vault->put('drafts/' . $id . '.json', $draft);
            return ['id' => $id, 'status' => 'deployed',
                'paths' => array_column($backup, 'path'),
                'deleted_paths' => array_column(array_filter($backup,
                    static fn(array $item): bool => ($item['operation'] ?? '') === 'delete'), 'path'),
                'rollback_available' => true];
        });
    }

    public function rollback(string $id, bool $confirmed): array
    {
        if (!$confirmed) throw new InvalidArgumentException('Explicit rollback confirmation required.');
        return $this->vault->locked(function () use ($id): array {
            $policy = $this->checked('rollback');
            $this->assertId($id);
            $release = $this->vault->json('releases/' . $id . '.json');
            if (($release['status'] ?? null) !== 'deployed') {
                throw new InvalidArgumentException('Release not eligible for rollback.');
            }
            foreach ($release['files'] as $item) {
                $full = $this->resolve((string)$item['path'], $policy, true);
                $current = is_file($full) ? hash_file('sha256', $full) : '';
                if (!hash_equals((string)$item['after_sha256'], (string)$current)) {
                    throw new RuntimeException('Rollback refused: newer changes on ' . $item['path']);
                }
            }
            foreach (array_reverse($release['files']) as $item) {
                $this->restore($item, $policy);
            }
            $release['status'] = 'rolled_back';
            $release['rolled_back_at'] = gmdate('c');
            $this->vault->put('releases/' . $id . '.json', $release);
            return ['id' => $id, 'status' => 'rolled_back',
                'paths' => array_column($release['files'], 'path')];
        });
    }

    public function history(int $limit = 20): array
    {
        $this->checked('history');
        $all = glob($this->vault->directory . '/releases/*.json') ?: [];
        rsort($all);
        $rows = [];
        foreach ($all as $name) {
            if (is_link($name)) continue;
            $data = $this->vault->json('releases/' . basename($name));
            $rows[] = [
                'id' => $data['id'] ?? '',
                'status' => $data['status'] ?? '',
                'summary' => $data['summary'] ?? '',
                'created_at' => $data['created_at'] ?? '',
                'paths' => array_column($data['files'] ?? [], 'path'),
                'deleted_paths' => array_column(array_filter($data['files'] ?? [],
                    static fn(array $file): bool => ($file['operation'] ?? '') === 'delete'), 'path'),
            ];
        }
        usort($rows, static fn (array $a, array $b): int =>
            strcmp((string)$b['created_at'], (string)$a['created_at']));
        return ['releases' => array_slice($rows, 0, max(1, min(100, $limit)))];
    }

    /** Last-resort CLI recovery after process termination; not exposed over MCP. */
    public function emergencyRecover(string $id): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
        $this->assertId($id);
        return $this->vault->locked(function () use ($id): array {
            $release = $this->vault->json('releases/' . $id . '.json');
            if (!in_array($release['status'] ?? null, ['deploying','deployed','emergency_recovery_required'], true)) {
                throw new InvalidArgumentException('Not recoverable.');
            }
            $policy = $this->vault->policy();
            foreach (array_reverse($release['files']) as $entry) {
                $this->restore($entry, $policy);
            }
            $release['status'] = 'emergency_rolled_back';
            $this->vault->put('releases/' . $id . '.json', $release);
            return ['id' => $id, 'status' => 'emergency_rolled_back'];
        });
    }

    private function checked(string $permission): array
    {
        $policy = $this->vault->policy();
        Policy::check($policy, $permission);
        return $policy;
    }

    private function draft(string $id): array
    {
        $this->assertId($id);
        $draft = $this->vault->json('drafts/' . $id . '.json');
        if (($draft['status'] ?? null) !== 'prepared' || time() > (int)($draft['expires_at'] ?? 0)) {
            throw new InvalidArgumentException('Draft unavailable or expired.');
        }
        return $draft;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('Invalid ID.');
    }

    private function resolve(string $path, array $policy, bool $write = false): string
    {
        Policy::path($policy, $path, $write);
        $current = $this->vault->root;
        foreach (explode('/', $path) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) throw new InvalidArgumentException('Symlinks cannot be traversed.');
        }
        if (file_exists($current) && !is_file($current)) {
            throw new InvalidArgumentException('Not a regular file.');
        }
        return $current;
    }

    /**
     * Static security preflight, focused on NEW sensitive PHP primitives.
     * This is not a vulnerability scanner or a substitute for functional tests.
     */
    private function securityFindings(string $path, string $before, string $after): array
    {
        if (!str_ends_with(strtolower($path), '.php')) return [];
        $count = static function (string $code): array {
            $tokens = token_get_all($code);
            $counts = [];
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) continue;
                if ($token[0] === T_EVAL) {
                    $counts['dynamic_eval'] = ($counts['dynamic_eval'] ?? 0) + 1;
                    continue;
                }
                if ($token[0] !== T_STRING) continue;
                $name = strtolower($token[1]);
                if (!in_array($name, ['exec', 'shell_exec', 'system', 'passthru',
                    'proc_open', 'popen', 'unserialize'], true)) continue;
                $j = $i + 1;
                while (isset($tokens[$j]) && is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
                if (($tokens[$j] ?? null) !== '(') continue;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
            return $counts;
        };
        $old = $count($before);
        $new = $count($after);
        $findings = [];
        foreach ($new as $rule => $amount) {
            if ($amount <= ($old[$rule] ?? 0)) continue;
            $findings[] = ['rule' => $rule,
                'severity' => $rule === 'dynamic_eval' ? 'block' : 'warning',
                'detail' => 'New potentially sensitive PHP operation; review manually.'];
        }
        return $findings;
    }

    private function syntax(string $path, string $body): void
    {
        if (str_ends_with(strtolower($path), '.php')) {
            try {
                token_get_all($body, TOKEN_PARSE);
            } catch (\ParseError $e) {
                throw new InvalidArgumentException('PHP parse error: ' . $e->getMessage());
            }
        }
    }

    private function replace(string $path, string $body, array $policy): void
    {
        $file = $this->resolve($path, $policy, true);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create target directory.');
        }
        $temp = tempnam($dir, '.cg-stage-');
        if ($temp === false) throw new RuntimeException('Cannot stage file.');
        try {
            if (file_put_contents($temp, $body) !== strlen($body)) {
                throw new RuntimeException('Incomplete staged file.');
            }
            chmod($temp, is_file($file) ? fileperms($file) & 0777 : 0644);
            if (!rename($temp, $file)) throw new RuntimeException('Cannot activate file.');
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }

    private function restore(array $entry, array $policy): void
    {
        $file = $this->resolve((string)$entry['path'], $policy, true);
        if (($entry['existed'] ?? false) === false) {
            if (is_file($file) && !unlink($file)) throw new RuntimeException('Cannot remove new file.');
            return;
        }
        $old = base64_decode((string)($entry['before_base64'] ?? ''), true);
        if ($old === false || !hash_equals((string)$entry['before_sha256'], hash('sha256', $old))) {
            throw new RuntimeException('Backup corrupted.');
        }
        $this->replace((string)$entry['path'], $old, $policy);
    }

    private function walk(array $policy): \Generator
    {
        $visited = 0;
        foreach ($policy['read_dirs'] as $relative) {
            // @project means the project root, excluding CodeGraft's protected paths.
            $start = $relative === '@project' ? $this->vault->root : $this->vault->root . '/' . $relative;
            if (!is_dir($start) || is_link($start)) continue;
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $info) {
                if (++$visited > 3000) return;
                if (!$info->isFile() || $info->isLink()) continue;
                $path = substr($info->getPathname(), strlen($this->vault->root) + 1);
                try {
                    Policy::path($policy, $path);
                    // Check symlink parents, e.g. ../plugins/external symlink directory.
                    $this->resolve($path, $policy);
                } catch (Throwable) {
                    continue;
                }
                yield $path;
            }
        }
    }
}
