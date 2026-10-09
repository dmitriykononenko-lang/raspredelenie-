<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Billing\BillingService;
use DealDist\Billing\SubscriptionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Админка KO:AGENCY — единая панель по виджетам.
 *
 * Маршруты (вне /api/, поэтому AuthMiddleware их не трогает — своя проверка ADMIN_TOKEN):
 *   GET /admin                 — HTML-страница (ключ в ?key=)
 *   GET /admin/api/overview    — JSON-агрегат по всем аккаунтам Распределения
 *
 * Данные читаются напрямую из STORAGE_PATH (settings/statuses/tokens/logs/schedules/pending).
 * Секреты (токены) наружу не отдаются — только факт наличия и срок.
 */
class AdminController
{
    private string $storage;

    public function __construct()
    {
        $this->storage = rtrim($_ENV['STORAGE_PATH'] ?? '/storage', '/');
    }

    private function authorized(ServerRequestInterface $request): bool
    {
        $expected = (string) ($_ENV['ADMIN_TOKEN'] ?? '');
        if ($expected === '') {
            return false;
        }
        $q = $request->getQueryParams();
        $provided = (string) ($q['key'] ?? '');
        if ($provided === '') {
            $provided = $request->getHeaderLine('X-Admin-Token');
        }
        return hash_equals($expected, $provided);
    }

    /* --------------------------- API: overview --------------------------- */

    public function overview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            $response->getBody()->write(json_encode(['error' => 'unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $ltAccount = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        $ltDomain  = trim((string) ($_ENV['AMO_BASE_DOMAIN'] ?? ''));
        $ltToken   = trim((string) ($_ENV['AMO_LONG_TERM_TOKEN'] ?? ''));

        $subs       = new SubscriptionStore($this->storage);
        $enforce    = ((string) ($_ENV['BILLING_ENFORCE'] ?? '')) === '1';

        $accounts = [];
        foreach ($this->accountIds() as $acc) {
            $settings = $this->readJson($this->storage . '/settings/' . $acc . '.json');
            $statuses = $this->readJson($this->storage . '/statuses/' . $acc . '.json');
            $tokenF   = $this->storage . '/tokens/' . $acc . '.json';
            $tokenD   = $this->readJson($tokenF);

            // домен
            $domain = '';
            if (!empty($tokenD['base_domain'])) {
                $domain = (string) $tokenD['base_domain'];
            } elseif ($ltAccount !== '' && $ltAccount === (string) $acc && $ltDomain !== '') {
                $domain = $ltDomain;
            }

            // токен
            $tokenPresent = false;
            $tokenKind    = 'none';
            $tokenExp     = null;
            if (is_file($tokenF)) {
                $tokenPresent = true;
                $tokenKind    = !empty($tokenD['long_term']) ? 'long_term' : 'oauth';
                $tokenExp     = $this->jwtExp($tokenD['access_token'] ?? '');
            } elseif ($ltToken !== '' && ($ltAccount === '' || $ltAccount === (string) $acc)) {
                $tokenPresent = true;
                $tokenKind    = 'env_long_term';
            }

            // правила / менеджеры
            $rules    = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
            $managers = [];
            foreach ($rules as $r) {
                foreach (($r['managers'] ?? []) as $m) {
                    $id = is_array($m) ? ($m['id'] ?? null) : $m;
                    if ($id !== null) { $managers[(string) $id] = true; }
                }
            }

            // онлайн
            $onlineIds = [];
            $totalUsers = 0;
            if (is_array($statuses)) {
                foreach ($statuses as $uid => $st) {
                    $totalUsers++;
                    if (!empty($st['online'])) { $onlineIds[] = (string) $uid; }
                }
            }

            // лог распределения (последние 25)
            $log = $this->tailNdjson($this->storage . '/logs/' . $acc . '.ndjson', 25);
            $lastReason = $log ? ($log[count($log) - 1]['reason'] ?? '') : '';
            $lastTs     = $log ? ($log[count($log) - 1]['ts'] ?? 0) : 0;

            // повторное распределение (pending)
            $pending = $this->readJson($this->storage . '/pending/' . $acc . '.json');
            $pendingCount = is_array($pending) ? count($pending) : 0;

            // расписания
            $schedDir = $this->storage . '/schedules/' . $acc;
            $schedCount = is_dir($schedDir) ? count(glob($schedDir . '/*.json') ?: []) : 0;

            // подписки (срок действия) по каждому виджету
            $subRead = $subs->read((string) $acc);
            $subscriptions = [];
            foreach (SubscriptionStore::WIDGETS as $wk) {
                $st = $subs->status((string) $acc, $wk);
                $st['history'] = $subRead[$wk]['history'] ?? [];
                $subscriptions[$wk] = $st;
            }

            $health = $this->health((string) $acc, $rules, is_array($statuses) ? $statuses : [], $subscriptions['raspredelenie'] ?? []);

            $accounts[] = [
                'health'        => $health,
                'account_id'    => (string) $acc,
                'domain'        => $domain,
                'token'         => ['present' => $tokenPresent, 'kind' => $tokenKind, 'exp' => $tokenExp],
                'method'        => (string) ($settings['distribution_method'] ?? '—'),
                'rules_count'   => count($rules),
                'managers_count'=> count($managers),
                'managers'      => array_keys($managers),
                'online_count'  => count($onlineIds),
                'online_ids'    => $onlineIds,
                'users_total'   => $totalUsers,
                'schedules'     => $schedCount,
                'pending'       => $pendingCount,
                'last_reason'   => $lastReason,
                'last_ts'       => $lastTs,
                'log'           => $log,
                'subscriptions' => $subscriptions,
            ];
        }

        usort($accounts, static fn($a, $b) => ($b['last_ts'] <=> $a['last_ts']));

        $out = [
            'generated_at'    => time(),
            'billing_enforce' => $enforce,
            'widgets'      => [
                'raspredelenie' => [
                    'title'    => 'Распределение',
                    'accounts' => $accounts,
                ],
                'dubli' => $this->hubWidget('dubli', 'Дубли'),
            ],
            'hub' => $this->hubMeta(),
        ];

        $response->getBody()->write(json_encode($out, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /* --------------------------- API: продление -------------------------- */

    private function jsonOut(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /** @return array<string,mixed> тело запроса (JSON или form). */
    private function body(ServerRequestInterface $request): array
    {
        $b = $request->getParsedBody();
        if (is_array($b) && $b) { return $b; }
        $raw = (string) $request->getBody();
        if ($raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) { return $d; }
        }
        return $request->getQueryParams();
    }

    /** POST /admin/api/extend  { account_id, widget, months } */
    public function extend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $b       = $this->body($request);
        $account = preg_replace('/[^0-9]/', '', (string) ($b['account_id'] ?? ''));
        $widget  = (string) ($b['widget'] ?? '');
        $months  = (int) ($b['months'] ?? 0);
        if ($account === '' || !in_array($widget, SubscriptionStore::WIDGETS, true) || $months === 0) {
            return $this->jsonOut($response, ['error' => 'bad_request', 'need' => 'account_id, widget(raspredelenie|dubli), months(!=0)'], 400);
        }
        $subs = new SubscriptionStore($this->storage);
        $res  = $subs->extend($account, $widget, $months, 'admin');
        if ($res === null) {
            return $this->jsonOut($response, ['error' => 'extend_failed'], 500);
        }
        $st = $subs->status($account, $widget);
        return $this->jsonOut($response, ['ok' => true, 'account_id' => $account, 'widget' => $widget, 'subscription' => $st + ['history' => $res['history']]]);
    }

    /** POST /admin/api/set-until  { account_id, widget, date: "YYYY-MM-DD"|"" } */
    public function setUntil(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $b       = $this->body($request);
        $account = preg_replace('/[^0-9]/', '', (string) ($b['account_id'] ?? ''));
        $widget  = (string) ($b['widget'] ?? '');
        $date    = trim((string) ($b['date'] ?? ''));
        if ($account === '' || !in_array($widget, SubscriptionStore::WIDGETS, true)) {
            return $this->jsonOut($response, ['error' => 'bad_request'], 400);
        }
        $until = null;
        if ($date !== '') {
            // конец дня по дате, чтобы «до 12.03» включал весь день
            $ts = strtotime($date . ' 23:59:59');
            if ($ts === false) {
                return $this->jsonOut($response, ['error' => 'bad_date', 'format' => 'YYYY-MM-DD'], 400);
            }
            $until = $ts;
        }
        $subs = new SubscriptionStore($this->storage);
        $res  = $subs->setUntil($account, $widget, $until, 'admin');
        if ($res === null) {
            return $this->jsonOut($response, ['error' => 'set_failed'], 500);
        }
        $st = $subs->status($account, $widget);
        return $this->jsonOut($response, ['ok' => true, 'account_id' => $account, 'widget' => $widget, 'subscription' => $st + ['history' => $res['history']]]);
    }

    /**
     * POST /admin/api/billing-link  { account_id, widget, plan_id, users, email? }
     * Создаёт платёж ЮKassa и возвращает ссылку на оплату (для отправки клиенту).
     * Пока поддержан только виджет «Распределение» (у «Дублей» свой шлюз).
     */
    public function billingLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $b       = $this->body($request);
        $account = (string) ($b['account_id'] ?? '');
        $widget  = (string) ($b['widget'] ?? BillingService::WIDGET);
        $planId  = (int) ($b['plan_id'] ?? 0);
        $users   = (int) ($b['users'] ?? 0);
        $email   = (string) ($b['email'] ?? '');

        if ($widget !== BillingService::WIDGET) {
            return $this->jsonOut($response, ['error' => 'widget_not_supported', 'hint' => 'ссылки на оплату пока только для «Распределения»'], 400);
        }

        $svc = new BillingService($this->storage);
        $res = $svc->createCheckout($account, $planId, $users, $email, 'admin');
        $status = (int) ($res['status'] ?? 200);
        unset($res['status']);
        return $this->jsonOut($response, $res, $status);
    }

    /* ---------------------- API: платежи / действия ---------------------- */

    /** GET /admin/api/payments — последние платежи «Распределения» (ЮKassa/админ) + платежи хаба (Дубли). */
    public function payments(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $rows = [];
        foreach (glob($this->storage . '/payments/*.json') ?: [] as $f) {
            $d = $this->readJson($f);
            if (!$d) { continue; }
            $rows[] = [
                'id'         => substr(basename($f, '.json'), 0, 12),
                'widget'     => (string) ($d['widget'] ?? 'raspredelenie'),
                'account_id' => (string) ($d['account_id'] ?? ''),
                'amount'     => (float) ($d['amount'] ?? 0),
                'months'     => (int) ($d['months'] ?? 0),
                'users'      => (int) ($d['users'] ?? 0),
                'status'     => (string) ($d['status'] ?? ''),
                'applied'    => (bool) ($d['applied'] ?? false),
                'source'     => (string) ($d['source'] ?? ''),
                'ts'         => (int) ($d['ts'] ?? @filemtime($f)),
            ];
        }
        $hub = $this->readJson($this->storage . '/admin/hub.json');
        foreach (($hub['payments'] ?? []) as $p) {
            $rows[] = [
                'id'         => 'hub-' . (string) ($p['id'] ?? ''),
                'widget'     => (string) ($p['product'] ?? 'dubli'),
                'account_id' => (string) ($p['account_id'] ?? ''),
                'amount'     => (float) ($p['amount'] ?? 0),
                'months'     => (int) ($p['months'] ?? 0),
                'users'      => (int) ($p['users'] ?? 0),
                'status'     => (string) ($p['status'] ?? ''),
                'applied'    => true,
                'source'     => (string) ($p['source'] ?? 'hub'),
                'ts'         => (int) ($p['ts'] ?? 0),
            ];
        }
        usort($rows, static fn($a, $b) => $b['ts'] <=> $a['ts']);
        $names = $this->subdomains();
        foreach ($rows as &$r) { $r['subdomain'] = $names[$r['account_id']] ?? ''; }
        unset($r);
        $monthStart = strtotime(date('Y-m-01 00:00:00'));
        $sumMonth = 0.0;
        foreach ($rows as $r) {
            if ($r['ts'] >= $monthStart && in_array($r['status'], ['succeeded', 'paid', 'ok', 'applied'], true)) { $sumMonth += $r['amount']; }
        }
        return $this->jsonOut($response, ['ok' => true, 'month_sum' => $sumMonth, 'payments' => array_slice($rows, 0, 100)]);
    }

    /** POST /admin/api/distribute  { account_id, lead_id } — раздать сделку по сохранённым правилам (как кнопка в виджете). */
    public function distributeLead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $b       = $this->body($request);
        $account = preg_replace('/[^0-9]/', '', (string) ($b['account_id'] ?? ''));
        $leadId  = (int) ($b['lead_id'] ?? 0);
        if ($account === '' || $leadId <= 0) {
            return $this->jsonOut($response, ['error' => 'bad_request', 'need' => 'account_id, lead_id'], 400);
        }
        try {
            $logger = new \Monolog\Logger('admin-panel');
            $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Level::Warning));
            $service = new \DealDist\Distribution\DistributionService(
                new \DealDist\AmoCRM\ApiClient($logger),
                new \DealDist\Distribution\QueueStorage(),
                new \DealDist\Distribution\ScheduleChecker(),
                new \DealDist\Distribution\StatusStorage(),
                new \DealDist\Distribution\DistributionLog(),
                $logger,
            );
            $settings = (new \DealDist\Config\SettingsStorage())->load($account);
            $result = $service->distribute([
                'account_id'          => $account,
                'lead_id'             => $leadId,
                'force'               => true,
                'rules'               => $settings['rules'] ?? [],
                'distribution_method' => $settings['distribution_method'] ?? 'round_robin',
            ]);
            if ($result === null) {
                return $this->jsonOut($response, ['ok' => false, 'status' => 'skipped', 'hint' => 'нет подходящего правила или все менеджеры недоступны']);
            }
            return $this->jsonOut($response, ['ok' => true, 'assigned_to' => $result['assigned_to_name'] ?? '', 'user_id' => $result['assigned_to_id'] ?? null]);
        } catch (\Throwable $e) {
            return $this->jsonOut($response, ['error' => 'internal_error', 'detail' => $e->getMessage()], 500);
        }
    }

    /** POST /admin/api/status  { account_id, user_id, online } — включить/выключить менеджера в распределении. */
    public function setStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $b       = $this->body($request);
        $account = preg_replace('/[^0-9]/', '', (string) ($b['account_id'] ?? ''));
        $userId  = (int) ($b['user_id'] ?? 0);
        if ($account === '' || $userId <= 0 || !array_key_exists('online', $b)) {
            return $this->jsonOut($response, ['error' => 'bad_request', 'need' => 'account_id, user_id, online'], 400);
        }
        $online = filter_var($b['online'], FILTER_VALIDATE_BOOLEAN);
        (new \DealDist\Distribution\StatusStorage())->set($account, $userId, $online, null);
        return $this->jsonOut($response, ['ok' => true, 'account_id' => $account, 'user_id' => $userId, 'online' => $online]);
    }

    /** GET /admin/api/users?account_id= — пользователи аккаунта (имена менеджеров) через bootstrap. */
    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            return $this->jsonOut($response, ['error' => 'unauthorized'], 401);
        }
        $logger = new \Monolog\Logger('admin-panel');
        $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Level::Warning));
        return (new AmoDataController($logger))->bootstrap($request, $response)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /* ------------------------- здоровье аккаунта ------------------------- */

    /**
     * Сводка «здоровья» распределения за 24 ч и список проблем для админ-панели.
     * @param array<int,array> $rules
     * @param array<string,array> $statuses
     */
    private function health(string $acc, array $rules, array $statuses, array $sub): array
    {
        $now   = time();
        $since = $now - 86400;
        $log   = $this->tailNdjson($this->storage . '/logs/' . $acc . '.ndjson', 3000);

        $stats = ['assigned' => 0, 'skipped' => 0, 'errors' => 0, 'by_reason' => []];
        $lastByLead = [];
        foreach ($log as $e) {
            $ts = (int) ($e['ts'] ?? 0);
            $reason = (string) ($e['reason'] ?? '');
            $lead = (int) ($e['lead_id'] ?? 0);
            if ($lead) { $lastByLead[$lead] = $e; }
            if ($ts < $since) { continue; }
            $stats['by_reason'][$reason] = ($stats['by_reason'][$reason] ?? 0) + 1;
            if (in_array($reason, ['assigned', 'redistributed', 'history_match'], true)) { $stats['assigned']++; }
            elseif (strncmp($reason, 'skipped', 7) === 0 || $reason === 'no_managers') { $stats['skipped']++; }
            elseif ($reason === 'error') { $stats['errors']++; }
        }

        // нераспределённые за 48 ч: последняя запись по сделке — skipped/error
        $skipped = [];
        foreach ($lastByLead as $lead => $e) {
            $ts = (int) ($e['ts'] ?? 0);
            $reason = (string) ($e['reason'] ?? '');
            if ($ts >= $now - 172800 && (strncmp($reason, 'skipped', 7) === 0 || $reason === 'error' || $reason === 'no_managers')
                && $reason !== 'skipped_duplicate' && $reason !== 'skipped_no_rule') {
                $skipped[] = ['lead_id' => $lead, 'reason' => $reason, 'ts' => $ts];
            }
        }
        usort($skipped, static fn($a, $b) => $b['ts'] <=> $a['ts']);

        // менеджеры правил и их статус
        $ruleManagers = [];
        $modes = [];
        $checkSchedule = false;
        foreach ($rules as $r) {
            $modes[] = (string) ($r['offshift_mode'] ?? 'skip');
            if (!empty($r['check_schedule'])) { $checkSchedule = true; }
            foreach (($r['managers'] ?? []) as $m) {
                $id = (string) (is_array($m) ? ($m['id'] ?? '') : $m);
                if ($id !== '') { $ruleManagers[$id] = true; }
            }
        }
        $offline = [];
        foreach (array_keys($ruleManagers) as $id) {
            if (isset($statuses[$id]) && empty($statuses[$id]['online'])) { $offline[] = (string) $id; }
        }

        // месячный график на текущий месяц
        $monthFile = $this->storage . '/schedules_month/' . $acc . '/' . date('Y-m') . '.json';
        $month = $this->readJson($monthFile);
        $monthFilled = is_array($month) && !empty($month['grid'] ?? $month);

        $issues = [];
        if ($rules === []) { $issues[] = ['level' => 'warn', 'text' => 'Нет правил распределения']; }
        if ($ruleManagers && count($offline) === count($ruleManagers)) {
            $issues[] = ['level' => 'error', 'text' => 'Все менеджеры правил выключены'];
        } elseif ($offline) {
            $issues[] = ['level' => 'warn', 'text' => 'Выключены менеджеры правил: ' . count($offline)];
        }
        if ($stats['errors'] > 0) { $issues[] = ['level' => 'error', 'text' => 'Ошибки за 24 ч: ' . $stats['errors']]; }
        if (count($skipped) > 0) { $issues[] = ['level' => 'warn', 'text' => 'Нераспределённых сделок за 48 ч: ' . count($skipped)]; }
        if ($checkSchedule && in_array('skip', $modes, true)) {
            $issues[] = ['level' => 'info', 'text' => 'Вне смены сделки пропускаются (режим «пропустить»)'];
        }
        $days = $sub['days_left'] ?? null;
        $subStatus = (string) ($sub['status'] ?? '');
        if ($subStatus === 'expired') {
            $issues[] = ['level' => 'error', 'text' => 'Подписка истекла'];
        } elseif ($subStatus === 'soon' || (is_int($days) && $days <= 7)) {
            $issues[] = ['level' => 'warn', 'text' => 'Подписка заканчивается через ' . (int) $days . ' дн.'];
        } elseif ($subStatus === 'none') {
            $issues[] = ['level' => 'info', 'text' => 'Срок подписки не задан'];
        }
        $lastAssigned = 0;
        foreach (array_reverse($log) as $e) {
            if (in_array((string) ($e['reason'] ?? ''), ['assigned', 'redistributed', 'history_match'], true)) { $lastAssigned = (int) $e['ts']; break; }
        }

        return [
            'stats24h'       => $stats,
            'skipped'        => array_slice($skipped, 0, 50),
            'rule_managers'  => array_keys($ruleManagers),
            'offline'        => $offline,
            'offshift_modes' => array_values(array_unique($modes)),
            'check_schedule' => $checkSchedule,
            'month_filled'   => $monthFilled,
            'last_assigned'  => $lastAssigned,
            'issues'         => $issues,
            'level'          => $this->worstLevel($issues),
        ];
    }

    private function worstLevel(array $issues): string
    {
        $rank = ['ok' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
        $w = 'ok';
        foreach ($issues as $i) {
            $l = (string) ($i['level'] ?? 'ok');
            if (($rank[$l] ?? 0) > $rank[$w]) { $w = $l; }
        }
        return $w;
    }

    /* ------------------------- данные хаба (Дубли) ------------------------ */

    /** @return array<string,string> account_id => subdomain (из выгрузки хаба). */
    private function subdomains(): array
    {
        $hub = $this->readJson($this->storage . '/admin/hub.json');
        $out = [];
        foreach (($hub['accounts'] ?? []) as $a) {
            $out[(string) ($a['account_id'] ?? '')] = (string) ($a['subdomain'] ?? '');
        }
        return $out;
    }

    private function hubMeta(): array
    {
        $hub = $this->readJson($this->storage . '/admin/hub.json');
        return [
            'available'    => is_array($hub),
            'generated_at' => (int) ($hub['generated_at'] ?? 0),
            'subdomains'   => $this->subdomains(),
        ];
    }

    private function hubWidget(string $product, string $title): array
    {
        $hub = $this->readJson($this->storage . '/admin/hub.json');
        if (!is_array($hub)) {
            return ['title' => $title, 'available' => false, 'note' => 'Нет выгрузки хаба (cron /opt/hub-sync/export-admin.py).', 'accounts' => []];
        }
        $names = $this->subdomains();
        $accounts = [];
        foreach (($hub['subscriptions'] ?? []) as $s) {
            if ((string) ($s['product'] ?? '') !== $product) { continue; }
            $paid = isset($s['paid_till']) && $s['paid_till'] ? (int) $s['paid_till'] : null;
            $accounts[] = [
                'account_id' => (string) $s['account_id'],
                'subdomain'  => $names[(string) $s['account_id']] ?? '',
                'status'     => (string) ($s['status'] ?? ''),
                'paid_until' => $paid,
                'days_left'  => $paid ? (int) floor(($paid - time()) / 86400) : null,
                'users'      => (int) ($s['users'] ?? 0),
            ];
        }
        return ['title' => $title, 'available' => true, 'generated_at' => (int) ($hub['generated_at'] ?? 0), 'accounts' => $accounts];
    }

    /* ----------------------------- helpers ------------------------------ */

    /** @return string[] уникальные account_id из всех подпапок storage */
    private function accountIds(): array
    {
        $ids = [];
        foreach (['settings', 'statuses', 'tokens', 'logs', 'pending'] as $dir) {
            foreach (glob($this->storage . '/' . $dir . '/*') ?: [] as $p) {
                $b = basename($p);
                $b = preg_replace('/\.(json|ndjson)$/', '', $b);
                $b = preg_replace('/\.history$/', '', (string) $b);
                if (preg_match('/^\d+$/', (string) $b)) { $ids[$b] = true; }
            }
        }
        foreach (glob($this->storage . '/schedules/*', GLOB_ONLYDIR) ?: [] as $p) {
            $b = basename($p);
            if (preg_match('/^\d+$/', $b)) { $ids[$b] = true; }
        }
        // долгосрочный аккаунт из env всегда показываем
        $lt = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        if ($lt !== '' && preg_match('/^\d+$/', $lt)) { $ids[$lt] = true; }
        return array_keys($ids);
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) { return null; }
        $d = json_decode((string) file_get_contents($file), true);
        return is_array($d) ? $d : null;
    }

    /** @return array<int,array> */
    private function tailNdjson(string $file, int $limit): array
    {
        if (!is_file($file)) { return []; }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach ($lines as $l) {
            $d = json_decode($l, true);
            if (is_array($d)) { $out[] = $d; }
        }
        return $out;
    }

    private function jwtExp(string $jwt): ?int
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) { return null; }
        $payload = json_decode($this->b64url($parts[1]), true);
        return is_array($payload) && isset($payload['exp']) ? (int) $payload['exp'] : null;
    }

    private function b64url(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) { $s .= str_repeat('=', 4 - $pad); }
        return (string) base64_decode($s);
    }

    /* ------------------------------- page ------------------------------- */

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            $response->getBody()->write($this->loginHtml());
            return $response->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        $response->getBody()->write($this->appHtml());
        return $response->withStatus(200)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function loginHtml(): string
    {
        return <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KO:AGENCY — Админка</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#ececec;
  font-family:'IBM Plex Sans',Arial,sans-serif;letter-spacing:-0.02em;color:#141414;padding:24px}
.box{width:400px;max-width:100%;background:#fff;border:1.5px solid #141414;border-radius:12px;padding:40px 40px 36px}
.brand{font-family:'IBM Plex Mono',monospace;font-size:22px;font-weight:600;letter-spacing:0.02em}
.eyebrow{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:500;letter-spacing:0.08em;
  text-transform:uppercase;color:#8a8a8a;margin-top:8px}
.divider{height:1.5px;background:#141414;margin:22px 0 24px}
label{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:600;letter-spacing:0.06em;
  text-transform:uppercase;color:#141414;display:block;margin-bottom:8px}
input{width:100%;padding:12px 14px;border:1.5px solid #cfcfcf;border-radius:8px;font-size:15px;
  font-family:'IBM Plex Sans',sans-serif;color:#141414;outline:none;transition:border-color .15s}
input:focus{border-color:#141414}
button{width:100%;margin-top:16px;padding:13px;border:0;border-radius:8px;background:#d22730;color:#fff;
  font-size:13px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;
  font-family:'IBM Plex Mono',monospace;cursor:pointer;transition:background .15s}
button:hover{background:#b91f28}
</style></head>
<body>
<form class="box" method="get" action="/admin">
  <div class="brand">KO&nbsp;:&nbsp;AGENCY</div>
  <div class="eyebrow">Админка виджетов · доступ по ключу</div>
  <div class="divider"></div>
  <label for="key">Ключ доступа</label>
  <input id="key" name="key" type="password" placeholder="••••••••••••" autofocus autocomplete="off">
  <button type="submit">Войти</button>
</form>
</body></html>
HTML;
    }

    private function appHtml(): string
    {
        // Страница читает ключ из ?key= и дергает /admin/api/overview.
        return <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KO:AGENCY — Админка виджетов</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#141414;--red:#d22730;--red-dark:#b91f28;--muted:#8a8a8a;--line:#141414;--soft:#dcdcdc;--page:#ececec;--card:#fff}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font-family:'IBM Plex Sans',Arial,sans-serif;letter-spacing:-0.02em;font-size:14px;line-height:1.5}
.mono{font-family:'IBM Plex Mono',monospace;letter-spacing:0}
.wrap{max-width:1200px;margin:0 auto;padding:36px 28px 72px}
/* header */
.top{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;border-bottom:1.5px solid var(--line);padding-bottom:18px}
.brand{font-family:'IBM Plex Mono',monospace;font-size:22px;font-weight:600;letter-spacing:0.02em}
.eyebrow{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:500;letter-spacing:0.07em;text-transform:uppercase;color:var(--muted);margin-top:6px}
.top-r{display:flex;align-items:center;gap:16px}
.gen{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:0.04em;color:var(--muted);text-transform:uppercase;text-align:right}
.refresh{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;padding:9px 16px;border:1.5px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;color:var(--ink);transition:.15s}
.refresh:hover{background:var(--ink);color:#fff}
/* tabs */
.tabs{display:flex;gap:28px;margin:28px 0 22px}
.tab{appearance:none;border:0;background:none;padding:0 0 10px;font-family:'IBM Plex Mono',monospace;font-size:13px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent}
.tab.on{color:var(--ink);border-bottom-color:var(--red)}
.tab:hover{color:var(--ink)}
.tab .n{color:var(--red);margin-left:6px}
/* kpis */
.kpis{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:26px}
.kpi{background:var(--card);border:1.5px solid var(--line);border-radius:8px;padding:16px 20px;min-width:150px;flex:1}
.kpi b{display:block;font-family:'IBM Plex Mono',monospace;font-size:34px;font-weight:600;line-height:1;letter-spacing:-0.03em}
.kpi.red b{color:var(--red)}
.kpi span{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;color:var(--muted);margin-top:10px;display:block}
/* card */
.card{background:var(--card);border:1.5px solid var(--line);border-radius:8px;padding:22px 24px;margin-bottom:16px}
.card-h{display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap}
.card-h h3{margin:0;font-size:21px;font-weight:600;letter-spacing:-0.03em}
.card-h .idx{font-family:'IBM Plex Mono',monospace;font-size:12px;font-weight:600;color:var(--red);letter-spacing:0.04em}
.dom{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:0.03em;text-transform:uppercase;color:var(--muted);margin-top:8px}
.row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px}
.pill{display:inline-flex;align-items:center;gap:7px;border:1.5px solid var(--line);border-radius:20px;padding:5px 13px;font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase}
.pill .dot{width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block}
.pill.red{border-color:var(--red);color:var(--red)}
.pill.mut{border-color:var(--soft);color:var(--muted)}
.pill.solid{background:var(--red);border-color:var(--red);color:#fff}
/* table */
details{margin-top:16px}
details summary{cursor:pointer;font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--ink);list-style:none;display:inline-flex;align-items:center;gap:8px}
details summary::-webkit-details-marker{display:none}
details summary::before{content:'+';color:var(--red);font-weight:600}
details[open] summary::before{content:'–'}
table{width:100%;border-collapse:collapse;margin-top:14px;font-size:12.5px}
th{font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--muted);text-align:left;padding:6px 10px;border-bottom:1.5px solid var(--line)}
td{text-align:left;padding:8px 10px;border-bottom:1px solid #ededed}
td.mono{font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--muted)}
.rsn{font-weight:600;display:inline-flex;align-items:center;gap:6px}
.rsn::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.rsn.ok{color:var(--ink)} .rsn.ok::before{background:var(--red)}
.rsn.skip{color:var(--muted)}
.rsn.err{color:var(--red)}
.muted{color:var(--muted)}
.empty{color:var(--muted);padding:16px 2px;font-family:'IBM Plex Mono',monospace;font-size:12px;letter-spacing:0.03em}
.logempty{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:0.04em;text-transform:uppercase;color:var(--muted);margin-top:14px}
.note{background:var(--card);border:1.5px dashed var(--soft);border-radius:8px;padding:22px 24px;color:var(--ink)}
.note .lbl{display:inline-block;font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:600;letter-spacing:0.07em;text-transform:uppercase;color:var(--red);border:1.5px solid var(--red);border-radius:6px;padding:4px 9px;margin-bottom:12px}
.note b{font-weight:600}
.err{border:1.5px solid var(--red);color:var(--red);padding:14px 16px;border-radius:8px;font-size:13px}
/* subscriptions */
.subs{margin-top:18px;border-top:1.5px solid #ededed;padding-top:16px;display:flex;flex-direction:column;gap:14px}
.sub{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px}
.sub-name{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;min-width:118px}
.sub-until{font-size:13px;color:var(--ink)}
.sub-until b{font-weight:600}
.sub-badge{font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;border:1.5px solid;border-radius:6px;padding:3px 9px}
.sub-badge.active{border-color:#1f9d57;color:#1f9d57}
.sub-badge.soon{border-color:#c98a00;color:#c98a00}
.sub-badge.expired{border-color:var(--red);color:var(--red)}
.sub-badge.none{border-color:var(--soft);color:var(--muted)}
.ext{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto;align-items:center}
.ext button{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:0.03em;padding:6px 11px;border:1.5px solid var(--line);border-radius:7px;background:#fff;color:var(--ink);cursor:pointer;transition:.12s}
.ext button:hover{background:var(--ink);color:#fff}
.ext button.primary{border-color:var(--red);color:var(--red)}
.ext button.primary:hover{background:var(--red);color:#fff}
.ext input[type=date]{font-family:'IBM Plex Mono',monospace;font-size:12px;padding:5px 8px;border:1.5px solid var(--soft);border-radius:7px;color:var(--ink)}
.sub-hist{font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--muted);letter-spacing:0.02em;flex-basis:100%;margin-top:2px}
.enforce{font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:0.04em;text-transform:uppercase;padding:4px 10px;border-radius:6px;border:1.5px solid var(--soft);color:var(--muted)}
.enforce.on{border-color:var(--red);color:var(--red)}
.toast{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:var(--ink);color:#fff;font-size:13px;padding:10px 18px;border-radius:8px;opacity:0;transition:opacity .2s;pointer-events:none;z-index:9}
.toast.show{opacity:1}
/* billing link row */
.linkrow{border-top:1px dashed var(--soft);padding-top:14px;margin-top:2px}
.lk-form{margin-left:auto;flex-wrap:wrap}
.lk-lbl{font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--muted);letter-spacing:0.02em}
.lk-sel,.lk-num,.lk-email{font-family:'IBM Plex Mono',monospace;font-size:12px;padding:5px 8px;border:1.5px solid var(--soft);border-radius:7px;color:var(--ink);background:#fff}
.lk-num{width:64px}
.lk-email{width:170px}
.lk-out{flex-basis:100%;margin-top:6px;font-size:12px;word-break:break-all}
.lk-url{font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--ink)}
.lk-copy{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:600;padding:3px 9px;border:1.5px solid var(--line);border-radius:6px;background:#fff;color:var(--ink);cursor:pointer}
.lk-copy:hover{background:var(--ink);color:#fff}
.lk-out.err-txt{color:var(--red)}
</style></head>
<body><div class="wrap">
<div class="top">
  <div><div class="brand">KO&nbsp;:&nbsp;AGENCY</div><div class="eyebrow">Админка виджетов · панель управления</div></div>
  <div class="top-r"><span class="enforce" id="enforce"></span><div class="gen" id="gen"></div><button class="refresh" id="refresh">↻ Обновить</button></div>
</div>
<div class="tabs">
  <button class="tab on" data-t="raspredelenie">Распределение<span class="n" id="tabn"></span></button>
  <button class="tab" data-t="dubli">Дубли</button>
</div>
<div id="content"><div class="empty">Загрузка…</div></div>
</div>
<div class="toast" id="toast"></div>
<script>
var KEY=new URLSearchParams(location.search).get('key')||'';
var DATA=null, TAB='raspredelenie';
function fmtTs(t){ if(!t) return '—'; try{var d=new Date(t*1000);return d.toLocaleString('ru-RU');}catch(e){return String(t);} }
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
var RSN={assigned:'Назначена',redistributed:'Перераспределена',skipped_no_rule:'Нет правила',skipped_offline:'Все офлайн',skipped_schedule:'Вне расписания',history_match:'По истории',error:'Ошибка',no_managers:'Нет менеджеров'};
function tokenPill(t){
  if(!t.present) return '<span class="pill red"><span class="dot"></span>токен: нет</span>';
  var kind = t.kind==='oauth'?'OAuth':(t.kind==='long_term'?'долгосрочный':(t.kind==='env_long_term'?'env':'—'));
  var exp = t.exp? ' · до '+new Date(t.exp*1000).toLocaleDateString('ru-RU') : '';
  return '<span class="pill"><span class="dot"></span>токен '+kind+exp+'</span>';
}
function rulePill(a){ return a.rules_count? '<span class="pill">правил '+a.rules_count+' · менеджеров '+a.managers_count+'</span>' : '<span class="pill red">правил нет</span>'; }
function onlinePill(a){ return '<span class="pill '+(a.online_count?'solid':'mut')+'">онлайн '+a.online_count+'/'+a.users_total+'</span>'; }
function reasonCls(r){ r=r||''; if(r==='error') return 'rsn err'; if(r.indexOf('skip')===0||r==='no_managers') return 'rsn skip'; return 'rsn ok'; }
var WT={raspredelenie:'Распределение',dubli:'Дубли'};
var SUBST={active:'активна',soon:'истекает',expired:'истекла',none:'не задана'};
function fmtDate(t){ if(!t) return '—'; try{return new Date(t*1000).toLocaleDateString('ru-RU');}catch(e){return String(t);} }
function toast(msg){ var t=document.getElementById('toast'); if(!t)return; t.textContent=msg; t.classList.add('show'); clearTimeout(t._h); t._h=setTimeout(function(){t.classList.remove('show');},2600); }
function subRow(acc,wk,s){
  s=s||{status:'none',paid_until:null,days_left:null,history:[]};
  var until = s.paid_until? ('оплачено до <b>'+fmtDate(s.paid_until)+'</b>') : 'срок не задан';
  var left  = (s.status==='active'||s.status==='soon')&&s.days_left!=null? ' · осталось '+s.days_left+' дн'
            : (s.status==='expired'&&s.days_left!=null? ' · истекла '+Math.abs(s.days_left)+' дн назад' : '');
  var h = (s.history&&s.history.length)? s.history.slice(-1)[0] : null;
  var hist = h? ('послед.: '+(h.action==='extend'?('+'+h.months+' мес'):'установлено')+' → '+fmtDate(h.until)+(h.ts?(' ('+new Date(h.ts*1000).toLocaleDateString('ru-RU')+')'):'')) : '';
  var did='d_'+acc+'_'+wk;
  return '<div class="sub">'
    +'<span class="sub-name">'+esc(WT[wk]||wk)+'</span>'
    +'<span class="sub-badge '+s.status+'">'+SUBST[s.status]+'</span>'
    +'<span class="sub-until">'+until+left+'</span>'
    +'<span class="ext">'
      +'<button onclick="doExt(\''+acc+'\',\''+wk+'\',1)">+1 мес</button>'
      +'<button onclick="doExt(\''+acc+'\',\''+wk+'\',3)">+3</button>'
      +'<button onclick="doExt(\''+acc+'\',\''+wk+'\',6)">+6</button>'
      +'<button class="primary" onclick="doExt(\''+acc+'\',\''+wk+'\',12)">+12</button>'
      +'<input type="date" id="'+did+'">'
      +'<button onclick="doSet(\''+acc+'\',\''+wk+'\',\''+did+'\')">Задать</button>'
    +'</span>'
    +(hist?('<span class="sub-hist">'+esc(hist)+'</span>'):'')
    +'</div>';
}
function linkRow(a){
  var acc=a.account_id, u=(a.users_total||5);
  return '<div class="sub linkrow">'
    +'<span class="sub-name">Ссылка на оплату</span>'
    +'<span class="ext lk-form">'
      +'<select id="p_'+acc+'" class="lk-sel"><option value="6">6 мес</option><option value="12" selected>12 мес · 2 в подарок</option></select>'
      +'<span class="lk-lbl">польз.</span><input type="number" id="u_'+acc+'" min="1" value="'+u+'" class="lk-num">'
      +'<input type="email" id="e_'+acc+'" placeholder="email для чека" class="lk-email">'
      +'<button class="primary" onclick="doLink(\''+acc+'\')">Создать ссылку</button>'
    +'</span>'
    +'<span class="lk-out" id="o_'+acc+'"></span>'
  +'</div>';
}
function subsBlock(a){
  var s=a.subscriptions||{};
  return '<div class="subs">'
    +subRow(a.account_id,'raspredelenie',s.raspredelenie)
    +linkRow(a)
    +subRow(a.account_id,'dubli',s.dubli)
    +'</div>';
}
function doLink(acc){
  var plan=+(document.getElementById('p_'+acc)||{}).value||12;
  var users=+(document.getElementById('u_'+acc)||{}).value||0;
  var email=(document.getElementById('e_'+acc)||{}).value||'';
  var out=document.getElementById('o_'+acc); if(out){ out.textContent='создаю ссылку…'; out.className='lk-out'; }
  post('/admin/api/billing-link',{account_id:acc,widget:'raspredelenie',plan_id:plan,users:users,email:email}).then(function(j){
    if(!out) return;
    out.innerHTML='';
    var url=j.confirmation_url;
    var a=document.createElement('a'); a.href=url; a.target='_blank'; a.rel='noopener'; a.textContent=url; a.className='lk-url';
    var cp=document.createElement('button'); cp.type='button'; cp.className='lk-copy'; cp.textContent='копировать';
    cp.onclick=function(){ navigator.clipboard.writeText(url).then(function(){cp.textContent='скопировано';setTimeout(function(){cp.textContent='копировать';},1500);}); };
    var meta=document.createElement('span'); meta.className='muted'; meta.textContent=' · '+j.amount+' ₽ · '+j.months+' мес · '+j.users+' польз.';
    out.appendChild(a); out.appendChild(document.createTextNode(' ')); out.appendChild(cp); out.appendChild(meta);
    toast('Ссылка создана: '+j.amount+' ₽');
  }).catch(function(e){ if(out){out.textContent='Ошибка: '+e.message; out.className='lk-out err-txt';} toast('Ошибка: '+e.message); });
}
function post(url,body){
  return fetch(url+'?key='+encodeURIComponent(KEY),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(function(r){ return r.json().then(function(j){ if(!r.ok||j.error) throw new Error(j.error||('HTTP '+r.status)); return j; }); });
}
function doExt(acc,wk,months){
  post('/admin/api/extend',{account_id:acc,widget:wk,months:months}).then(function(j){
    toast(WT[wk]+' · продлено на '+months+' мес → '+fmtDate(j.subscription.paid_until)); load();
  }).catch(function(e){ toast('Ошибка: '+e.message); });
}
function doSet(acc,wk,inputId){
  var el=document.getElementById(inputId); var d=el?el.value:'';
  post('/admin/api/set-until',{account_id:acc,widget:wk,date:d}).then(function(j){
    toast(WT[wk]+' · срок '+(d?('→ '+fmtDate(j.subscription.paid_until)):'снят')); load();
  }).catch(function(e){ toast('Ошибка: '+e.message); });
}
function render(){
  var c=document.getElementById('content');
  if(!DATA){ c.innerHTML='<div class="empty">Загрузка…</div>'; return; }
  var g=document.getElementById('gen'); if(g&&DATA.generated_at){ g.textContent='обновлено '+new Date(DATA.generated_at*1000).toLocaleTimeString('ru-RU'); }
  var en=document.getElementById('enforce'); if(en){ var on=!!DATA.billing_enforce; en.textContent=on?'блокировка: вкл':'блокировка: выкл (учёт)'; en.className='enforce'+(on?' on':''); en.title=on?'Виджет распределения останавливается по истечении срока':'Срок только фиксируется, распределение не блокируется'; }
  var w=DATA.widgets[TAB];
  if(TAB==='dubli'){ c.innerHTML='<div class="note"><span class="lbl">Скоро</span><br><b>Дубли</b> — раздел подключается. '+esc(w.note||'')+'</div>'; return; }
  var accs=w.accounts||[];
  var tn=document.getElementById('tabn'); if(tn){ tn.textContent=accs.length; }
  var tokOk=accs.filter(function(a){return a.token.present;}).length;
  var noRule=accs.filter(function(a){return !a.rules_count;}).length;
  var onl=accs.reduce(function(s,a){return s+a.online_count;},0);
  var html='<div class="kpis">'
    +'<div class="kpi"><b>'+accs.length+'</b><span>аккаунтов</span></div>'
    +'<div class="kpi"><b>'+tokOk+'</b><span>с токеном</span></div>'
    +'<div class="kpi"><b>'+noRule+'</b><span>без правила</span></div>'
    +'<div class="kpi red"><b>'+onl+'</b><span>онлайн сейчас</span></div>'
    +'</div>';
  if(!accs.length){ html+='<div class="empty">Нет аккаунтов.</div>'; c.innerHTML=html; return; }
  accs.forEach(function(a,i){
    var idx=('0'+(i+1)).slice(-2);
    html+='<div class="card"><div class="card-h"><h3>'+esc(a.domain||('account '+a.account_id))+'</h3><span class="idx">'+idx+'</span></div>'
      +'<div class="dom">id '+esc(a.account_id)+' · метод '+esc(a.method)+' · расписаний '+a.schedules+(a.pending?(' · повтор в очереди '+a.pending):'')+'</div>'
      +'<div class="row">'+tokenPill(a.token)+rulePill(a)+onlinePill(a)+'</div>';
    html+=subsBlock(a);
    if(a.log && a.log.length){
      html+='<details><summary>Лог распределения · '+a.log.length+'</summary><table><tr><th>Время</th><th>Сделка</th><th>Этап</th><th>Менеджер</th><th>Причина</th></tr>';
      a.log.slice().reverse().forEach(function(e){
        html+='<tr><td class="mono">'+fmtTs(e.ts)+'</td><td>'+esc(e.lead_id||'—')+'</td><td class="mono">'+esc(e.stage_id||'—')+'</td><td>'+esc(e.manager_id||'—')+'</td><td><span class="'+reasonCls(e.reason)+'">'+esc(RSN[e.reason]||e.reason||'—')+'</span></td></tr>';
      });
      html+='</table></details>';
    } else { html+='<div class="logempty">Лог пуст</div>'; }
    html+='</div>';
  });
  c.innerHTML=html;
}
function load(){
  var c=document.getElementById('content'); c.innerHTML='<div class="empty">Загрузка…</div>';
  fetch('/admin/api/overview?key='+encodeURIComponent(KEY)).then(function(r){
    if(!r.ok) throw new Error('HTTP '+r.status); return r.json();
  }).then(function(j){ DATA=j; render(); }).catch(function(e){
    c.innerHTML='<div class="err">Ошибка загрузки: '+esc(e.message)+'. Проверьте ключ доступа.</div>';
  });
}
document.querySelectorAll('.tab').forEach(function(t){ t.addEventListener('click',function(){
  document.querySelectorAll('.tab').forEach(function(x){x.classList.remove('on');}); t.classList.add('on'); TAB=t.dataset.t; render();
});});
document.getElementById('refresh').addEventListener('click',load);
load();
</script></body></html>
HTML;
    }
}
