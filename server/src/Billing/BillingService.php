<?php

declare(strict_types=1);

namespace DealDist\Billing;

/**
 * Ядро биллинга «Распределения»: расчёт цены, создание платежа ЮKassa,
 * идемпотентное подтверждение (продление подписки). Используется:
 *   - BillingController (виджет: /api/billing/quote, /api/billing/checkout, /webhook/yookassa)
 *   - AdminController   (админка: «Создать ссылку на оплату»)
 *   - bin/billing-poll.php (поллер succeeded-платежей, т.к. вебхук магазина занят Дублями)
 *
 * Модель цены — как у «Дублей»:
 *   сумма = BILLING_PRICE_PER_USER × max(BILLING_MIN_USERS, users) × pay_months
 *   Планы: 6 мес (оплата 6) и 12 мес (оплата 10 — 2 месяца в подарок).
 */
final class BillingService
{
    public const WIDGET = 'raspredelenie';

    /** @var array<int,array{months:int,pay:int}> план → {срок подписки, оплачиваемых месяцев} */
    private const PLANS = [
        6  => ['months' => 6,  'pay' => 6],
        12 => ['months' => 12, 'pay' => 10],
    ];

    private string $storage;

    public function __construct(?string $storage = null)
    {
        $this->storage = rtrim($storage ?? (string) ($_ENV['STORAGE_PATH'] ?? '/storage'), '/');
    }

    public function pricePerUser(): int
    {
        $v = (int) ($_ENV['BILLING_PRICE_PER_USER'] ?? 399);
        return $v > 0 ? $v : 399;
    }

    public function minUsers(): int
    {
        $v = (int) ($_ENV['BILLING_MIN_USERS'] ?? 5);
        return $v > 0 ? $v : 5;
    }

    public function fiscalOn(): bool
    {
        $v = strtolower(trim((string) ($_ENV['YOOKASSA_FISCAL'] ?? '')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<int,array{months:int,pay:int}> */
    public function planList(): array
    {
        return self::PLANS;
    }

    /**
     * Расчёт по одному плану.
     * @return array{ok:bool,plan_id?:int,users?:int,billed_users?:int,months?:int,pay?:int,bonus?:int,price_per_user?:int,sum?:float,error?:string,available_plans?:array}
     */
    public function quote(int $planId, int $usersRaw): array
    {
        if (!isset(self::PLANS[$planId])) {
            return ['ok' => false, 'error' => 'bad_plan', 'available_plans' => array_keys(self::PLANS)];
        }
        $plan   = self::PLANS[$planId];
        $ppu    = $this->pricePerUser();
        $billed = max($this->minUsers(), $usersRaw > 0 ? $usersRaw : 0);
        return [
            'ok' => true, 'plan_id' => $planId, 'users' => $usersRaw, 'billed_users' => $billed,
            'months' => $plan['months'], 'pay' => $plan['pay'], 'bonus' => $plan['months'] - $plan['pay'],
            'price_per_user' => $ppu, 'sum' => (float) ($ppu * $billed * $plan['pay']),
        ];
    }

    /** Текущее состояние подписки аккаунта по виджету «Распределение». */
    public function getStatus(string $account): array
    {
        $account = preg_replace('/[^0-9]/', '', $account);
        if ($account === '') {
            return ['status' => 'none', 'paid_until' => null, 'days_left' => null];
        }
        return (new SubscriptionStore($this->storage))->status($account, self::WIDGET);
    }

    /** Расчёт по всем планам (для UI). @return array<int,array> */
    public function allQuotes(int $usersRaw): array
    {
        $out = [];
        foreach (array_keys(self::PLANS) as $id) {
            $out[] = $this->quote($id, $usersRaw);
        }
        return $out;
    }

    /**
     * Создать платёж ЮKassa и вернуть ссылку на оплату.
     *
     * @param string $source 'widget' | 'admin' — только для истории.
     * @return array{ok:bool,status:int,confirmation_url?:string,payment_id?:string,amount?:float,months?:int,users?:int,error?:string,detail?:string,available_plans?:array}
     */
    public function createCheckout(string $account, int $planId, int $users, string $email = '', string $source = 'widget'): array
    {
        $account = preg_replace('/[^0-9]/', '', $account);
        if ($account === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'account_id required'];
        }
        $q = $this->quote($planId, $users);
        if (!$q['ok']) {
            return ['ok' => false, 'status' => 400, 'error' => $q['error'], 'available_plans' => $q['available_plans'] ?? []];
        }

        $yk = new YooKassaClient();
        if (!$yk->enabled()) {
            return ['ok' => false, 'status' => 503, 'error' => 'yookassa_not_configured', 'detail' => 'set YOOKASSA_SHOP_ID/SECRET_KEY'];
        }

        $sum    = (float) $q['sum'];
        $months = (int) $q['months'];
        $billed = (int) $q['billed_users'];
        $email  = trim($email);
        $desc   = 'Подписка «Распределение», ' . $billed . ' польз., ' . $months . ' мес';

        // Чек 54-ФЗ (значение может быть "1"/"true"/"yes"/"on" — как у Дублей "true")
        $receipt = null;
        if ($this->fiscalOn()) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'status' => 400, 'error' => 'email_required_for_receipt'];
            }
            $receipt = [
                'customer' => ['email' => $email],
                'items'    => [[
                    'description'     => mb_substr($desc, 0, 128),
                    'quantity'        => '1.00',
                    'amount'          => ['value' => number_format($sum, 2, '.', ''), 'currency' => 'RUB'],
                    'vat_code'        => (int) ($_ENV['YOOKASSA_VAT_CODE'] ?? 1),
                    'payment_subject' => 'service',
                    'payment_mode'    => 'full_payment',
                ]],
            ];
        }

        $returnUrl = (string) ($_ENV['BILLING_RETURN_URL'] ?? 'https://raspredelenie.koagency.ru/billing/done');

        try {
            $payment = $yk->createPayment([
                'amount'      => $sum,
                'description' => $desc,
                'return_url'  => $returnUrl,
                'metadata'    => [
                    'account_id' => $account, 'widget' => self::WIDGET,
                    'months' => $months, 'plan_id' => $planId, 'users' => $billed, 'src' => $source,
                ],
                'receipt'     => $receipt,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 502, 'error' => 'yookassa_create_failed', 'detail' => $e->getMessage()];
        }

        $url = (string) ($payment['confirmation']['confirmation_url'] ?? '');
        $pid = (string) ($payment['id'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'status' => 502, 'error' => 'no_confirmation_url'];
        }

        $this->savePayment($pid, [
            'account_id' => $account, 'widget' => self::WIDGET, 'months' => $months, 'plan_id' => $planId,
            'users' => $billed, 'amount' => $sum, 'status' => 'pending', 'source' => $source, 'ts' => time(),
        ]);

        return [
            'ok' => true, 'status' => 200, 'confirmation_url' => $url, 'payment_id' => $pid,
            'amount' => $sum, 'months' => $months, 'users' => $billed,
        ];
    }

    /**
     * Идемпотентное подтверждение платежа: перепроверяем статус в ЮKassa,
     * при succeeded — продлеваем подписку. Повторные вызовы безопасны.
     * @return string applied|already|not_succeeded|foreign|no_yk
     */
    public function applyPayment(string $id): string
    {
        $yk = new YooKassaClient();
        if (!$yk->enabled()) return 'no_yk';

        $p      = $yk->getPayment($id);
        $status = (string) ($p['status'] ?? 'unknown');
        if ($status !== 'succeeded') {
            $this->markPayment($id, $status);
            return 'not_succeeded';
        }

        $meta    = (array) ($p['metadata'] ?? []);
        $account = preg_replace('/[^0-9]/', '', (string) ($meta['account_id'] ?? ''));
        $widget  = (string) ($meta['widget'] ?? '');
        $months  = (int) ($meta['months'] ?? 0);

        // чужой платёж (общий магазин шлёт и платежи «Дублей») — тихо пропускаем
        if ($account === '' || $widget !== self::WIDGET || $months <= 0) {
            return 'foreign';
        }

        $rec = $this->readPayment($id);
        if (($rec['applied'] ?? false) === true) {
            return 'already';
        }

        $subs = new SubscriptionStore($this->storage);
        $subs->extend($account, $widget, $months, 'yookassa');
        $st = $subs->status($account, $widget);

        $this->savePayment($id, [
            'account_id' => $account, 'widget' => $widget, 'months' => $months,
            'amount' => isset($p['amount']['value']) ? (float) $p['amount']['value'] : null,
            'status' => 'succeeded', 'applied' => true,
            'paid_until' => $st['paid_until'] ?? null, 'applied_ts' => time(),
        ]);
        $this->log(['ok' => 'applied', 'payment' => $id, 'account' => $account, 'months' => $months, 'src' => $meta['src'] ?? '']);
        return 'applied';
    }

    /* ------------ payments store (идемпотентность + история) ------------ */

    /** @return list<string> абсолютные пути pending/неприменённых записей */
    public function pendingFiles(): array
    {
        $out = [];
        foreach (glob($this->storage . '/payments/*.json') ?: [] as $f) {
            $rec = json_decode((string) @file_get_contents($f), true);
            if (!is_array($rec)) continue;
            if (($rec['applied'] ?? false) === true) continue;
            if (in_array((string) ($rec['status'] ?? ''), ['canceled', 'expired'], true)) continue;
            $out[] = $f;
        }
        return $out;
    }

    private function payFile(string $id): string
    {
        return $this->storage . '/payments/' . preg_replace('/[^A-Za-z0-9._-]/', '', $id) . '.json';
    }

    private function savePayment(string $id, array $data): void
    {
        if ($id === '') return;
        $dir = $this->storage . '/payments';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $prev = $this->readPayment($id);
        @file_put_contents($this->payFile($id), json_encode(array_merge($prev, $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function markPayment(string $id, string $status): void
    {
        $this->savePayment($id, ['status' => $status, 'ts' => time()]);
    }

    private function readPayment(string $id): array
    {
        $f = $this->payFile($id);
        if (!is_file($f)) return [];
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    private function log(array $row): void
    {
        $dir = $this->storage . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/billing.ndjson',
            json_encode(['ts' => time()] + $row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}
