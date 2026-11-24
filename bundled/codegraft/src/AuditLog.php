<?php
declare(strict_types=1);
namespace CodeGraft;

/** Bounded metadata-only MCP audit. Never records tool arguments, tokens or source code. */
final class AuditLog
{
    private static function filename(Vault $vault): string
    {
        return $vault->directory . '/mcp-audit.jsonl';
    }

    public static function record(Vault $vault, string $tool, bool $ok, string $channel): void
    {
        if (!preg_match('/^(cg|app)_[a-z0-9_]{1,80}$/D', $tool)) return;
        $name = self::filename($vault);
        if (is_link($name)) return;
        try {
            $handle = @fopen($name, 'c+');
            if ($handle === false) return;
            try {
                if (!flock($handle, LOCK_EX)) return;
                @chmod($name, 0600);
                $stat = fstat($handle);
                if (($stat['size'] ?? 0) > 1048576) {
                    // Keep a bounded tail; no background cleaner required.
                    fseek($handle, -131072, SEEK_END);
                    $tail = (string)stream_get_contents($handle);
                    $lineStart = strpos($tail, "\n");
                    $tail = $lineStart === false ? '' : substr($tail, $lineStart + 1);
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, $tail);
                }
                fseek($handle, 0, SEEK_END);
                fwrite($handle, json_encode([
                    'at' => gmdate('c'), 'tool' => $tool, 'ok' => $ok,
                    'channel' => $channel === 'oauth' ? 'oauth' : 'legacy',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable) {
            // Auditing must not interrupt business requests. The UI reports unavailable logs.
        }
    }

    public static function recent(Vault $vault, int $limit = 40): array
    {
        $name = self::filename($vault);
        if (is_link($name) || !is_file($name)) return [];
        $size = filesize($name);
        if ($size === false) return [];
        $handle = @fopen($name, 'rb');
        if ($handle === false) return [];
        try {
            $offset = max(0, $size - 131072);
            fseek($handle, $offset);
            if ($offset > 0) fgets($handle);
            $bytes = (string)stream_get_contents($handle, 131072);
        } finally { fclose($handle); }
        $result = [];
        foreach (explode("\n", $bytes) as $line) {
            if ($line === '' || strlen($line) > 1000) continue;
            $entry = json_decode($line, true);
            if (!is_array($entry) || !is_string($entry['tool'] ?? null)) continue;
            $result[] = [
                'at' => (string)($entry['at'] ?? ''),
                'tool' => (string)$entry['tool'],
                'ok' => ($entry['ok'] ?? false) === true,
                'channel' => (string)($entry['channel'] ?? ''),
            ];
        }
        return array_slice(array_reverse($result), 0, max(1, min(100, $limit)));
    }
}
