<?php
declare(strict_types=1);
/**
 * Мониторинг здоровья аккаунтов + утренний отчёт. Cron: раз в 15 мин (алерты) и в 09:15 МСК (отчёт).
 *   php /app/bin/health-check.php            — проверить и отправить только новые алерты
 *   php /app/bin/health-check.php --report   — дневной отчёт по всем аккаунтам
 *   php /app/bin/health-check.php --test     — тестовое сообщение
 * Telegram: TG_BOT_TOKEN / TG_CHAT_ID в .env. Если не заданы — пишет в stdout/лог.
 * Антидребезг: одинаковый алерт по аккаунту не чаще раза в 6 часов (STORAGE/alerts/state.json).
 */
require __DIR__ . '/../vendor/autoload.php';
try { \Dotenv\Dotenv::createImmutable('/app')->safeLoad(); } catch (\Throwable $e) {}
$S = rtrim($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '/storage', '/');
$tz = new DateTimeZone('Europe/Moscow'); $now = new DateTime('now', $tz); $ts = time();
$mode = $argv[1] ?? '';
$names = ['32722686' => 'RE:source (клиника)', '7275562' => 'Кит Тех', '33258634' => 'Тест-аккаунт amoCRM', '30119215' => 'ko:agency', '33022710' => 'partnerskoda (тех)'];
function rj(string $f): array { if (!is_file($f)) return []; $d = json_decode((string) file_get_contents($f), true); return is_array($d) ? $d : []; }
function tg(string $text): void {
    $tok = $_ENV['TG_BOT_TOKEN'] ?? ''; $chat = $_ENV['TG_CHAT_ID'] ?? '';
    if ($tok === '' || $chat === '') { echo "[no-telegram] $text\n"; return; }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 1]), 'timeout' => 10]]);
    $r = @file_get_contents("https://api.telegram.org/bot$tok/sendMessage", false, $ctx);
    echo $r === false ? "[telegram FAILED] $text\n" : "[sent] " . substr($text, 0, 60) . "\n";
}
if ($mode === '--test') { tg("✅ Распределение: мониторинг подключён. " . $now->format('d.m H:i')); exit; }

$sched  = new DealDist\Distribution\ScheduleChecker();
$status = new DealDist\Distribution\StatusStorage();
$report = []; $alerts = [];
foreach (glob("$S/settings/*.json") as $f) {
    if (!preg_match('~/(\d+)\.json$~', $f, $m)) continue;
    $acc = $m[1]; $name = $names[$acc] ?? $acc;
    $regRec = (new DealDist\Accounts\AccountRegistry())->get($acc);
    if (!empty($regRec['uninstalled_at']) || !empty($regRec['revoked_at'])) continue;   // виджет удалён — не алертим
    if (!empty($regRec['name']) && !isset($names[$acc])) $name = $regRec['name'];
    $set = rj($f); $rules = $set['rules'] ?? [];
    $tok = rj("$S/tokens/$acc.json");
    $log = []; $lf = "$S/logs/$acc.ndjson";
    if (is_file($lf)) { foreach (array_slice(file($lf, FILE_IGNORE_NEW_LINES), -400) as $l) { $e = json_decode($l, true); if ($e) $log[] = $e; } }
    $today0 = (clone $now)->setTime(0, 0)->getTimestamp();
    $todayLog = array_filter($log, fn($e) => ($e['ts'] ?? 0) >= $today0);
    $lastTs = $log ? max(array_column($log, 'ts')) : 0;
    $cnt = []; foreach ($todayLog as $e) { $cnt[$e['reason'] ?? '?'] = ($cnt[$e['reason'] ?? '?'] ?? 0) + 1; }
    $pending = rj("$S/pending/$acc.json"); $waiting = array_filter($pending, fn($e) => ($e['type'] ?? '') === 'waiting');
    $oldestWait = $waiting ? min(array_column($waiting, 'queued_at')) : null;
    // менеджеры правил: online / на смене
    $mgr = []; foreach ($rules as $r) foreach ($r['managers'] ?? [] as $mm) { $id = (int) (is_array($mm) ? ($mm['id'] ?? 0) : $mm); if ($id) $mgr[$id] = $r; }
    $online = 0; $onShift = 0;
    foreach ($mgr as $id => $r) { $o = $status->isOnline($acc, $id); if ($o) $online++; if ($o && (empty($r['check_schedule']) || $sched->isAvailable($acc, $id))) $onShift++; }
    $probs = [];
    if (!$rules) $probs[] = 'нет правил распределения';
    if (!$tok) $probs[] = 'нет токена amoCRM';
    if ($rules && $mgr && $online === 0) $probs[] = 'все менеджеры выключены';
    $h = (int) $now->format('G');
    if ($rules && $lastTs && $ts - $lastTs > 6 * 3600 && $h >= 10 && $h <= 20 && $acc !== '33258634') $probs[] = 'нет событий > 6 ч в рабочее время';
    if ($oldestWait && $onShift > 0 && $ts - $oldestWait > 30 * 60) $probs[] = 'очередь не раздана: ' . count($waiting) . ' сделок, есть менеджер на смене';
    if ($oldestWait && $ts - $oldestWait > 24 * 3600) $probs[] = 'в очереди > 24 ч: ' . count($waiting);
    foreach (array_slice(array_reverse($log), 0, 30) as $e) { if (str_contains((string) ($e['reason'] ?? ''), 'error')) { $probs[] = 'ошибки в логе'; break; } }
    $report[] = ['acc' => $acc, 'name' => $name, 'cnt' => $cnt, 'waiting' => count($waiting), 'online' => $online, 'onShift' => $onShift, 'mgr' => count($mgr), 'probs' => $probs, 'last' => $lastTs];
    foreach ($probs as $p) $alerts[] = [$acc, $name, $p];
}
$stF = "$S/alerts/state.json"; @mkdir("$S/alerts", 0775, true); $st = rj($stF); $sent = 0;
foreach ($alerts as [$acc, $name, $p]) {
    $k = md5($acc . '|' . $p);
    if (($st[$k] ?? 0) > $ts - 6 * 3600) continue;
    tg("⚠️ <b>$name</b>\n$p\n" . $now->format('d.m H:i')); $st[$k] = $ts; $sent++;
}
foreach ($st as $k => $t) if ($t < $ts - 86400) unset($st[$k]);
file_put_contents($stF, json_encode($st));
// ── Клиенты: установки, застрявшие, отключения (05.11.2026) ──────────────
$reg = new DealDist\Accounts\AccountRegistry();
$onb = ['new' => [], 'stuck' => [], 'gone' => []];
foreach ($reg->all() as $acc => $rec) {
    if (DealDist\Accounts\AccountRegistry::isInternal((string) $acc, $rec)) continue;
    $stg  = $reg->stage((string) $acc, $rec);
    $who  = (($rec['name'] ?? '') ?: ($rec['domain'] ?? $acc)) . (!empty($rec['installer']['email']) ? ' · ' . $rec['installer']['email'] : '');
    $inst = (int) ($rec['installed_at'] ?? 0);
    if ($inst > $ts - 86400 && empty($rec['backfilled'])) $onb['new'][] = $who;
    if ($stg['stuck']) {
        $why = ['installed' => 'не открывал виджет', 'opened' => 'открыл, но нет правил', 'rules' => 'правила есть, распределений нет'][$stg['stage']] ?? $stg['stage'];
        $onb['stuck'][] = $who . ' — ' . $why . ', ' . (int) floor(($ts - $inst) / 86400) . ' дн.';
        $k = md5($acc . '|stuck|' . $stg['stage']);
        if (($st[$k] ?? 0) < $ts - 3 * 86400) { tg("⏳ <b>Клиент застрял</b>\n" . htmlspecialchars($who) . "\n" . $why); $st[$k] = $ts; }
    }
    $gone = max((int) ($rec['uninstalled_at'] ?? 0), (int) ($rec['revoked_at'] ?? 0));
    if ($gone > $ts - 86400) $onb['gone'][] = $who;
}
file_put_contents($stF, json_encode($st));
if ($mode === '--report') {
    $lines = ["📊 <b>Распределение — " . $now->format('d.m') . "</b>"];
    if ($onb['new'])   $lines[] = "🆕 Новые установки: " . htmlspecialchars(implode('; ', $onb['new']));
    if ($onb['stuck']) $lines[] = "⏳ Застряли:\n• " . htmlspecialchars(implode("\n• ", $onb['stuck']));
    if ($onb['gone'])  $lines[] = "🔻 Отключили: " . htmlspecialchars(implode('; ', $onb['gone']));
    foreach ($report as $r) {
        $a = ($r['cnt']['assigned'] ?? 0) + ($r['cnt']['history_match'] ?? 0);
        $q = $r['cnt']['queued'] ?? 0; $s = ($r['cnt']['skipped_schedule'] ?? 0) + ($r['cnt']['skipped_offline'] ?? 0);
        $l = "• <b>{$r['name']}</b>: роздано $a, в очереди {$r['waiting']}" . ($q ? ", ночью $q" : '') . ($s ? ", пропущено $s" : '') . " · на смене {$r['onShift']}/{$r['mgr']}";
        if ($r['probs']) $l .= "\n  ❗ " . implode('; ', $r['probs']);
        $lines[] = $l;
    }
    tg(implode("\n", $lines));
}
echo "checked " . count($report) . " accounts, alerts sent $sent\n";
