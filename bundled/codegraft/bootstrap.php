<?php
declare(strict_types=1);

// The core is pure PHP 8.2+, not coupled to WordPress, FlightPHP or Composer.
require_once __DIR__ . '/src/Policy.php';
require_once __DIR__ . '/src/Vault.php';
require_once __DIR__ . '/src/Workspace.php';
require_once __DIR__ . '/src/DynamicOAuth.php';
require_once __DIR__ . '/src/OAuthHttp.php';
require_once __DIR__ . '/src/CommandExecutor.php';
require_once __DIR__ . '/src/Mcp.php';
require_once __DIR__ . '/src/Admin.php';
require_once __DIR__ . '/src/AuditLog.php';
require_once __DIR__ . '/src/ControlCenter.php';

function codegraft(string $projectRoot, string $vaultDir, string $adapter = 'generic'): array
{
    $vault = new \CodeGraft\Vault($projectRoot, $vaultDir, $adapter);
    $workspace = new \CodeGraft\Workspace($vault);
    return ['vault' => $vault, 'workspace' => $workspace,
        'mcp' => new \CodeGraft\Mcp($vault, $workspace)];
}
