<?php

declare(strict_types=1);

namespace DealDist\Billing;

/**
 * Хранилище сроков действия («оплачено до») по аккаунтам и виджетам.
 *
 * Файл: STORAGE_PATH/subscriptions/{account_id}.json
 * Формат:
 *   {
 *     "raspredelenie": { "paid_until": 1799999999, "history": [ {ts, action, months, until, by} ] },
 *     "dubli":         { "paid_until": null,       "history": [] }
 *   }
 *
 * paid_until — unix-время (сек), до которого виджет оплачен; null = не задано.
 *
 * Самодостаточный класс (без DI): путь берёт из ENV, как AdminController,
 * чтобы им могли пользоваться и админка, и вебхук распределения.
 */
final class SubscriptionStore
{
    /** Виджеты, у которых считается срок. */
    public const WIDGETS = ['raspredelenie', 'dubli'];

    /** Порог «истекает скоро», сек (14 дней). */
    public const SOON = 14 * 86400;

    private string $dir;

    public function __construct(?string $storagePath = null)
    {
        $base = $storagePath ?? rtrim((string) ($_ENV['STORAGE_PATH'] ?? '/storage'), '/');
        $this->dir = $base . '/subscriptions';
    }

    private function file(string $account): string
    {
        return $this->dir . '/' . preg_replace('/[^0-9]/', '', $account) . '.json';
    }

    /** @return array<string,array{paid_until:?int,history:array}> */
    public function read(string $account): array
    {
        $f = $this->file($account);
        $data = [];
        if (is_file($f)) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) { $data = $d; }
        }
        // нормализуем структуру по всем виджетам
        $out = [];
        foreach (self::WIDGETS as $w) {
            $node = is_array($data[$w] ?? null) ? $data[$w] : [];
            $out[$w] = [
                'paid_until' => isset($node['paid_until']) && $node['paid_until'] !== null ? (int) $node['paid_until'] : null,
                'history'    => is_array($node['history'] ?? null) ? $node['history'] : [],
            ];
        }
        return $out;
    }

    private function write(string $account, array $data): bool
    {
        if (!is_dir($this->dir)) { @mkdir($this->dir, 0775, true); }
        $tmp = $this->file($account) . '.tmp';
        $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
        if ($ok) { $ok = @rename($tmp, $this->file($account)); }
        return $ok;
    }

    private static function normWidget(string $widget): ?string
    {
        $widget = strtolower(trim($widget));
        return in_array($widget, self::WIDGETS, true) ? $widget : null;
    }

    /**
     * Продлить на N месяцев. Считает от max(now, paid_until) — не сгорает остаток.
     * @return array{paid_until:?int,history:array}|null null при неверном виджете/months.
     */
    public function extend(string $account, string $widget, int $months, string $by = 'admin', int $now = 0): ?array
    {
        $widget = self::normWidget($widget);
        if ($widget === null || $months === 0 || $months < -120 || $months > 120) { return null; }
        $now = $now ?: time();
        $data = $this->read($account);
        $base = max($now, (int) ($data[$widget]['paid_until'] ?? 0));
        $until = strtotime('+' . $months . ' months', $base);
        if ($until === false) { return null; }
        $data[$widget]['paid_until'] = $until;
        $data[$widget]['history'][] = [
            'ts'     => $now,
            'action' => 'extend',
            'months' => $months,
            'until'  => $until,
            'by'     => $by,
        ];
        $data[$widget]['history'] = array_slice($data[$widget]['history'], -50);
        return $this->write($account, $data) ? $data[$widget] : null;
    }

    /**
     * Явно установить дату (unix-сек), null — снять срок.
     * @return array{paid_until:?int,history:array}|null
     */
    public function setUntil(string $account, string $widget, ?int $until, string $by = 'admin', int $now = 0): ?array
    {
        $widget = self::normWidget($widget);
        if ($widget === null) { return null; }
        $now = $now ?: time();
        $data = $this->read($account);
        $data[$widget]['paid_until'] = $until !== null ? (int) $until : null;
        $data[$widget]['history'][] = [
            'ts'     => $now,
            'action' => 'set',
            'until'  => $until,
            'by'     => $by,
        ];
        $data[$widget]['history'] = array_slice($data[$widget]['history'], -50);
        return $this->write($account, $data) ? $data[$widget] : null;
    }

    /**
     * Статус виджета: none|active|soon|expired + остаток дней.
     * @return array{status:string,paid_until:?int,days_left:?int}
     */
    public function status(string $account, string $widget, int $now = 0): array
    {
        $widget = self::normWidget($widget) ?? 'raspredelenie';
        $now = $now ?: time();
        $pu = $this->read($account)[$widget]['paid_until'] ?? null;
        if ($pu === null) {
            return ['status' => 'none', 'paid_until' => null, 'days_left' => null];
        }
        $left = $pu - $now;
        $days = (int) ceil($left / 86400);
        if ($left <= 0)          { $st = 'expired'; }
        elseif ($left <= self::SOON) { $st = 'soon'; }
        else                     { $st = 'active'; }
        return ['status' => $st, 'paid_until' => (int) $pu, 'days_left' => $days];
    }

    /**
     * Активна ли подписка (для enforcement). Если срок не задан (none) —
     * считаем активной (fail-open): продажи ещё нет, старые аккаунты не режем.
     */
    public function isActive(string $account, string $widget, int $now = 0): bool
    {
        $s = $this->status($account, $widget, $now);
        return $s['status'] !== 'expired';
    }
}
