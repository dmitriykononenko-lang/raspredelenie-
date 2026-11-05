<?php
declare(strict_types=1);
/**
 * Карточки клиентов по всем аккаунтам с токеном.
 *   php /app/bin/accounts-sync.php              — обновить профили (раз в сутки) и завести сделки вендора новым внешним
 *   php /app/bin/accounts-sync.php --dry-run    — только показать, ничего не писать
 *   php /app/bin/accounts-sync.php --no-vendor  — без сделок в koagency
 * Cron: 30 4 * * * (ежедневно).
 */
require __DIR__ . '/../vendor/autoload.php';
try { \Dotenv\Dotenv::createImmutable('/app')->safeLoad(); } catch (\Throwable $e) {}

use DealDist\Accounts\AccountRegistry;
use DealDist\Accounts\VendorNotifier;
use DealDist\AmoCRM\ApiClient;

$dry      = in_array('--dry-run', $argv, true);
$noVendor = in_array('--no-vendor', $argv, true);
$S        = rtrim($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '/storage', '/');

$logger = new Monolog\Logger('accounts-sync');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Level::Warning));
$api      = new ApiClient($logger);
$registry = new AccountRegistry();
$notifier = new VendorNotifier($api, $logger, $registry);

foreach (glob("$S/tokens/*.json") ?: [] as $f) {
    $acc = basename($f, '.json');
    if (!preg_match('/^\d+$/', $acc) || $acc === '0') continue;
    $tok = json_decode((string) file_get_contents($f), true) ?: [];
    $rec = $registry->get($acc);

    $fresh = !empty($rec['profile_at']) && time() - (int) $rec['profile_at'] < 20 * 3600;
    if (!$fresh && empty($rec['revoked_at']) && empty($rec['uninstalled_at'])) {
        $patch = ['domain' => (string) ($tok['base_domain'] ?? ($rec['domain'] ?? ''))];
        if (empty($rec['installed_at'])) {
            // установка была до появления карточек — берём время первого сохранения токена
            $patch['installed_at'] = (int) ($tok['saved_at'] ?? filemtime($f));
            $patch['backfilled']   = true;
        }
        $patch = array_merge($patch, $registry->fetchProfile($acc, $api));
        if (!empty($patch['profile_error']) && stripos($patch['profile_error'], 'revoked') !== false) {
            $patch['revoked_at'] = time();
        }
        if (!$dry) {
            $rec = $registry->save($acc, $patch);
        } else {
            $rec = array_merge($rec, $patch);
        }
    }

    $st       = $registry->stage($acc, $rec);
    $internal = AccountRegistry::isInternal($acc, $rec);
    printf("%-9s %-32s %-28s users=%-3s stage=%-12s%s%s\n",
        $acc, $rec['domain'] ?? '?', mb_substr((string) ($rec['name'] ?? ''), 0, 28), $rec['users_active'] ?? '-',
        $st['stage'], $internal ? ' [internal]' : '', !empty($rec['profile_error']) ? ' ERR: ' . mb_substr($rec['profile_error'], 0, 60) : '');

    if (!$dry && !$noVendor && !$internal && empty($rec['vendor_lead_id']) && $st['stage'] !== 'uninstalled' && !empty($rec['name'])) {
        $notifier->installed($acc, $rec);
        $rec = $registry->get($acc);
        echo "          → сделка в koagency: " . ($rec['vendor_lead_id'] ?? 'не создана') . "\n";
    }
}
