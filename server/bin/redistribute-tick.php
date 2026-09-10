<?php

declare(strict_types=1);

/**
 * Планировщик ПЕРЕраспределения. Запускается по cron раз в минуту:
 *   docker exec raspredelenie-app-1 php /app/bin/redistribute-tick.php
 *
 * Логика: для каждой «зависшей» сделки (записана при назначении, если в
 * правиле включён таймаут) проверяем — если она всё ещё на том же этапе и
 * ответственный не менялся (менеджер её не взял) по истечении таймаута,
 * перекидываем следующему по кругу (online + график), максимум N раз.
 * Если сделку взяли/двинули — снимаем с контроля.
 */

require __DIR__ . '/../vendor/autoload.php';

use DealDist\AmoCRM\ApiClient;
use DealDist\Distribution\PendingStorage;
use DealDist\Distribution\StatusStorage;
use DealDist\Distribution\ScheduleChecker;
use DealDist\Distribution\DistributionLog;
use DealDist\Distribution\DistributionService;
use DealDist\Distribution\QueueStorage;
use DealDist\Config\SettingsStorage;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// .env смонтирован в /app/.env (см. docker-compose). Грузим креды/токен.
try {
    $dotenv = \Dotenv\Dotenv::createImmutable('/app');
    $dotenv->safeLoad();
} catch (\Throwable $e) {
    // ignore — переменные могут уже быть в окружении
}
if (empty($_ENV['STORAGE_PATH'])) {
    $_ENV['STORAGE_PATH'] = getenv('STORAGE_PATH') ?: '/storage';
}

$logger = new Logger('redistribute');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$pending = new PendingStorage();
$api     = new ApiClient($logger);
$status  = new StatusStorage();
$sched   = new ScheduleChecker();
$log     = new DistributionLog();

$now      = time();
$accounts = $pending->listAccounts();
if (!$accounts) {
    exit(0);
}

// ── v1.4: ОЧЕРЕДЬ ОЖИДАНИЯ ──────────────────────────────────────────────────
// Записи type=waiting — заявки, которым в момент прихода некого было отдать.
// Раздаём в порядке queued_at, как только есть менеджер online и (при графике)
// на смене; не раньше not_before (HH:MM, МСК-таймзона правила); сделку,
// ушедшую с этапа, снимаем; просроченные (max_wait_sec) — снимаем с пометкой.
// По одному аккаунту — не более RELEASE_PER_TICK сделок за проход (API amoCRM).
const RELEASE_PER_TICK = 15;
$service = new DistributionService($api, new QueueStorage(), $sched, $status, $log, $logger);
$tz      = new DateTimeZone('Europe/Moscow');

foreach ($accounts as $accountId) {
    $all     = $pending->all($accountId);
    $changed = false;

    $waiting = array_filter($all, fn($e) => ($e['type'] ?? '') === 'waiting');
    if ($waiting) {
        uasort($waiting, fn($a, $b) => (int) ($a['queued_at'] ?? 0) <=> (int) ($b['queued_at'] ?? 0));
        $released = 0;
        $settings = (new SettingsStorage())->load($accountId);
        foreach ($waiting as $key => $e) {
            if ($released >= RELEASE_PER_TICK) { break; }
            $leadId = (int) ($e['lead_id'] ?? 0);
            if ($leadId <= 0) { unset($all[$key]); $changed = true; continue; }

            // просрочка
            if ($now - (int) ($e['queued_at'] ?? $now) > (int) ($e['max_wait_sec'] ?? 129600)) {
                $log->record($accountId, $leadId, $e['pipeline_id'] ?? null, $e['stage_id'] ?? null, null, $e['method'] ?? 'round_robin', 'queue_expired');
                $logger->info('Waiting: expired, dropping', ['lead_id' => $leadId]);
                unset($all[$key]); $changed = true; continue;
            }
            // не раньше HH:MM
            $nb = (string) ($e['not_before'] ?? '');
            if ($nb !== '' && (new DateTime('now', $tz))->format('H:i') < $nb) { continue; }
            // есть ли кому отдать
            $anyone = false;
            foreach (array_map('intval', $e['managers'] ?? []) as $mid) {
                if (!$status->isOnline($accountId, $mid)) { continue; }
                if (!empty($e['check_schedule']) && !$sched->isAvailable($accountId, $mid)) { continue; }
                $anyone = true; break;
            }
            if (!$anyone) { continue; }
            // сделка всё ещё на том же этапе?
            try {
                $lead = $api->getLead($accountId, $leadId);
            } catch (\Throwable $ex) {
                $logger->info('Waiting: lead fetch failed, dropping', ['lead_id' => $leadId, 'err' => $ex->getMessage()]);
                unset($all[$key]); $changed = true; continue;
            }
            if (!empty($e['stage_id']) && (int) ($lead['status_id'] ?? 0) !== (int) $e['stage_id']) {
                $log->record($accountId, $leadId, $e['pipeline_id'] ?? null, $e['stage_id'] ?? null, null, $e['method'] ?? 'round_robin', 'queue_dropped_moved');
                unset($all[$key]); $changed = true; continue;
            }
            // раздаём через обычный сервис (история клиента, очередь, LeadGuard)
            unset($all[$key]); $changed = true;            // снимаем ДО вызова — сервис может положить свою запись (redistribute)
            $pending->saveAll($accountId, $all);
            try {
                $res = $service->distribute([
                    'account_id'          => $accountId,
                    'lead_id'             => $leadId,
                    'pipeline_id'         => $e['pipeline_id'] ?? null,
                    'stage_id'            => $e['stage_id'] ?? null,
                    'distribution_method' => $settings['distribution_method'] ?? ($e['method'] ?? 'round_robin'),
                    'rules'               => $settings['rules'] ?? [],
                    'dp_settings'         => [],
                    'event'               => 'queue',
                    'force'               => true,
                ]);
                $logger->info('Waiting: released', ['lead_id' => $leadId, 'result' => $res]);
                $released++;
            } catch (\Throwable $ex) {
                $logger->warning('Waiting: release failed', ['lead_id' => $leadId, 'err' => $ex->getMessage()]);
            }
            $all = $pending->all($accountId);   // перечитать — сервис мог изменить файл
        }
        if ($changed) { $pending->saveAll($accountId, $all); $changed = false; }
    }

    foreach ($all as $key => $e) {
        if (($e['type'] ?? '') === 'waiting') {
            continue; // обработано выше
        }
        if ((int) ($e['next_at'] ?? 0) > $now) {
            continue; // ещё не время
        }

        $leadId = (int) ($e['lead_id'] ?? 0);
        if ($leadId <= 0) { unset($all[$key]); $changed = true; continue; }

        try {
            $lead = $api->getLead($accountId, $leadId);
        } catch (\Throwable $ex) {
            // сделки нет / ошибка API — снимаем с контроля
            $logger->info('Redistribute: lead fetch failed, dropping', ['lead_id' => $leadId, 'err' => $ex->getMessage()]);
            unset($all[$key]); $changed = true; continue;
        }

        $curStage = isset($lead['status_id'])            ? (int) $lead['status_id']            : null;
        $curResp  = isset($lead['responsible_user_id'])  ? (int) $lead['responsible_user_id']  : null;

        // Взяли/двинули → снимаем с контроля.
        if ($curStage !== (int) $e['stage_id'] || $curResp !== (int) $e['manager_id']) {
            unset($all[$key]); $changed = true; continue;
        }

        // Не взяли → следующий по кругу.
        $managers = array_values(array_map('intval', $e['managers'] ?? []));
        if (count($managers) < 2) { // некому передавать
            unset($all[$key]); $changed = true; continue;
        }
        $cur = (int) $e['manager_id'];
        $idx = array_search($cur, $managers, true);
        $n   = count($managers);

        $next = null;
        for ($i = 1; $i <= $n; $i++) {
            $mid = $managers[($idx === false ? 0 : $idx + $i) % $n];
            if ($mid === $cur) { continue; }
            if (!$status->isOnline($accountId, $mid)) { continue; }
            if (!empty($e['check_schedule']) && !$sched->isAvailable($accountId, $mid)) { continue; }
            $next = $mid; break;
        }

        if ($next === null) {
            // Некого назначить сейчас — попробуем в следующий цикл, попытку не тратим.
            $e['next_at'] = $now + (int) $e['after_sec'];
            $all[$key] = $e; $changed = true; continue;
        }

        try {
            $api->updateLeadResponsible($accountId, $leadId, $next);
            try { $api->reassignOpenTasks($accountId, $leadId, $next); } catch (\Throwable $t) {}
        } catch (\Throwable $ex) {
            $logger->warning('Redistribute: reassign failed', ['lead_id' => $leadId, 'err' => $ex->getMessage()]);
            $e['next_at'] = $now + (int) $e['after_sec'];
            $all[$key] = $e; $changed = true; continue;
        }

        $log->record(
            $accountId,
            $leadId,
            ((int) ($e['pipeline_id'] ?? 0)) ?: null,
            ((int) ($e['stage_id'] ?? 0)) ?: null,
            $next,
            $e['method'] ?? 'round_robin',
            'redistributed'
        );
        $logger->info('Redistributed', ['lead_id' => $leadId, 'from' => $cur, 'to' => $next, 'attempt' => (int) $e['attempts'] + 1]);

        $e['manager_id'] = $next;
        $e['attempts']   = (int) $e['attempts'] + 1;
        if ($e['attempts'] >= (int) ($e['max'] ?? 3)) {
            unset($all[$key]); // достигли лимита попыток — стоп
        } else {
            $e['next_at'] = $now + (int) $e['after_sec'];
            $all[$key] = $e;
        }
        $changed = true;
    }

    if ($changed) {
        $pending->saveAll($accountId, $all);
    }
}
