<?php

declare(strict_types=1);

namespace DealDist\Http\Middleware;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Проверка одноразового токена amoCRM (X-Auth-Token) на /api/*.
 *
 * Виджет 1.3.21+ шлёт запросы через self.$authorizedAjax(): amoCRM добавляет
 * заголовок X-Auth-Token — JWT (HS256), подписанный client_secret интеграции,
 * в нём account_id, user_id, client_uuid, exp. Так бэкенд знает, что запрос
 * пришёл из этого аккаунта, а не подделан по чужому X-Account-Id.
 *
 * Режимы:
 *   • токен есть и валиден → account_id из токена; X-Account-Id (если число)
 *     и ?account_id= должны совпадать, иначе 403. Нет X-Account-Id — подставляем.
 *   • токен есть, но не прошёл проверку → лог; 401 только при AMO_JWT_ENFORCE=true.
 *   • токена нет → пропускаем (старые версии виджета); 401 только при AMO_JWT_ENFORCE=true.
 * Атрибуты запроса: amo_jwt = verified|invalid|none, amo_user_id.
 */
final class AmoJwtMiddleware implements MiddlewareInterface
{
    private const LEEWAY = 120;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS' || strncmp($request->getUri()->getPath(), '/api/', 5) !== 0) {
            return $handler->handle($request);
        }
        $enforce = in_array(strtolower(trim((string) ($_ENV['AMO_JWT_ENFORCE'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
        $token   = trim($request->getHeaderLine('X-Auth-Token'));

        if ($token === '') {
            if ($enforce) {
                return $this->deny(401, 'X-Auth-Token required');
            }
            return $handler->handle($request->withAttribute('amo_jwt', 'none'));
        }

        $claims = self::verify($token, (string) ($_ENV['AMO_CLIENT_SECRET'] ?? ''), (string) ($_ENV['AMO_CLIENT_ID'] ?? ''));
        if ($claims === null) {
            $this->log()->warning('Invalid X-Auth-Token', ['path' => $request->getUri()->getPath(), 'x_account' => $request->getHeaderLine('X-Account-Id')]);
            if ($enforce) {
                return $this->deny(401, 'Invalid X-Auth-Token');
            }
            return $handler->handle($request->withAttribute('amo_jwt', 'invalid'));
        }

        $acc   = (string) $claims['account_id'];
        $hdr   = trim($request->getHeaderLine('X-Account-Id'));
        $query = $request->getQueryParams();
        $mismatch = (ctype_digit($hdr) && $hdr !== $acc)
            || (isset($query['account_id']) && ctype_digit((string) $query['account_id']) && (string) $query['account_id'] !== $acc)
            || (isset($query['subdomain']) && $query['subdomain'] !== '' && !self::subdomainMatches((string) $query['subdomain'], (string) ($claims['iss'] ?? '')));
        if ($mismatch) {
            $this->log()->warning('X-Auth-Token account mismatch', ['token_account' => $acc, 'x_account' => $hdr, 'path' => $request->getUri()->getPath()]);
            return $this->deny(403, 'Account mismatch');
        }
        if (!ctype_digit($hdr)) {
            $request = $request->withHeader('X-Account-Id', $acc);
        }
        return $handler->handle($request
            ->withAttribute('amo_jwt', 'verified')
            ->withAttribute('amo_account_id', $acc)
            ->withAttribute('amo_user_id', (string) ($claims['user_id'] ?? '')));
    }

    /** @return array<string,mixed>|null claims, если подпись и сроки в порядке */
    public static function verify(string $jwt, string $secret, string $clientId = '', ?int $now = null): ?array
    {
        if ($secret === '') {
            return null;
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode((string) self::b64d($h64), true);
        $claims = json_decode((string) self::b64d($p64), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $sig = self::b64d($s64);
        if ($sig === null || !hash_equals(hash_hmac('sha256', $h64 . '.' . $p64, $secret, true), $sig)) {
            return null;
        }
        $now = $now ?? time();
        if (isset($claims['exp']) && $now > (int) $claims['exp'] + self::LEEWAY) {
            return null;
        }
        if (isset($claims['nbf']) && $now + self::LEEWAY < (int) $claims['nbf']) {
            return null;
        }
        if ($clientId !== '' && isset($claims['client_uuid']) && (string) $claims['client_uuid'] !== $clientId) {
            return null;
        }
        if (empty($claims['account_id']) || !ctype_digit((string) $claims['account_id'])) {
            return null;
        }
        return $claims;
    }

    private static function subdomainMatches(string $sub, string $iss): bool
    {
        $host = (string) (parse_url($iss, PHP_URL_HOST) ?: $iss);
        if ($host === '') {
            return true; // не с чем сравнить
        }
        return strcasecmp(explode('.', $host)[0], $sub) === 0;
    }

    private static function b64d(string $s): ?string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? null : $r;
    }

    private function deny(int $code, string $msg): ResponseInterface
    {
        $r = new Response($code);
        $r->getBody()->write(json_encode(['status' => 'error', 'message' => $msg]));
        return $r->withHeader('Content-Type', 'application/json');
    }

    private function log(): Logger
    {
        $l = new Logger('amo-jwt');
        $l->pushHandler(new StreamHandler('php://stderr', \Monolog\Level::Warning));
        return $l;
    }
}
