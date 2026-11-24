<?php
declare(strict_types=1);
namespace CGF;

use PDO;
use RuntimeException;

final class Database {
    private PDO $pdo;
    public function __construct(array $config) {
        $driver = (string)($config['driver'] ?? 'sqlite');
        if ($driver === 'sqlite') {
            $path = (string)($config['path'] ?? '');
            if ($path === '' || !str_starts_with($path, '/')) throw new RuntimeException('SQLite exige un chemin absolu');
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->pdo->exec('PRAGMA busy_timeout=5000');
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        } elseif (in_array($driver,['pgsql','mysql'],true)) {
            $host=(string)($config['host']??'localhost'); $name=(string)($config['name']??'');
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$host) || !preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('Configuration DB invalide');
            $dsn=$driver.':host='.$host.';dbname='.$name;
            if ($driver==='mysql') $dsn.=';charset=utf8mb4';
            $this->pdo=new PDO($dsn,(string)($config['user']??''),(string)($config['password']??''));
        } else throw new RuntimeException('Pilote DB inconnu');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        if ($driver !== 'sqlite') $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    public function pdo(): PDO { return $this->pdo; }
    public function transaction(callable $callback): mixed {
        $this->pdo->beginTransaction();
        try { $result=$callback($this->pdo); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function rows(string $sql,array $params=[]): array { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    public function one(string $sql,array $params=[]): ?array { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetch() ?: null; }
    public function execute(string $sql,array $params=[]): int { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->rowCount(); }
}

final class Migrations {
    public function __construct(private readonly Database $database, private readonly string $directory) {}

    /** Read-only preview; historical migrations without checksums remain unverified. */
    public function status(): array {
        $applied = [];
        $checksums = [];
        try {
            $applied = array_column($this->database->rows('SELECT version FROM cgf_migrations'), 'version');
        } catch (\PDOException $e) {
            if (!self::isMissingMetadataTable($e)) throw $e;
        }
        try {
            $checksums = array_column($this->database->rows('SELECT version, checksum FROM cgf_migration_checksums'), 'checksum', 'version');
        } catch (\PDOException $e) {
            if (!self::isMissingMetadataTable($e)) throw $e;
        }
        $files = $this->files();
        $rows = [];
        foreach ($files as $version => $path) {
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) throw new RuntimeException('Empreinte de migration impossible : '.$version);
            $wasApplied = in_array($version, $applied, true);
            $stored = $checksums[$version] ?? null;
            $state = !$wasApplied ? 'pending' : ($stored === null ? 'legacy_unverified'
                : (hash_equals($stored, $checksum) ? 'applied' : 'modified'));
            $rows[] = ['version'=>$version, 'state'=>$state, 'sha256'=>$checksum];
        }
        foreach ($applied as $version) {
            if (!isset($files[$version])) $rows[] = ['version'=>$version, 'state'=>'missing', 'sha256'=>null];
        }
        return $rows;
    }

    public function up(): array {
        // Advisory lock for concurrent CLI workers on the same host; not distributed.
        $lockPath = sys_get_temp_dir().'/cgf-migrations-'.hash('sha256', $this->directory).'.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new RuntimeException('Verrou migrations indisponible');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Une migration est déjà en cours');
            $pdo = $this->database->pdo();
            $pdo->exec('CREATE TABLE IF NOT EXISTS cgf_migrations (version VARCHAR(160) PRIMARY KEY, applied_at VARCHAR(40) NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS cgf_migration_checksums (version VARCHAR(160) PRIMARY KEY, checksum VARCHAR(64) NOT NULL)');
            $applied = array_column($this->database->rows('SELECT version FROM cgf_migrations'), 'version');
            $checksums = array_column($this->database->rows('SELECT version, checksum FROM cgf_migration_checksums'), 'checksum', 'version');
            $files = $this->files();
            // Preflight ALL checksums before applying a single pending migration.
            foreach ($applied as $version) {
                if (!isset($files[$version])) throw new RuntimeException('Migration déjà appliquée manquante : '.$version);
                if (isset($checksums[$version])) {
                    $current = hash_file('sha256', $files[$version]);
                    if ($current === false || !hash_equals($checksums[$version], $current)) {
                        throw new RuntimeException('Migration appliquée modifiée : '.$version);
                    }
                }
            }
            $done = [];
            foreach ($files as $version => $path) {
                if (in_array($version, $applied, true)) continue;
                $checksum = hash_file('sha256', $path);
                if ($checksum === false) throw new RuntimeException('Empreinte de migration impossible : '.$version);
                $callable = require $path;
                if (!is_callable($callable)) throw new RuntimeException('Migration sans callback : '.$version);
                $this->database->transaction(function(PDO $db) use ($callable, $version, $checksum): void {
                    $callable($db);
                    $statement = $db->prepare('INSERT INTO cgf_migrations (version,applied_at) VALUES (?,?)');
                    $statement->execute([$version, gmdate('c')]);
                    $statement = $db->prepare('INSERT INTO cgf_migration_checksums (version,checksum) VALUES (?,?)');
                    $statement->execute([$version, $checksum]);
                });
                $done[] = $version;
            }
            return $done;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function isMissingMetadataTable(\PDOException $error): bool {
        $state=(string)$error->getCode();
        if (in_array($state,['42P01','42S02'],true)) return true;
        return str_contains(strtolower($error->getMessage()),'no such table: cgf_migration');
    }

    private function files(): array {
        $files = [];
        foreach (glob($this->directory.'/*.php') ?: [] as $path) {
            $name = basename($path, '.php');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) throw new RuntimeException('Migration invalide');
            $files[$name] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }
}

final class FileCache {
    public function __construct(private readonly string $directory) {
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cache inaccessible');
    }
    public function get(string $key,mixed $default=null): mixed {
        $file=$this->file($key);
        if (!is_file($file) || is_link($file)) return $default;
        $json=json_decode((string)file_get_contents($file),true);
        if (!is_array($json) || !isset($json['expires']) || time() >= (int)$json['expires']) return $default;
        return $json['value'] ?? $default;
    }
    public function set(string $key,mixed $value,int $ttl): void {
        if ($ttl < 1 || $ttl > 31536000) throw new RuntimeException('TTL hors limites');
        $json=json_encode(['expires'=>time()+$ttl,'value'=>$value],JSON_THROW_ON_ERROR);
        $tmp=tempnam($this->directory,'.cgf-');
        if ($tmp===false) throw new RuntimeException('Cache indisponible');
        try { file_put_contents($tmp,$json,LOCK_EX); chmod($tmp,0600); rename($tmp,$this->file($key)); }
        finally { if (is_file($tmp)) unlink($tmp); }
    }
    public function delete(string $key): void { $f=$this->file($key); if (is_file($f) && !is_link($f)) unlink($f); }
    private function file(string $key): string { return $this->directory.'/'.hash('sha256',$key).'.json'; }
}
