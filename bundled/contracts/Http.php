<?php
declare(strict_types=1);
namespace CGF;

use RuntimeException;

final class Request {
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $form = [],
        public readonly array $headers = [],
        public readonly string $body = ''
    ) {}
    public static function fromGlobals(): self {
        $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($uri) && $uri !== '' ? $uri : '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        $body = (string)file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($body) > 1048576) throw new HttpError(413, 'Requête trop volumineuse');
        return new self(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $_GET, $_POST, $headers, $body);
    }
    public function json(): array {
        if (strlen($this->body) > 1048576) throw new HttpError(413, 'Requête trop volumineuse');
        try {
            $object = json_decode($this->body, false, 32, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) throw new HttpError(400, 'Un objet JSON est attendu');
            $value = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (\JsonException) {
            throw new HttpError(400, 'JSON invalide');
        }
    }

    public function header(string $key): string { return $this->headers[strtolower($key)] ?? ''; }
}

final class HttpError extends RuntimeException {
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

final class Response {
    public function __construct(public readonly string $body = '', public readonly int $status = 200, public readonly array $headers = []) {}
    public static function json(array $body, int $status = 200): self {
        return new self(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status, ['Content-Type'=>'application/json; charset=utf-8']);
    }
    public static function html(string $body, int $status = 200): self {
        return new self($body, $status, ['Content-Type'=>'text/html; charset=utf-8']);
    }
    public static function redirect(string $url): self { return new self('', 303, ['Location'=>$url]); }
    public function send(bool $head = false): void {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        foreach ($this->headers as $k=>$v) header($k . ': ' . $v);
        if (!$head) echo $this->body;
    }
}

final class Router {
    private array $routes = [];
    private array $middleware = [];
    public function use(callable $middleware): void { $this->middleware[] = $middleware; }
    public function add(string $method, string $pattern, callable $handler): self {
        $method = strtoupper($method);
        if ($pattern === '' || $pattern[0] !== '/') throw new RuntimeException('Route non absolue');
        $parts = preg_split('~(\{[a-zA-Z_][a-zA-Z0-9_]*\})~', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('~^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$~', $part, $m)) $regex .= '(?P<' . $m[1] . '>[^/]+)';
            else $regex .= preg_quote($part, '~');
        }
        $this->routes[] = [$method, '~^' . $regex . '/?$~D', $handler];
        return $this;
    }
    public function get(string $pattern, callable $handler): self { return $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): self { return $this->add('POST', $pattern, $handler); }
    public function dispatch(Request $request): Response {
        $allowed = [];
        foreach ($this->routes as [$method,$regex,$handler]) {
            if (!preg_match($regex, $request->path, $matches)) continue;
            if ($method !== $request->method && !($request->method === 'HEAD' && $method === 'GET')) {
                $allowed[] = $method; continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $next = static fn(Request $r): Response => $handler($r, $params);
            foreach (array_reverse($this->middleware) as $mw) {
                $previous = $next;
                $next = static fn(Request $r): Response => $mw($r, $previous);
            }
            return $next($request);
        }
        if ($allowed !== []) return new Response('Méthode non autorisée',405,['Allow'=>implode(', ',array_unique($allowed))]);
        return Response::html('<h1>404 · Page introuvable</h1>',404);
    }
}
