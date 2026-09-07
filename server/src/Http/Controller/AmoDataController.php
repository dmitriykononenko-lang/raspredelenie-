<?php
declare(strict_types=1);
namespace DealDist\Http\Controller;
use DealDist\AmoCRM\ApiClient;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
/** GET /api/amo/bootstrap — account_id(users/pipelines) для виджета без window.AMOCRM. */
class AmoDataController
{
    private string $storage;
    public function __construct(private readonly Logger $logger)
    {
        $this->storage = rtrim($_ENV['STORAGE_PATH'] ?? '/storage', '/');
    }
    public function bootstrap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = $request->getQueryParams();
        $raw = trim((string) ($q['account_id'] ?? $request->getHeaderLine('X-Account-Id')));
        $sub = trim((string) ($q['subdomain'] ?? $request->getHeaderLine('X-Account-Subdomain')));
        $accountId = $this->resolve($raw, $sub);
        $out = ['ok' => true, 'account_id' => $accountId, 'subdomain' => $sub, 'users' => [], 'pipelines' => []];
        if ($accountId === '') {
            $out['ok'] = false;
            $out['error'] = 'account_not_resolved';
            return $this->json($response, $out);
        }
        $api = new ApiClient($this->logger);
        try { $out['users'] = $api->getUsers($accountId); }
        catch (\Throwable $e) { $this->logger->warning('bootstrap users failed', ['acc' => $accountId, 'err' => $e->getMessage()]); }
        try { $out['pipelines'] = $api->getPipelines($accountId); }
        catch (\Throwable $e) { $this->logger->warning('bootstrap pipelines failed', ['acc' => $accountId, 'err' => $e->getMessage()]); }
        return $this->json($response, $out);
    }
    private function resolve(string $raw, string $sub): string
    {
        if ($raw !== '' && ctype_digit($raw)) return $raw;
        $needle = strtolower($sub !== '' ? $sub : $raw);
        if ($needle === '') return '';
        $ltAcc = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        $ltDom = strtolower(trim((string) ($_ENV['AMO_BASE_DOMAIN'] ?? '')));
        if ($ltAcc !== '' && $ltDom !== '' && strpos($ltDom, $needle) === 0) return $ltAcc;
        foreach (glob($this->storage . '/tokens/*.json') ?: [] as $f) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (!is_array($d)) continue;
            $bd = strtolower((string) ($d['base_domain'] ?? ''));
            if ($bd !== '' && ($bd === $needle || strpos($bd, $needle . '.') === 0)) {
                return (string) preg_replace('/\.json$/', '', basename($f));
            }
        }
        return '';
    }
    private function json(ResponseInterface $r, array $data, int $status = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $r->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
