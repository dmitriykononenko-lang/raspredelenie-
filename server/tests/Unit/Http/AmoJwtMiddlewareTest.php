<?php
declare(strict_types=1);
namespace DealDist\Tests\Unit\Http;

use DealDist\Http\Middleware\AmoJwtMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** 1.3.21: X-Auth-Token (JWT amoCRM из $authorizedAjax). */
final class AmoJwtMiddlewareTest extends TestCase
{
    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        $_ENV['AMO_CLIENT_SECRET'] = self::SECRET;
        $_ENV['AMO_CLIENT_ID']     = 'uuid-1';
        unset($_ENV['AMO_JWT_ENFORCE']);
    }

    private static function jwt(array $claims, string $secret = self::SECRET): string
    {
        $b = fn($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $h = $b(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = $b(json_encode($claims));
        return "$h.$p." . $b(hash_hmac('sha256', "$h.$p", $secret, true));
    }

    private static function claims(array $over = []): array
    {
        return $over + ['iss' => 'https://acme.amocrm.ru', 'account_id' => 111, 'user_id' => 5, 'client_uuid' => 'uuid-1', 'iat' => time(), 'nbf' => time(), 'exp' => time() + 300];
    }

    /** @return array{0:ResponseInterface,1:?ServerRequestInterface} */
    private function call(string $uri, array $headers): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $req = $req->withQueryParams($q);
        foreach ($headers as $k => $v) { $req = $req->withHeader($k, $v); }
        $h = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;
            public function handle(ServerRequestInterface $r): ResponseInterface { $this->seen = $r; return new Response(200); }
        };
        $res = (new AmoJwtMiddleware())->process($req, $h);
        return [$res, $h->seen];
    }

    public function test_verify_accepts_valid_and_rejects_tampered(): void
    {
        self::assertSame('111', (string) AmoJwtMiddleware::verify(self::jwt(self::claims()), self::SECRET, 'uuid-1')['account_id']);
        self::assertNull(AmoJwtMiddleware::verify(self::jwt(self::claims(), 'other'), self::SECRET));
        self::assertNull(AmoJwtMiddleware::verify(self::jwt(self::claims(['exp' => time() - 1000])), self::SECRET));
        self::assertNull(AmoJwtMiddleware::verify(self::jwt(self::claims(['client_uuid' => 'foreign'])), self::SECRET, 'uuid-1'));
        self::assertNull(AmoJwtMiddleware::verify('garbage', self::SECRET));
    }

    public function test_valid_token_fills_missing_account_header(): void
    {
        [$res, $seen] = $this->call('/api/status', ['X-Auth-Token' => self::jwt(self::claims())]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('111', $seen->getHeaderLine('X-Account-Id'));
        self::assertSame('verified', $seen->getAttribute('amo_jwt'));
    }

    public function test_foreign_account_is_forbidden(): void
    {
        [$res] = $this->call('/api/status', ['X-Auth-Token' => self::jwt(self::claims()), 'X-Account-Id' => '222']);
        self::assertSame(403, $res->getStatusCode());
        [$res] = $this->call('/api/amo/bootstrap?account_id=222', ['X-Auth-Token' => self::jwt(self::claims())]);
        self::assertSame(403, $res->getStatusCode());
        [$res] = $this->call('/api/amo/bootstrap?subdomain=other', ['X-Auth-Token' => self::jwt(self::claims())]);
        self::assertSame(403, $res->getStatusCode());
        [$res] = $this->call('/api/amo/bootstrap?subdomain=acme', ['X-Auth-Token' => self::jwt(self::claims())]);
        self::assertSame(200, $res->getStatusCode());
    }

    public function test_soft_mode_passes_old_widgets_and_enforce_blocks(): void
    {
        [$res, $seen] = $this->call('/api/status', ['X-Account-Id' => '111']);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('none', $seen->getAttribute('amo_jwt'));
        [$res, $seen] = $this->call('/api/status', ['X-Account-Id' => '111', 'X-Auth-Token' => 'bad.token.here']);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('invalid', $seen->getAttribute('amo_jwt'));

        $_ENV['AMO_JWT_ENFORCE'] = 'true';
        [$res] = $this->call('/api/status', ['X-Account-Id' => '111']);
        self::assertSame(401, $res->getStatusCode());
        [$res] = $this->call('/health', []);
        self::assertSame(200, $res->getStatusCode());
    }
}
