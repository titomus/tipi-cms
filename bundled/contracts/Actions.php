<?php
declare(strict_types=1);
namespace CGF;

final class ActionRegistry {
    private array $actions = [];

    /** Optional authorization callback receives ($args, $principal) and must return true. */
    public function register(string $name, string $description, string $mode, array $schema,
        callable $handler, ?callable $authorize = null): void {
        if (!preg_match('/^[a-z][a-z0-9_]{1,50}$/D', $name) || !in_array($mode, ['read','write'], true)) {
            throw new \RuntimeException('Contrat action invalide');
        }
        if (isset($this->actions[$name])) throw new \RuntimeException('Action dupliquée');
        ActionSchema::definitions($schema);
        $this->actions[$name] = ['description'=>$description, 'mode'=>$mode, 'schema'=>$schema,
            'handler'=>$handler, 'authorize'=>$authorize];
    }

    public function all(): array { return $this->actions; }

    public function definitions(): array {
        $result = [];
        foreach ($this->actions as $name => $a) {
            $result[] = ['name'=>'app_'.$name, 'description'=>$a['description'],
                'inputSchema'=>ActionSchema::definitions($a['schema'])];
        }
        return $result;
    }

    public function run(string $name, array $args, array $principal): array {
        $a = $this->actions[$name] ?? null;
        if ($a === null) throw new HttpError(404, 'Action inconnue');
        if (($principal['role'] ?? '') !== 'admin') throw new HttpError(403, 'Non autorisé');
        ActionSchema::assertValue($args, $a['schema']);
        if ($a['authorize'] !== null && ($a['authorize'])($args, $principal) !== true) {
            throw new HttpError(403, 'Accès à cette ressource refusé');
        }
        $data = ($a['handler'])($args, $principal);
        if (!is_array($data)) throw new \RuntimeException('Action handler must return an array');
        return $data;
    }
}
