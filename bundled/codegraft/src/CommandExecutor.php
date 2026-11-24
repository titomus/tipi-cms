<?php
declare(strict_types=1);

namespace CodeGraft;

use InvalidArgumentException;
use RuntimeException;

/**
 * Fixed-command runner. No shell, no caller-supplied executable or working directory.
 * Commands are disabled until the owner enables both execute and the individual ID.
 */
final class CommandExecutor
{
    public const COMMANDS = [
        'vite.build' => ['label' => 'Compiler les assets Vite', 'seconds' => 70, 'sensitive' => false],
        'php.lint' => ['label' => 'Vérifier la syntaxe PHP', 'seconds' => 15, 'sensitive' => false],
        'tests.run' => ['label' => 'Exécuter les tests', 'seconds' => 45, 'sensitive' => false],
        'db.migrate' => ['label' => 'Exécuter les migrations', 'seconds' => 45, 'sensitive' => true],
        'deps.install' => ['label' => 'Installer les dépendances', 'seconds' => 100, 'sensitive' => true],
    ];

    public function __construct(private readonly Vault $vault) {}

    public function catalog(): array
    {
        $policy = $this->vault->policy();
        Policy::check($policy, 'read');
        $items = [];
        foreach (self::COMMANDS as $id => $spec) {
            $items[] = [
                'id' => $id, 'label' => $spec['label'],
                'enabled' => ($policy['permissions']['execute'] ?? false) === true
                    && ($policy['command_permissions'][$id] ?? false) === true,
                'sensitive' => $spec['sensitive'],
            ];
        }
        return ['commands' => $items, 'note' => 'Only approved fixed commands; no arbitrary shell.'];
    }

    public function status(): array
    {
        Policy::check($this->vault->policy(), 'read');
        $state = $this->vault->json('commands.json', []);
        return ['last' => $state['last'] ?? null];
    }

    public function run(string $id, string $variant = '', string $path = '', bool $confirmed = false): array
    {
        if (!isset(self::COMMANDS[$id])) throw new InvalidArgumentException('Unknown command.');
        $policy = $this->vault->policy();
        Policy::check($policy, 'execute');
        if (($policy['command_permissions'][$id] ?? false) !== true) {
            throw new RuntimeException('Command not enabled by owner.');
        }
        if ((($id === 'db.migrate' && $variant === 'apply') || $id === 'deps.install') && !$confirmed) {
            throw new InvalidArgumentException('Sensitive command needs explicit confirmation.');
        }
        [$argv, $required] = $this->resolve($id, $variant, $path, $policy);
        foreach ($required as $file) $this->requireRegular($file);
        $root = $this->vault->root;
        $lockFile = $this->vault->directory . '/.command.lock';
        if (is_link($lockFile)) throw new RuntimeException('Unsafe command lock.');
        $lock = fopen($lockFile, 'c');
        if ($lock === false) throw new RuntimeException('Unable to lock commands.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another command is already running.');
            // Recheck policy after obtaining the lock: changes may have occurred while waiting.
            $current = $this->vault->policy();
            Policy::check($current, 'execute');
            if (($current['command_permissions'][$id] ?? false) !== true) {
                throw new RuntimeException('Command permission revoked.');
            }
            $start = microtime(true);
            $result = $this->execute($argv, (int) self::COMMANDS[$id]['seconds']);
            $record = [
                'id' => $id, 'variant' => $variant, 'at' => gmdate('c'),
                'success' => $result['exit_code'] === 0 && !$result['timed_out'],
                'exit_code' => $result['exit_code'], 'timed_out' => $result['timed_out'],
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
            $this->vault->put('commands.json', ['last' => $record]);
            return $record + ['output' => $this->scrub($result['output'])];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function resolve(string $id, string $variant, string $path, array $policy): array
    {
        if ($id === 'php.lint') {
            if ($variant !== '' || !str_ends_with(strtolower($path), '.php')) {
                throw new InvalidArgumentException('PHP lint requires a .php path and no variant.');
            }
            (new Workspace($this->vault))->readFiles([$path]);
            $this->requireRegular($path);
            return [['php', '-l', $path], [$path]];
        }
        if ($path !== '') throw new InvalidArgumentException('Path is only accepted for PHP lint.');
        if ($id === 'vite.build' && $variant === '') {
            return [['npm', 'run', 'build'], ['package.json']];
        }
        if ($id === 'tests.run' && $variant === '') {
            foreach (['tests/run.php', 'tests/smoke.php'] as $candidate) {
                if ($this->isRegular($candidate)) return [['php', $candidate], [$candidate]];
            }
            throw new RuntimeException('No approved test entrypoint found.');
        }
        if ($id === 'db.migrate' && $variant === 'status') {
            return [['php', 'bin/migrate.php', '--status'], ['bin/migrate.php']];
        }
        if ($id === 'db.migrate' && $variant === 'apply') {
            return [['php', 'bin/migrate.php'], ['bin/migrate.php']];
        }
        if ($id === 'deps.install' && $variant === 'composer') {
            return [['composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist'], ['composer.json', 'composer.lock']];
        }
        if ($id === 'deps.install' && $variant === 'npm') {
            return [['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund'], ['package.json', 'package-lock.json']];
        }
        throw new InvalidArgumentException('Unsupported command variant.');
    }

    private function isRegular(string $relative): bool
    {
        if (!Policy::isRelative($relative)) return false;
        $part = $this->vault->root;
        foreach (explode('/', $relative) as $segment) {
            $part .= '/' . $segment;
            if (is_link($part)) return false;
        }
        $real = realpath($part);
        return $real !== false && Policy::under($real, $this->vault->root)
            && is_file($real) && !is_link($real);
    }

    private function requireRegular(string $relative): void
    {
        if (!$this->isRegular($relative)) throw new RuntimeException('Required project file missing or unsafe.');
    }

    private function execute(array $argv, int $seconds): array
    {
        if (!function_exists('proc_open')) throw new RuntimeException('Process execution disabled on this hosting.');
        // Array argv instructs PHP to bypass shell parsing. No arbitrary environment variables inherited.
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $this->vault->directory,
            'TMPDIR' => sys_get_temp_dir(), 'LANG' => 'C.UTF-8', 'CI' => '1'];
        $pipes = [];
        $proc = @proc_open($argv, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes, $this->vault->root, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('Unable to start process.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + $seconds;
        $timedOut = false;
        $exitCode = null;
        try {
            while (true) {
                foreach ([1,2] as $n) {
                    $chunk = stream_get_contents($pipes[$n], 2048);
                    if (is_string($chunk) && strlen($out) < 8192) $out .= substr($chunk, 0, 8192 - strlen($out));
                }
                $state = proc_get_status($proc);
                if (!$state['running']) {
                    $exitCode = $state['exitcode'] >= 0 ? $state['exitcode'] : null;
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($proc, 15);
                    usleep(100000);
                    $after = proc_get_status($proc);
                    if ($after['running']) proc_terminate($proc, 9);
                    break;
                }
                usleep(100000);
            }
            foreach ([1,2] as $n) {
                $chunk = stream_get_contents($pipes[$n]);
                if (is_string($chunk) && strlen($out) < 8192) $out .= substr($chunk, 0, 8192 - strlen($out));
            }
        } finally {
            fclose($pipes[1]); fclose($pipes[2]);
            $closed = proc_close($proc);
            if ($exitCode === null && !$timedOut && $closed >= 0) $exitCode = $closed;
        }
        return ['exit_code' => $exitCode, 'timed_out' => $timedOut, 'output' => $out];
    }

    private function scrub(string $out): string
    {
        $out = str_replace([$this->vault->root, $this->vault->directory], '[private]', $out);
        $out = preg_replace('/(token|secret|password|authorization|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[redacted]', $out) ?? '';
        return substr($out, 0, 8192);
    }
}
