<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Шаблоны распределения — переиспользуемые наборы настроек, на которые можно
 * ссылаться из Digital Pipeline и SalesBot. Хранение настроек в шаблонах
 * защищает их от потери при удалении триггера в цифровой воронке
 * (см. docs/AMOCRM-WIDGET-BILLING-LESSONS.md — «хранение настроек в шаблонах»).
 *
 * Раскладка: STORAGE_PATH/templates/{accountId}.json
 *
 * Структура одного шаблона:
 * {
 *   "id":             "tpl_...",
 *   "name":           "Новые заявки — продажи",
 *   "type":           "round_robin" | "workload" | "percent",
 *   "managers":       [ {"id": 201, "percent": 60}, ... ],
 *   "check_history":  true,
 *   "check_schedule": false,
 *   "filters":        { ... },
 *   "created_at":     1712345678,
 *   "updated_at":     1712345678
 * }
 */
class TemplateStorage
{
    private const TYPES = ['round_robin', 'workload', 'percent'];

    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/templates';
    }

    /** @return array<string, array> [id => template] */
    public function all(string $accountId): array
    {
        $file = $this->filePath($accountId);
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public function get(string $accountId, string $id): ?array
    {
        return $this->all($accountId)[$id] ?? null;
    }

    public function create(string $accountId, array $data): array
    {
        $all = $this->all($accountId);
        $id  = $this->generateId($all);

        $tpl = $this->normalize($data);
        $tpl['id']         = $id;
        $tpl['created_at'] = time();
        $tpl['updated_at'] = time();

        $all[$id] = $tpl;
        $this->save($accountId, $all);

        return $tpl;
    }

    public function update(string $accountId, string $id, array $data): ?array
    {
        $all = $this->all($accountId);
        if (!isset($all[$id])) {
            return null;
        }

        $tpl = $this->normalize($data, $all[$id]);
        $tpl['id']         = $id;
        $tpl['created_at'] = $all[$id]['created_at'] ?? time();
        $tpl['updated_at'] = time();

        $all[$id] = $tpl;
        $this->save($accountId, $all);

        return $tpl;
    }

    public function delete(string $accountId, string $id): bool
    {
        $all = $this->all($accountId);
        if (!isset($all[$id])) {
            return false;
        }
        unset($all[$id]);
        $this->save($accountId, $all);
        return true;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /** Приводит вход к валидной структуре шаблона (без id/времени). */
    private function normalize(array $data, array $base = []): array
    {
        $type = $data['type'] ?? $base['type'] ?? 'round_robin';
        if (!in_array($type, self::TYPES, true)) {
            $type = 'round_robin';
        }

        $managers = [];
        foreach (($data['managers'] ?? $base['managers'] ?? []) as $m) {
            $id = (int) ($m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $entry = ['id' => $id];
            if ($type === 'percent') {
                $entry['percent'] = max(0, min(100, (int) ($m['percent'] ?? 0)));
            }
            $managers[] = $entry;
        }

        return [
            'name'           => trim((string) ($data['name'] ?? $base['name'] ?? 'Без названия')),
            'type'           => $type,
            'managers'       => $managers,
            'check_history'  => (bool) ($data['check_history']  ?? $base['check_history']  ?? false),
            'check_schedule' => (bool) ($data['check_schedule'] ?? $base['check_schedule'] ?? false),
            'filters'        => (array) ($data['filters'] ?? $base['filters'] ?? []),
        ];
    }

    private function generateId(array $existing): string
    {
        do {
            $id = 'tpl_' . bin2hex(random_bytes(6));
        } while (isset($existing[$id]));
        return $id;
    }

    private function save(string $accountId, array $all): void
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
        file_put_contents(
            $this->filePath($accountId),
            json_encode($all, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function filePath(string $accountId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $accountId) ?: 'unknown';
        return $this->basePath . '/' . $safe . '.json';
    }
}
