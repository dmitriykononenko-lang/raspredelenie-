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

foreach ($accounts as $accountId) {
    $all     = $pending->all($accountId);
    $changed = false;

    foreach ($all as $key => $e) {
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
