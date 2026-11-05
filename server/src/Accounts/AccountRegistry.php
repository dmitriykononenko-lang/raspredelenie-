<?php

declare(strict_types=1);

namespace DealDist\Accounts;

use DealDist\AmoCRM\ApiClient;

/**
 * Карточка клиента, установившего виджет: кто, что за компания, на каком этапе.
 *
 * Файл: STORAGE_PATH/accounts/{accountId}.json
 *   installed_at, reinstalled_at, uninstalled_at, revoked_at,
 *   domain, name, country, users_active, users_total,
 *   installer {id, name, email, lang}, admins [{id, name, email}],
 *   first_opened_at, last_seen_at, vendor_lead_id
 *
 * Этап (stage) считается на лету: uninstalled → paid → distributing → rules → opened → installed.
 */
final class AccountRegistry
{
    private string $storage;
    private string $dir;

    public function __construct()
    {
        $this->storage = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/');
        $this->dir     = $this->storage . '/accounts';
    }

    public function get(string $accountId): array
    {
        $f = $this->file($accountId);
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    /** @return array<string,array> account_id => record */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) {
                $out[basename($f, '.json')] = $d;
            }
        }
        return $out;
    }

    public function save(string $accountId, array $patch): array
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $rec = array_merge($this->get($accountId), $patch, ['account_id' => $accountId]);
        file_put_contents($this->file($accountId), json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        return $rec;
    }

    /**
     * Снимает данные о компании и установившем через свежий токен.
     * Любая ошибка API не валит установку: сохраняем то, что успели.
     */
    public function onInstall(string $accountId, string $domain, ApiClient $api): array
    {
        $prev  = $this->get($accountId);
        $now   = time();
        $patch = ['domain' => $domain, 'uninstalled_at' => null, 'revoked_at' => null];
        if (empty($prev['installed_at'])) {
            $patch['installed_at'] = $now;
        } else {
            $patch['reinstalled_at'] = $now;
        }
        $patch = array_merge($patch, $this->fetchProfile($accountId, $api));
        return $this->save($accountId, $patch);
    }

    /** Данные аккаунта из amoCRM: название, страна, установивший, админы, число пользователей. */
    public function fetchProfile(string $accountId, ApiClient $api): array
    {
        $out = [];
        try {
            $a = $api->api($accountId, 'GET', '/account');
            $out['name']    = (string) ($a['name'] ?? '');
            $out['country'] = (string) ($a['country'] ?? '');
            $installerId    = (int) ($a['current_user_id'] ?? 0);

            $users = [];
            $page  = 1;
            do {
                $d     = $api->api($accountId, 'GET', '/users?limit=250&page=' . $page);
                $items = $d['_embedded']['users'] ?? [];
                $users = array_merge($users, $items);
                $page++;
            } while (isset($d['_links']['next']) && count($items) === 250 && $page < 10);

            $active = array_filter($users, fn($u) => !empty($u['rights']['is_active']));
            $out['users_total']  = count($users);
            $out['users_active'] = count($active);
            $admins = [];
            foreach ($users as $u) {
                $short = ['id' => (int) ($u['id'] ?? 0), 'name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? ''), 'lang' => (string) ($u['lang'] ?? '')];
                if ($installerId && (int) ($u['id'] ?? 0) === $installerId) {
                    $out['installer'] = $short;
                }
                if (!empty($u['rights']['is_admin']) && !empty($u['rights']['is_active'])) {
                    $admins[] = $short;
                }
            }
            $out['admins'] = array_slice($admins, 0, 10);
            $out['profile_at'] = time();
        } catch (\Throwable $e) {
            $out['profile_error'] = mb_substr($e->getMessage(), 0, 200);
        }
        return $out;
    }

    /** Отметка «виджет открывали» — вызывается на каждом /api/* запросе, пишет не чаще раза в 5 минут. */
    public function touch(string $accountId): void
    {
        if (!preg_match('/^\d{3,12}$/', $accountId)) {
            return;
        }
        $rec = $this->get($accountId);
        $now = time();
        if (!empty($rec['last_seen_at']) && $now - (int) $rec['last_seen_at'] < 300) {
            return;
        }
        $patch = ['last_seen_at' => $now];
        if (empty($rec['first_opened_at'])) {
            $patch['first_opened_at'] = $now;
        }
        $this->save($accountId, $patch);
    }

    public function markUninstalled(string $accountId, bool $verified): array
    {
        return $this->save($accountId, ['uninstalled_at' => time(), 'uninstall_verified' => $verified]);
    }

    public function markRevoked(string $accountId): void
    {
        $rec = $this->get($accountId);
        if (empty($rec['revoked_at'])) {
            $this->save($accountId, ['revoked_at' => time()]);
        }
    }

    /** Этап воронки активации + флаги для сводки. */
    public function stage(string $accountId, ?array $rec = null): array
    {
        $rec      = $rec ?? $this->get($accountId);
        $settings = $this->readJson($this->storage . '/settings/' . $accountId . '.json');
        $rules    = count($settings['rules'] ?? []);
        $lastAssign = 0;
        $assigned   = 0;
        $lf = $this->storage . '/logs/' . $accountId . '.ndjson';
        if (is_file($lf)) {
            foreach (array_slice(file($lf, FILE_IGNORE_NEW_LINES) ?: [], -500) as $l) {
                $e = json_decode($l, true);
                if (is_array($e) && in_array($e['reason'] ?? '', ['assigned', 'history_match'], true)) {
                    $assigned++;
                    $lastAssign = max($lastAssign, (int) ($e['ts'] ?? 0));
                }
            }
        }
        $sub  = $this->readJson($this->storage . '/subscriptions/' . $accountId . '.json');
        $paid = (int) ($sub['raspredelenie']['paid_until'] ?? 0) > time();

        if (!empty($rec['uninstalled_at']) || !empty($rec['revoked_at'])) {
            $stage = 'uninstalled';
        } elseif ($paid) {
            $stage = 'paid';
        } elseif ($assigned > 0) {
            $stage = 'distributing';
        } elseif ($rules > 0) {
            $stage = 'rules';
        } elseif (!empty($rec['first_opened_at'])) {
            $stage = 'opened';
        } else {
            $stage = 'installed';
        }
        $since = (int) ($rec['installed_at'] ?? 0);
        $stuck = in_array($stage, ['installed', 'opened', 'rules'], true) && $since > 0 && time() - $since > 48 * 3600;

        return ['stage' => $stage, 'rules' => $rules, 'assigned_recent' => $assigned, 'last_assigned_at' => $lastAssign ?: null, 'paid' => $paid, 'stuck' => $stuck];
    }

    /** Наши тестовые и служебные аккаунты — без уведомлений и сделок в CRM. */
    public static function isInternal(string $accountId, array $rec): bool
    {
        if (in_array($accountId, ['30119215', '33022710'], true)) {
            return true;
        }
        $domain = (string) ($rec['domain'] ?? '');
        if (preg_match('/^(aat\d+|\d+raspredelenieko|\d+gnzs|partnerskoda|koagency)\./i', $domain)) {
            return true;
        }
        $email = strtolower((string) ($rec['installer']['email'] ?? ''));
        return str_ends_with($email, '@koagency.me');
    }

    private function file(string $accountId): string
    {
        return $this->dir . '/' . preg_replace('/[^0-9]/', '', $accountId) . '.json';
    }

    private function readJson(string $f): array
    {
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }
}
