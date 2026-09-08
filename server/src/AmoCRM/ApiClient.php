<?php

declare(strict_types=1);

namespace DealDist\AmoCRM;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

/**
 * Thin wrapper around the AmoCRM REST API v4.
 *
 * Each account has its own access/refresh tokens stored in the filesystem
 * (STORAGE_PATH/tokens/{accountId}.json). The client automatically refreshes
 * the access token when it receives a 401 response.
 */
class ApiClient
{
    private const API_VERSION = 'v4';
    private Client $http;
    private string $storagePath;

    public function __construct(
        private readonly Logger $logger,
    ) {
        $this->storagePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/');
        $this->http        = new Client(['timeout' => 15, 'connect_timeout' => 5]);
    }

    // ── Token management ─────────────────────────────────────────────────────

    public function saveTokens(string $accountId, string $baseDomain, array $tokens): void
    {
        $dir = $this->storagePath . '/tokens';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tokens['base_domain']  = $baseDomain;
        $tokens['saved_at']     = time();

        file_put_contents(
            $dir . '/' . $accountId . '.json',
            json_encode($tokens, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    public function loadTokens(string $accountId): ?array
    {
        // Долгосрочный токен доступа из .env (§4.1): бэкенд работает без OAuth-
        // установки. Значение задаётся ТОЛЬКО в .env на сервере, не в git.
        // Если AMO_ACCOUNT_ID пуст — токен применяется к любому аккаунту
        // (режим одного аккаунта), иначе только к совпавшему.
        $longTerm  = trim((string) ($_ENV['AMO_LONG_TERM_TOKEN'] ?? ''));
        $ltAccount = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        if ($longTerm !== '' && ($ltAccount === '' || $ltAccount === (string) $accountId)) {
            return [
                'access_token' => $longTerm,
                'base_domain'  => trim((string) ($_ENV['AMO_BASE_DOMAIN'] ?? 'amocrm.ru')),
                'long_term'    => true,
            ];
        }

        $file = $this->storagePath . '/tokens/' . $accountId . '.json';
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function refreshTokens(string $accountId, array $tokens): array
    {
        $response = $this->http->post('https://' . $tokens['base_domain'] . '/oauth2/access_token', [
            'json' => [
                'client_id'     => $_ENV['AMO_CLIENT_ID'],
                'client_secret' => $_ENV['AMO_CLIENT_SECRET'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $tokens['refresh_token'],
                'redirect_uri'  => $_ENV['AMO_REDIRECT_URI'],
            ],
        ]);

        $new = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->saveTokens($accountId, $tokens['base_domain'], $new);
        $this->logger->info('Refreshed tokens', ['account_id' => $accountId]);
        return $new;
    }

    // ── Generic request helper ────────────────────────────────────────────────

    private function request(string $accountId, string $method, string $path, array $options = []): array
    {
        $tokens = $this->loadTokens($accountId);
        if (!$tokens) {
            throw new \RuntimeException("No tokens for account $accountId");
        }

        $url = 'https://' . $tokens['base_domain'] . '/api/' . self::API_VERSION . $path;
        $options['headers']['Authorization'] = 'Bearer ' . $tokens['access_token'];

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 401) {
                // Долгосрочный токен обновить нельзя — даём понятную ошибку (§6.4).
                if (!empty($tokens['long_term'])) {
                    $this->logger->error('Долгосрочный токен отклонён (401)', [
                        'account_id' => $accountId,
                        'hint'       => 'Проверьте AMO_LONG_TERM_TOKEN / AMO_ACCOUNT_ID в .env',
                    ]);
                    throw new \RuntimeException('amoCRM 401: долгосрочный токен недействителен или не для этого аккаунта');
                }
                // OAuth-токен истёк — обновляем и повторяем один раз
                $tokens = $this->refreshTokens($accountId, $tokens);
                $options['headers']['Authorization'] = 'Bearer ' . $tokens['access_token'];
                $response = $this->http->request($method, $url, $options);
            } else {
                throw $e;
            }
        }

        $body = (string) $response->getBody();
        return $body ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    // ── Lead ──────────────────────────────────────────────────────────────────

    public function getLead(string $accountId, int $leadId, array $with = []): array
    {
        $query = $with ? '?with=' . implode(',', $with) : '';
        $data  = $this->request($accountId, 'GET', "/leads/$leadId$query");
        return $data;
    }

    public function updateLeadResponsible(string $accountId, int $leadId, int $responsibleUserId): void
    {
        $this->request($accountId, 'PATCH', '/leads', [
            'json' => [
                [
                    'id'                  => $leadId,
                    'responsible_user_id' => $responsibleUserId,
                ],
            ],
        ]);
        $this->logger->info('Lead responsible updated', [
            'account_id'   => $accountId,
            'lead_id'      => $leadId,
            'new_user_id'  => $responsibleUserId,
        ]);
    }

    // ── Contacts / Companies ──────────────────────────────────────────────────

    /**
     * Returns the responsible_user_id from any deal related to the same
     * contact or company, if one exists.
     *
     * @param array|null $leadData  Already-loaded lead data (avoids an extra API call)
     */
    /**
     * История клиента: кому раньше отдавали сделки этого контакта/компании.
     *
     * Возвращает кандидатов (responsible_user_id) в порядке приоритета:
     *   1) ответственные по ДРУГИМ сделкам контакта/компании — от самой свежей к старой
     *      (по updated_at); 2) ответственный самого контакта/компании.
     * Пустой массив — история не найдена.
     *
     * ВАЖНО: `/contacts/{id}?with=leads` отдаёт в _embedded только id сделок
     * (без responsible_user_id), поэтому сделки дочитываем отдельным запросом
     * `/leads?filter[id][]=…` (старый код брал responsible из embed и всегда
     * получал 0 — история фактически не работала).
     */
    public function getHistoryCandidates(string $accountId, int $leadId, ?array $leadData = null, int $maxLeads = 50): array
    {
        $lead = $leadData ?? $this->getLead($accountId, $leadId, ['contacts', 'companies']);
        $contactIds = array_map('intval', array_column($lead['_embedded']['contacts'] ?? [], 'id'));
        $companyIds = array_map('intval', array_column($lead['_embedded']['companies'] ?? [], 'id'));

        $otherLeadIds   = [];
        $entityFallback = [];   // ответственные самих контактов/компаний

        foreach ($contactIds as $cid) {
            try {
                $c = $this->request($accountId, 'GET', "/contacts/$cid?with=leads");
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($c['_embedded']['leads'] ?? [] as $l) {
                if ((int) ($l['id'] ?? 0) !== $leadId) {
                    $otherLeadIds[] = (int) $l['id'];
                }
            }
            // Ответственный самого контакта — только если контакт «старый»
            // (создан заметно раньше сделки). Контакт, созданный вместе со
            // сделкой из чата/бота, получает ответственного по настройкам
            // источника — это не история клиента (кейс 49363807: контакт и
            // сделка созданы в одну минуту, responsible=Катя, других сделок нет).
            if (!empty($c['responsible_user_id']) && $this->isOldEntity($c, $lead)) {
                $entityFallback[] = (int) $c['responsible_user_id'];
            }
        }
        foreach ($companyIds as $coid) {
            try {
                $c = $this->request($accountId, 'GET', "/companies/$coid?with=leads");
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($c['_embedded']['leads'] ?? [] as $l) {
                if ((int) ($l['id'] ?? 0) !== $leadId) {
                    $otherLeadIds[] = (int) $l['id'];
                }
            }
            if (!empty($c['responsible_user_id']) && $this->isOldEntity($c, $lead)) {
                $entityFallback[] = (int) $c['responsible_user_id'];
            }
        }

        $candidates = [];
        $otherLeadIds = array_values(array_unique($otherLeadIds));
        if ($otherLeadIds) {
            $leads = [];
            foreach (array_chunk(array_slice($otherLeadIds, 0, $maxLeads), 25) as $chunk) {
                $q = implode('&', array_map(fn($id) => 'filter[id][]=' . $id, $chunk));
                try {
                    $d = $this->request($accountId, 'GET', "/leads?limit=250&$q");
                } catch (\Throwable $e) {
                    continue;
                }
                foreach ($d['_embedded']['leads'] ?? [] as $l) {
                    $leads[] = $l;
                }
            }
            usort($leads, fn($a, $b) => (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0));
            foreach ($leads as $l) {
                $rid = (int) ($l['responsible_user_id'] ?? 0);
                if ($rid > 0) {
                    $candidates[] = $rid;
                }
            }
        }
        foreach ($entityFallback as $rid) {
            $candidates[] = $rid;
        }
        return array_values(array_unique($candidates));
    }

    /** Контакт/компания созданы минимум за час до сделки → это реальная история, а не артефакт создания. */
    private function isOldEntity(array $entity, array $lead): bool
    {
        $ec = (int) ($entity['created_at'] ?? 0);
        $lc = (int) ($lead['created_at'] ?? 0);
        return $ec > 0 && $lc > 0 && ($lc - $ec) >= 3600;
    }

    /** @deprecated используйте getHistoryCandidates(); оставлено для совместимости */
    public function getExistingResponsible(string $accountId, int $leadId, ?array $leadData = null): ?int
    {
        $c = $this->getHistoryCandidates($accountId, $leadId, $leadData);
        return $c[0] ?? null;
    }

    public function getOpenLeadsCountByUser(string $accountId, array $userIds): array
    {
        $counts = array_fill_keys($userIds, 0);

        foreach ($userIds as $userId) {
            $total = 0;
            $page  = 1;
            do {
                $data  = $this->request($accountId, 'GET',
                    '/leads?filter[responsible_user_id]=' . $userId .
                    '&filter[is_deleted]=false&limit=250&page=' . $page);
                $items = $data['_embedded']['leads'] ?? [];
                $total += count($items);
                $page++;
                $hasNext = isset($data['_links']['next']);
            } while ($hasNext && count($items) === 250);

            $counts[$userId] = $total;
        }

        return $counts;
    }

    /**
     * Переназначить открытые (незавершённые) задачи сделки на нового ответственного.
     * Вызывается при смене ответственного (распределение/перераспределение).
     * @return int число переназначенных задач
     */
    public function reassignOpenTasks(string $accountId, int $leadId, int $newUserId): int
    {
        try {
            $data = $this->request($accountId, 'GET',
                '/tasks?filter[entity_type]=leads&filter[entity_id]=' . $leadId .
                '&filter[is_completed]=0&limit=100');
        } catch (\Throwable $e) {
            $this->logger->warning('reassignOpenTasks: fetch failed', ['lead_id' => $leadId, 'err' => $e->getMessage()]);
            return 0;
        }
        $tasks   = $data['_embedded']['tasks'] ?? [];
        $payload = [];
        foreach ($tasks as $t) {
            if (!empty($t['is_completed'])) { continue; }
            if ((int) ($t['responsible_user_id'] ?? 0) === $newUserId) { continue; }
            $payload[] = ['id' => (int) $t['id'], 'responsible_user_id' => $newUserId];
        }
        if (!$payload) { return 0; }
        try {
            $this->request($accountId, 'PATCH', '/tasks', ['json' => $payload]);
        } catch (\Throwable $e) {
            $this->logger->warning('reassignOpenTasks: patch failed', ['lead_id' => $leadId, 'err' => $e->getMessage()]);
            return 0;
        }
        $this->logger->info('Open tasks reassigned', ['lead_id' => $leadId, 'count' => count($payload), 'user_id' => $newUserId]);
        return count($payload);
    }

    // Users / Pipelines (widget bootstrap /api/amo/bootstrap)
    /** @return array<int,array{id:int,name:string,group:string}> */
    /** Пользователи аккаунта. @param array<int,string> $groups id=>name (getUserGroups) */
    public function getUsers(string $accountId, array $groups = []): array
    {
        $out = []; $page = 1;
        do {
            $data  = $this->request($accountId, 'GET', '/users?limit=250&page=' . $page);
            $items = $data['_embedded']['users'] ?? [];
            foreach ($items as $u) {
                $gid = $u['rights']['group_id'] ?? null;
                $out[] = [
                    'id'        => (int) ($u['id'] ?? 0),
                    'name'      => (string) ($u['name'] ?? ('#' . ($u['id'] ?? ''))),
                    'group'     => ($gid !== null && isset($groups[(int) $gid])) ? (string) $groups[(int) $gid] : '',
                    'group_key' => 'group_' . (int) ($gid ?? 0),
                    'active'    => isset($u['rights']['is_active']) ? (bool) $u['rights']['is_active'] : true,
                ];
            }
            $page++; $hasNext = isset($data['_links']['next']);
        } while ($hasNext && count($items) === 250);
        return $out;
    }

    /** Группы (отделы) пользователей. @return array<int,string> group_id => name */
    public function getUserGroups(string $accountId): array
    {
        $data = $this->request($accountId, 'GET', '/account?with=users_groups');
        $out  = [];
        foreach (($data['_embedded']['users_groups'] ?? []) as $g) {
            $out[(int) ($g['id'] ?? 0)] = (string) ($g['name'] ?? '');
        }
        return $out;
    }

    /** @return array<int,array{id:int,name:string,statuses:array}> */
    public function getPipelines(string $accountId): array
    {
        $data=$this->request($accountId,'GET','/leads/pipelines');
        $out=[];
        foreach(($data['_embedded']['pipelines']??[]) as $p){
            $st=[];
            foreach(($p['_embedded']['statuses']??[]) as $s){
                $st[]=['id'=>(int)($s['id']??0),'name'=>(string)($s['name']??''),'type'=>(int)($s['type']??1)];
            }
            $out[]=['id'=>(int)($p['id']??0),'name'=>(string)($p['name']??''),'statuses'=>$st];
        }
        return $out;
    }
}
