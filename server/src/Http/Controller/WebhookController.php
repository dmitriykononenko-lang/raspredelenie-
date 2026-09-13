<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\AmoCRM\ApiClient;
use DealDist\Config\SettingsStorage;
use DealDist\Distribution\DistributionService;
use DealDist\Distribution\DistributionLog;
use DealDist\Distribution\QueueStorage;
use DealDist\Distribution\ScheduleChecker;
use DealDist\Distribution\StatusStorage;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles incoming webhooks from AmoCRM.
 *
 * Поддерживаются ДВА формата:
 *
 * 1) Digital Pipeline виджета (manifest.json → dp.webhook_url). amo шлёт POST
 *    (form-urlencoded или JSON) вида:
 *      event[type_code]=lead_appeared_in_status
 *      event[data][id]=<lead_id>  event[data][status_id]=..  event[data][pipeline_id]=..
 *      action[settings][widget][settings][...]   — то, что вернул dpSettings().save()
 *      account_id=<id>  subdomain=<subdomain>
 *    account_id берём из тела (fallback — ?account_id= в URL).
 *
 * 2) Классический вебхук аккаунта («Настройки → Интеграции → Webhooks»):
 *      leads[add][]  /  leads[status][]  + обязательный ?account_id= в URL.
 *
 * Защита: если задан WIDGET_SECRET, проверяется заголовок X-Widget-Secret ИЛИ
 * ?key=<secret> в URL. amo для DP-вебхука заголовок прислать не может, поэтому
 * либо добавьте ?key= в dp.webhook_url манифеста, либо отключите проверку
 * WEBHOOK_SECRET_ENFORCE=false (тогда вебхук открыт — полагаемся на неугадываемость URL).
 */
class WebhookController
{
    public function __construct(private readonly Logger $logger) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        $secret  = (string) ($_ENV['WIDGET_SECRET'] ?? '');
        $enforce = filter_var($_ENV['WEBHOOK_SECRET_ENFORCE'] ?? 'true', FILTER_VALIDATE_BOOL);
        if ($secret !== '' && $enforce) {
            $provided = $request->getHeaderLine('X-Widget-Secret');
            if ($provided === '') {
                $provided = (string) ($query['key'] ?? '');
            }
            if (!hash_equals($secret, $provided)) {
                $this->logger->warning('Webhook rejected: bad secret', ['ua' => $request->getHeaderLine('User-Agent')]);
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }
        }

        $body = $this->parseBody($request);

        $accountId  = (string) ($body['account_id'] ?? $query['account_id'] ?? '');
        $dpSettings = [];
        $leads      = [];

        // ── Формат 1: Digital Pipeline виджета ────────────────────────────────
        if (isset($body['event']) && is_array($body['event'])) {
            $data = (array) ($body['event']['data'] ?? []);
            $typeCode = (string) ($body['event']['type_code'] ?? $body['event']['type'] ?? 'dp');
            if (!empty($data['id'])) {
                $leads[] = [
                    'id'          => (int) $data['id'],
                    'status_id'   => $data['status_id']   ?? null,
                    'pipeline_id' => $data['pipeline_id'] ?? null,
                    'updated_at'  => $data['updated_at'] ?? null,
                    '_event'      => 'dp:' . $typeCode,
                ];
            }
            $ws = $body['action']['settings']['widget']['settings']
                ?? $body['action']['settings']['widget']
                ?? $body['action']['settings']
                ?? [];
            $dpSettings = $this->normalizeDpSettings(is_array($ws) ? $ws : []);
        }

        // ── Формат 2: классический вебхук leads[add] / leads[status] ─────────
        if (empty($leads)) {
            $addedLeads    = $body['leads']['add']    ?? [];
            $statusedLeads = $body['leads']['status'] ?? [];
            $leads = array_merge(
                $this->normalizeLeads((array) $addedLeads,    'add'),
                $this->normalizeLeads((array) $statusedLeads, 'status'),
            );
            if ($accountId === '' && !empty($body['account']['id'])) {
                $accountId = (string) $body['account']['id'];
            }
        }

        if (empty($leads)) {
            $this->logger->info('Webhook: no leads in payload', ['keys' => array_keys($body)]);
            return $this->json($response, ['status' => 'no_leads']);
        }

        if ($accountId === '') {
            return $this->json($response, ['error' => 'account_id required (body or query)'], 400);
        }

        $settings = (new SettingsStorage())->load($accountId);
        $results  = [];

        // ── Ранний ответ amoCRM (P0) ────────────────────────────────────────────
        // amoCRM ждёт ответ ~2 сек и при задержке повторяет вебхук (параллельно).
        // Цепочка GET lead → PATCH responsible занимает 2–3 сек, поэтому отдаём
        // 200 сразу, а распределение выполняем уже после закрытия соединения.
        $early = false;
        if (function_exists('fastcgi_finish_request') && !headers_sent()) {
            ignore_user_abort(true);
            @set_time_limit(120);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'accepted', 'leads' => count($leads)], JSON_UNESCAPED_UNICODE);
            fastcgi_finish_request();
            $early = true;
        }

        $service = new DistributionService(
            new ApiClient($this->logger),
            new QueueStorage(),
            new ScheduleChecker(),
            new StatusStorage(),
            new DistributionLog(),
            $this->logger,
        );

        foreach ($leads as $lead) {
            $payload = [
                'account_id'          => $accountId,
                'lead_id'             => (int) $lead['id'],
                'pipeline_id'         => isset($lead['pipeline_id']) && $lead['pipeline_id'] !== '' ? (int) $lead['pipeline_id'] : null,
                'stage_id'            => isset($lead['status_id'])   && $lead['status_id']   !== '' ? (int) $lead['status_id']   : null,
                'distribution_method' => $settings['distribution_method'] ?? 'round_robin',
                'rules'               => $settings['rules'] ?? [],
                'dp_settings'         => $dpSettings,
                'event'               => $lead['_event'],
                'event_key'           => \DealDist\Distribution\OperationJournal::keyFromWebhookLead($lead, (string) $lead['_event']),
            ];

            try {
                $result    = $service->distribute($payload);
                $results[] = ['lead_id' => $lead['id'], 'result' => $result ?? 'skipped'];
            } catch (\Throwable $e) {
                $this->logger->error('Webhook distribution error', [
                    'lead_id' => $lead['id'],
                    'error'   => $e->getMessage(),
                ]);
                $results[] = ['lead_id' => $lead['id'], 'error' => $e->getMessage()];
            }
        }

        $this->logger->info('Webhook processed', ['account_id' => $accountId, 'early_ack' => $early, 'results' => $results]);

        return $this->json($response, ['status' => 'ok', 'results' => $results]);
    }

    /** Тело может прийти как form-urlencoded (PHP → $_POST), JSON или сырой query-string. */
    private function parseBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        if (is_object($parsed)) {
            return (array) $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        parse_str($raw, $form);
        return is_array($form) ? $form : [];
    }

    /**
     * Приводим настройки DP-действия к виду, который ждёт DistributionService:
     * managers → [{id:int}], check_history/check_schedule → bool.
     * amo может прислать managers как список объектов, список id или map.
     */
    private function normalizeDpSettings(array $ws): array
    {
        $out = $ws;
        $managers = [];
        foreach ((array) ($ws['managers'] ?? []) as $m) {
            $id = is_array($m) ? ($m['id'] ?? null) : $m;
            if ($id !== null && $id !== '' && (int) $id > 0) {
                $managers[] = ['id' => (int) $id];
            }
        }
        $out['managers'] = $managers;
        foreach (['check_history', 'check_schedule'] as $flag) {
            if (array_key_exists($flag, $ws)) {
                $out[$flag] = filter_var($ws[$flag], FILTER_VALIDATE_BOOL);
            }
        }
        return $out;
    }

    /** AmoCRM sends each lead as a sub-array; normalize to a flat list */
    private function normalizeLeads(array $leads, string $event): array
    {
        return array_map(static function (array $lead) use ($event): array {
            $lead['_event'] = $event;
            return $lead;
        }, $leads);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
