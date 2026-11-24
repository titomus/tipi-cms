<?php

declare(strict_types=1);

namespace CodeGraft;
use InvalidArgumentException;

/**
 * ChatGPT registers a public OAuth client automatically (DCR + PKCE).
 * The admin authenticates normally and explicitly consents to each scope.
 */
final class OAuthHttp
{
    public function __construct(private readonly Vault $vault) {}

    private function oauth(): DynamicOAuth
    {
        return new DynamicOAuth($this->vault);
    }

    private function path(string $action): string
    {
        $url = (string) ($this->vault->json('endpoint.json', [])['oauth_resource'] ?? '');
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') throw new \RuntimeException('OAuth endpoint not mounted.');
        return rtrim($path, '/') . '/oauth/' . $action . '/';
    }

    private function startOwnerSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('codegraft_admin');
        session_set_cookie_params(['secure' => true, 'httponly' => true,
            'samesite' => 'Lax', 'path' => '/']);
        session_start();
    }

    private function csrfToken(): string
    {
        $this->startOwnerSession();
        return $_SESSION['cg_csrf'] ??= bin2hex(random_bytes(24));
    }

    public function metadata(): void
    {
        $base = $this->oauth()->baseUrl();
        $this->json([
            'issuer' => $base,
            'authorization_endpoint' => $base . $this->path('authorize'),
            'token_endpoint' => $base . $this->path('token'),
            'registration_endpoint' => $base . $this->path('register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [
                DynamicOAuth::READ, DynamicOAuth::WRITE,
                DynamicOAuth::APP_READ, DynamicOAuth::APP_WRITE,
            ],
        ]);
    }

    public function protectedResource(): void
    {
        $this->json([
            'resource' => $this->oauth()->resource(),
            'authorization_servers' => [$this->oauth()->baseUrl()],
            'scopes_supported' => [
                DynamicOAuth::READ, DynamicOAuth::WRITE,
                DynamicOAuth::APP_READ, DynamicOAuth::APP_WRITE,
            ],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function register(): void
    {
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
            return;
        }

        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($body) || strlen($body) > 16384) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
            return;
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                throw new InvalidArgumentException('invalid_client_metadata');
            }
            $this->json(($this->oauth())->register($payload), 201);
        } catch (InvalidArgumentException|\JsonException) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
        } catch (\Throwable $error) {
            error_log('MCP client registration failure: ' . $error->getMessage());
            $this->json(['error' => 'server_error'], 503);
        }
    }

    public function authorize(): void
    {
        header('Cache-Control: no-store');
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\' https://chatgpt.com; base-uri \'none\'; frame-ancestors \'none\'');

        $request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
        $oauth = $this->oauth();

        try {
            $validated = $oauth->verifyAuthorizationRequest($request);
        } catch (\Throwable $error) {
            error_log('MCP authorization request validation failed: ' . $error->getMessage());
            http_response_code(503);
            echo 'OAuth service is temporarily unavailable.';
            return;
        }

        if ($validated === null) {
            http_response_code(400);
            echo 'Invalid OAuth client, callback, scopes or PKCE challenge.';
            return;
        }

        $this->startOwnerSession();
        if (($_SESSION['cg_authenticated'] ?? false) !== true) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $adminPath = (string) ($this->vault->json('endpoint.json', [])['oauth_admin_path']
                ?? '/codegraft/standalone/admin.php');
            echo '<!doctype html><meta charset="utf-8"><title>Connexion requise</title>'
                . '<p>Connectez-vous à CodeGraft dans un autre onglet puis rechargez cette page.</p>'
                . '<a href="' . htmlspecialchars($adminPath, ENT_QUOTES, 'UTF-8')
                . '" target="_blank" rel="noopener">Se connecter</a>';
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $submittedCsrf = (string) ($_POST['_csrf'] ?? '');
            if ($submittedCsrf === '' || !hash_equals($this->csrfToken(), $submittedCsrf)) {
                http_response_code(403);
                echo 'Invalid consent CSRF token.';
                return;
            }

            try {
                if (($_POST['decision'] ?? '') === 'approve') {
                    $code = $oauth->authorize(1, $validated);
                    $parameters = ['code' => $code];
                } else {
                    $parameters = ['error' => 'access_denied'];
                }
            } catch (\Throwable $error) {
                error_log('MCP consent failure: ' . $error->getMessage());
                http_response_code(503);
                echo 'Authorization could not be completed.';
                return;
            }

            $parameters['state'] = $validated['state'];
            header('Location: ' . $validated['redirect_uri'] . '?'
                . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), true, 303);
            // Preserve the explicit 303 and avoid FlightPHP rewriting it.
            exit;
        }

        $fields = '';
        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge',
            'code_challenge_method', 'scope', 'state', 'resource'] as $name) {
            if (isset($request[$name]) && is_string($request[$name])) {
                $fields .= '<input type="hidden" name="'
                    . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                    . '" value="' . htmlspecialchars($request[$name], ENT_QUOTES, 'UTF-8') . '">';
            }
        }

        $scopes = explode(' ', $validated['scope']);
        $rights = [];
        if (in_array(DynamicOAuth::READ, $scopes, true)) {
            $rights[] = 'consulter les fichiers explicitement autorisés';
        }
        if (in_array(DynamicOAuth::WRITE, $scopes, true)) {
            $rights[] = 'proposer et déployer du code seulement si CodeGraft le permet';
        }
        if (in_array(DynamicOAuth::APP_READ, $scopes, true)) {
            $rights[] = 'consulter les informations métier du compte propriétaire';
        }
        if (in_array(DynamicOAuth::APP_WRITE, $scopes, true)) {
            $rights[] = 'modifier les données applicatives du compte propriétaire';
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width"><title>Autorisation ChatGPT</title>'
            . '<main style="max-width:560px;padding:24px;margin:8vh auto;font:16px system-ui,sans-serif;line-height:1.6">'
            . '<h1>Connecter cette application à ChatGPT</h1><p>Vous autorisez ChatGPT à :</p><ul><li>'
            . implode('</li><li>', array_map(static fn (string $right): string =>
                htmlspecialchars($right, ENT_QUOTES, 'UTF-8'), $rights))
            . '</li></ul><p>Le coeur OAuth et MCP reste protégé ; le déploiement de code métier '
            . 'exige la permission app:deploy et une confirmation.</p>'
            . '<form method="post" action="' . htmlspecialchars($this->path('authorize'), ENT_QUOTES, 'UTF-8') . '">'
            . $fields . '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . '">'
            . '<button name="decision" value="approve" style="padding:12px 20px;margin-right:10px">Autoriser</button>'
            . '<button name="decision" value="deny" style="padding:12px 20px">Refuser</button>'
            . '</form></main></html>';
    }

    public function token(): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') === false) {
            $this->json(['error' => 'invalid_request'], 400);
            return;
        }

        // Dynamic registration produces a public client, authenticated with PKCE.
        if (isset($_POST['client_secret'])
            || str_starts_with((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Basic ')) {
            $this->json(['error' => 'invalid_client'], 401);
            return;
        }

        try {
            $oauth = $this->oauth();
            $result = match ($_POST['grant_type'] ?? '') {
                'authorization_code' => $oauth->exchange($_POST),
                'refresh_token' => $oauth->refresh($_POST),
                default => throw new InvalidArgumentException('unsupported_grant_type'),
            };
            $this->json($result);
        } catch (InvalidArgumentException $error) {
            $message = $error->getMessage();
            $this->json([
                'error' => in_array($message, ['invalid_grant', 'unsupported_grant_type'], true)
                    ? $message : 'invalid_grant',
            ], 400);
        } catch (\Throwable $error) {
            error_log('MCP OAuth token failure: ' . $error->getMessage());
            $this->json(['error' => 'server_error'], 503);
        }
    }

    private function json(array $payload, int $code = 200): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // The HTTP controller must preserve the response status after a route callback
        // returns. OAuth status codes (201/400/401/503) must reach clients
        // unchanged, so finish the HTTP response here.
        exit;
    }
}
