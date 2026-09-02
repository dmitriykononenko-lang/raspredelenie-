<?php

declare(strict_types=1);

/**
 * Поллер платежей «Распределения». Магазин ЮKassa общий с «Дублями», его вебхук
 * занят Дублями — поэтому succeeded подтверждаем опросом (идемпотентно).
 *
 * Cron внутри контейнера, напр. каждые 3 минуты:
 *   docker exec raspredelenie-app-1 php /app/bin/billing-poll.php
 *
 * Перебирает /storage/payments/*.json (pending/unknown) и вызывает
 * BillingService::applyPayment(). Applied/canceled/expired пропускаются.
 */

$root = dirname(__DIR__); // /app
require $root . '/vendor/autoload.php';

// CLI не проходит через AppFactory — сами грузим смонтированный /app/.env
// (там лежат YOOKASSA_*/BILLING_* — их нет в окружении процесса контейнера).
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
// STORAGE_PATH приходит из compose `environment:` — подтянем из реального окружения.
if (!isset($_ENV['STORAGE_PATH'])) {
    $v = getenv('STORAGE_PATH');
    if ($v !== false) {
        $_ENV['STORAGE_PATH'] = $v;
    }
}

use DealDist\Billing\BillingService;

$svc     = new BillingService();
$checked = 0;
$applied = 0;
$results = [];

foreach ($svc->pendingFiles() as $f) {
    $id = basename($f, '.json');
    $checked++;
    try {
        $r = $svc->applyPayment($id);
        $results[$id] = $r;
        if ($r === 'applied') {
            $applied++;
        }
    } catch (\Throwable $e) {
        $results[$id] = 'error:' . $e->getMessage();
    }
}

fwrite(STDOUT, json_encode([
    'ts' => date('c'), 'checked' => $checked, 'applied' => $applied, 'results' => $results,
], JSON_UNESCAPED_UNICODE) . "\n");
