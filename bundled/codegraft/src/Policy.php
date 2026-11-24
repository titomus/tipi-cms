<?php
declare(strict_types=1);

namespace CodeGraft;

use InvalidArgumentException;

/**
 * Live policy snapshot: no tool schema changes when an operator toggles rights.
 * Paths are relative to an explicitly configured project root.
 */
final class Policy
{
    public const OPERATIONS = [
        'list' => 'Lister les fichiers',
        'search' => 'Rechercher du code',
        'read' => 'Lire les fichiers',
        'prepare' => 'Préparer un patch',
        'delete' => 'Supprimer des fichiers (désactivé par défaut)',
        'validate' => 'Valider un patch',
        'deploy' => 'Déployer un patch',
        'rollback' => 'Restaurer une version',
        'history' => 'Consulter l’historique',
        'execute' => 'Exécuter les commandes autorisées (désactivé par défaut)',
    ];

    public const BLOCKED_PARTS = [
        '.git', '.svn', '.hg', 'vendor', 'node_modules',
        'wp-admin', 'wp-includes', 'wp-content/uploads',
        'codegraft', '.codegraft', '.codegraft-vault',
        '.env', '.htpasswd', '.ssh', 'storage', 'cache',
        '.github', 'wp-config.php', 'config.php', 'composer.lock',
        'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
        '.htaccess', 'web.config',
        // Framework-specific permanent protection for core and migrations.
        'src', 'migrations', 'bin', 'bootstrap.php',
        'public/index.php', 'public/install.php',
        'public/codegraft', 'public/business-mcp', 'public/.well-known',
    ];

    public static function defaults(string $adapter = 'generic'): array
    {
        return [
            'version' => 1,
            'adapter' => $adapter,
            'enabled' => false,
            'permissions' => array_fill_keys(array_keys(self::OPERATIONS), false),
            'command_permissions' => array_fill_keys(array_keys(CommandExecutor::COMMANDS), false),
            'read_dirs' => $adapter === 'wordpress' ? ['wp-content/themes'] : ['@project'],
            'write_dirs' => [],
            'extra_denied' => [],
            'max_files_per_patch' => 12,
            'max_file_bytes' => 150000,
            'max_patch_bytes' => 1200000,
            'max_read_results' => 80,
            'updated_at' => gmdate('c'),
        ];
    }

    /** Normalize owner-authored configuration; never let HTTP request override the vault path. */
    public static function normalize(array $input, string $adapter): array
    {
        $base = self::defaults($adapter);
        $base['enabled'] = ($input['enabled'] ?? false) === true;
        foreach (self::OPERATIONS as $key => $_label) {
            $base['permissions'][$key] = ($input['permissions'][$key] ?? false) === true;
        }
        foreach (CommandExecutor::COMMANDS as $id => $_command) {
            $base['command_permissions'][$id] = ($input['command_permissions'][$id] ?? false) === true;
        }
        foreach (['read_dirs', 'write_dirs', 'extra_denied'] as $key) {
            $dirs = $input[$key] ?? [];
            if (!is_array($dirs) || count($dirs) > 30) {
                throw new InvalidArgumentException('Invalid path rules: ' . $key);
            }
            $normalized = [];
            foreach ($dirs as $dir) {
                if (!is_string($dir) || !self::isRelative($dir)) {
                    throw new InvalidArgumentException('Unsafe directory rule: ' . $key);
                }
                $dir = trim($dir, '/');
                if ($dir === '') {
                    throw new InvalidArgumentException('Explicit directory required.');
                }
                $normalized[$dir] = true;
            }
            $base[$key] = array_keys($normalized);
        }
        if ($base['enabled'] && $base['permissions']['deploy'] && $base['write_dirs'] === []) {
            throw new InvalidArgumentException('Authorize write directories before enabling deploy.');
        }
        return $base;
    }

    public static function check(array $policy, string $operation): void
    {
        if (!array_key_exists($operation, self::OPERATIONS)) {
            throw new InvalidArgumentException('Unknown operation.');
        }
        if (($policy['enabled'] ?? false) !== true
            || ($policy['permissions'][$operation] ?? false) !== true) {
            throw new \RuntimeException('CodeGraft policy denies: ' . $operation);
        }
    }

    public static function path(array $policy, string $relative, bool $write = false): void
    {
        if (!self::isRelative($relative)) {
            throw new InvalidArgumentException('Invalid relative path.');
        }
        $path = trim($relative, '/');
        $lower = strtolower($path);
        foreach (explode('/', $lower) as $segment) {
            if ($segment === '' || $segment[0] === '.' || $segment === 'config.php'
                || $segment === 'wp-config.php' || $segment === 'composer.lock') {
                throw new InvalidArgumentException('Protected file or hidden directory.');
            }
        }
        foreach (self::BLOCKED_PARTS as $blocked) {
            if (self::under($lower, strtolower($blocked))
                || str_contains('/' . $lower . '/', '/' . strtolower($blocked) . '/')) {
                throw new InvalidArgumentException('Protected location: ' . $blocked);
            }
        }
        foreach ($policy['extra_denied'] ?? [] as $blocked) {
            if (self::under($lower, strtolower((string) $blocked))) {
                throw new InvalidArgumentException('Denied by project policy.');
            }
        }
        $allowed = $write ? ($policy['write_dirs'] ?? []) : ($policy['read_dirs'] ?? []);
        $permitted = false;
        foreach ($allowed as $prefix) {
            if ($prefix === '@project' || self::under($path, (string) $prefix)) {
                $permitted = true;
                break;
            }
        }
        if (!$permitted) {
            throw new InvalidArgumentException('Path not allowed by project policy.');
        }
        // Public assets may be edited, but no PHP entrypoint may be created under public/.
        if ($write && self::under(strtolower($path), 'public')
            && preg_match('~\.php$~iD', $path) === 1) {
            throw new InvalidArgumentException('Public PHP entrypoints are protected.');
        }
        // File-type validation is framework-neutral; directory rights remain configurable.
        // Latte shares the SHA-256, validation, deployment and rollback lifecycle.
        if ($write && preg_match('~\.(?:php|css|js|json|html|htm|md|txt|twig|latte)$~iD', $path) !== 1) {
            throw new InvalidArgumentException('Extension is not write-enabled.');
        }
    }

    public static function isRelative(string $path): bool
    {
        if ($path === '' || strlen($path) > 300 || str_contains($path, "\\")
            || str_contains($path, "\0") || str_starts_with($path, '/')
            || preg_match('~(^|/)\.{1,2}(/|$)~', $path)
            || preg_match('~[\x00-\x1f\x7f]~', $path)) {
            return false;
        }
        return preg_match('~^[a-zA-Z0-9_./@+ -]+$~D', $path) === 1;
    }

    public static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }
}
