<?php
declare(strict_types=1);
/**
 * Мониторинг здоровья аккаунтов + утренний отчёт. Cron: раз в 15 мин (алерты) и в 09:15 МСК (отчёт).
 *   php /app/bin/health-check.php            — проверить и отправить только новые алерты
 *   php /app/bin/health-check.php --report   — дневной отчёт по всем аккаунтам
 *   php /app/bin/health-check.php --test     — тестовое сообщение
 * Telegram: TG_BOT_TOKEN / TG_CHAT_ID в .env. Если не заданы — пишет в stdout/лог.
 * Тревога по проблеме - один раз, при исчезновении - одно сообщение «восстановилось» (STORAGE/alerts/state.json, ключ 'active').
 * Формат (07.11.2026): «значок Распределение лидов · клиент» / Что / Чем грозит / Что делать / ссылка / время МСК.
 */
require __DIR__ . '/../vendor/autoload.php';
try { \Dotenv\Dotenv::createImmutable('/app')->safeLoad(); } catch (\Throwable $e) {}
$S = rtrim($_ENV['STORAGE_PATH'] ?? getenv('STORAGE_PATH') ?: '/storage', '/');
$tz = new DateTimeZone('Europe/Moscow'); $now = new DateTime('now', $tz); $ts = time();
$mode = $argv[1] ?? '';
$names = ['32722686' => 'RE:source (клиника)', '7275562' => 'Кит Тех', '33258634' => 'Тест-аккаунт amoCRM', '30119215' => 'ko:agency (наш аккаунт)', '33022710' => 'partnerskoda (тех)', '36652395' => 'EVERY'];
// Аккаунты, где пустые правила - норма: не тревога, а строка в сводке
$noRulesOk = ['36652395' => 'правил нет, лиды раздаёт NOVA - так задумано'];
function rj(string $f): array { if (!is_file($f)) return []; $d = json_decode((string) file_get_contents($f), true); return is_array($d) ? $d : []; }
function tg(string $text): void {
    $tok = $_ENV['TG_BOT_TOKEN'] ?? ''; $chat = $_ENV['TG_CHAT_ID'] ?? '';
    if ($tok === '' || $chat === '') { echo "[no-telegram] $text\n"; return; }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 1]), 'timeout' => 10]
        // api.telegram.org заблокирован из РФ — ходим через xray-релей на хосте (TG_PROXY=tcp://172.19.0.1:18080)
        + (($p = (string) ($_ENV['TG_PROXY'] ?? '')) !== '' ? ['proxy' => $p] : [])]);
    $r = @file_get_contents("https://api.telegram.org/bot$tok/sendMessage", false, $ctx);
    echo $r === false ? "[telegram FAILED] $text\n" : "[sent] " . substr($text, 0, 60) . "\n";
}
if ($mode === '--test') { tg("✅ Распределение: мониторинг подключён. " . $now->format('d.m H:i')); exit; }

$sched  = new DealDist\Distribution\ScheduleChecker();
$status = new DealDist\Distribution\StatusStorage();
$reg    = new DealDist\Accounts\AccountRegistry();
$report = [];
$stF = "$S/alerts/state.json"; @mkdir("$S/alerts", 0775, true); $st = rj($stF);
$active = is_array($st['active'] ?? null) ? $st['active'] : [];
$found  = [];   // key => [acc, name, link, code, icon, title, body]
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
// Короткое человеческое описание ошибки из журнала
function shortErr(string $e): string {
    if (str_contains($e, 'No tokens')) return 'нет токена amoCRM';
    if (str_contains($e, 'cURL error 28')) return 'amoCRM не ответил за 15 секунд (таймаут)';
    if (preg_match('~amoCRM (\d{3})~', $e, $m)) {
        $hint = ['401' => 'нет доступа, токен недействителен', '402' => 'аккаунт amoCRM не оплачен', '403' => 'доступ запрещён', '429' => 'слишком много запросов к amoCRM'][$m[1]] ?? '';
        if (preg_match('~"code":"([A-Za-z]+)"~', $e, $c)) $hint = trim($hint . ' ' . ($c[1] === 'NotSupportedChoice' ? 'значение поля не из списка' : $c[1]));
        return "amoCRM {$m[1]}" . ($hint ? ": $hint" : '');
    }
    return mb_substr(preg_replace('~https?://\S+~', '', $e), 0, 140);
}
foreach (glob("$S/settings/*.json") as $f) {
    if (!preg_match('~/(\d+)\.json$~', $f, $m)) continue;
    $acc = $m[1]; $name = $names[$acc] ?? $acc;
    $regRec = $reg->get($acc);
    if (!empty($regRec['uninstalled_at']) || !empty($regRec['revoked_at'])) continue;   // виджет удалён — не алертим
    if (!empty($regRec['name']) && !isset($names[$acc])) $name = $regRec['name'];
    $link = !empty($regRec['domain']) ? 'https://' . $regRec['domain'] . '/leads/list/' : '';
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
    $probs = [];   // code => [icon, короткое название, Что / Чем грозит / Что делать]
    $info = null;
    if (!$rules) {
        if (isset($noRulesOk[$acc])) $info = $noRulesOk[$acc];
        else $probs['no_rules'] = ['⚠️', 'нет правил распределения', "Что: в виджете нет ни одного правила\nЧем грозит: новые сделки никому не назначаются\nЧто делать: открыть настройки виджета и создать правило"];
    }
    if (!$tok) $probs['no_token'] = ['🔴', 'нет доступа к amoCRM', "Что: у виджета нет токена amoCRM\nЧем грозит: распределение не работает совсем\nЧто делать: переподключить виджет в аккаунте (Настройки → Интеграции)"];
    if ($rules && $mgr && $online === 0) $probs['all_off'] = ['⚠️', 'все менеджеры выключены', "Что: все менеджеры из правил выключены в виджете\nЧем грозит: новые сделки копятся в очереди\nЧто делать: проверить, выходной ли сегодня, иначе включить менеджеров"];
    $hr = (int) $now->format('G');
    if ($rules && $lastTs && $ts - $lastTs > 6 * 3600 && $hr >= 10 && $hr <= 20 && $acc !== '33258634')
        $probs['no_events'] = ['⚠️', 'нет новых сделок > 6 ч', "Что: за 6 рабочих часов ни одной новой сделки\nВозможные причины: нет заявок или отвалилась интеграция, создающая сделки\nЧто делать: проверить, появляются ли новые сделки в amoCRM"];
    // Ночная очередь раздаётся в первые минуты смены, поэтому возраст сделки в очереди сам по себе ничего не говорит.
    // Тревога, только если очередь стоит при менеджере на смене две проверки подряд (>= 14 мин). 07.11.2026
    $qk = 'qstuck|' . $acc;
    if ($oldestWait && $onShift > 0 && $ts - $oldestWait > 30 * 60) {
        if (empty($st[$qk])) $st[$qk] = $ts;
        if ($ts - $st[$qk] >= 14 * 60) $probs['queue_stuck'] = ['🔴', 'очередь не раздаётся', 'Что: в очереди ' . count($waiting) . ' сделок дольше 30 мин, на смене ' . $onShift . ' из ' . count($mgr) . " менеджеров\nЧем грозит: заявки ждут ответа\nЧто делать: проверить журнал виджета и статусы менеджеров, при необходимости раздать вручную"];
    } else unset($st[$qk]);
    if ($oldestWait && $ts - $oldestWait > 24 * 3600) $probs['queue_24h'] = ['🔴', 'сделки в очереди больше суток', 'Что: ' . count($waiting) . " сделок ждут в очереди больше суток\nЧем грозит: заявки остались без менеджера\nЧто делать: раздать вручную и проверить смены менеджеров"];
    // Ошибки: только за последние 6 ч (раньше - любая из последних 30 записей, из-за этого старая ошибка «висела» сутками)
    $errs = array_values(array_filter($log, fn($e) => ($e['ts'] ?? 0) > $ts - 6 * 3600 && str_contains((string) ($e['reason'] ?? ''), 'error')));
    if ($errs) {
        $lastErr = shortErr((string) (end($errs)['error'] ?? ''));
        $probs['errors'] = ['⚠️', 'ошибки в журнале', 'Что: ошибок в журнале виджета за 6 ч: ' . count($errs) . ', последняя: "' . $lastErr . "\"\nЧем грозит: часть сделок могла остаться без менеджера\nЧто делать: проверить журнал и нераспределённые сделки"];
    }
    $report[] = ['acc' => $acc, 'name' => $name, 'cnt' => $cnt, 'waiting' => count($waiting), 'online' => $online, 'onShift' => $onShift, 'mgr' => count($mgr), 'probs' => $probs, 'info' => $info, 'last' => $lastTs];
    foreach ($probs as $code => $p) $found["$acc|$code"] = [$acc, $name, $link, $code, ...$p];
}
$sent = 0; $time = $now->format('d.m H:i') . ' МСК';
// Новые проблемы: одно сообщение
foreach ($found as $k => [$acc, $name, $link, $code, $icon, $title, $body]) {
    if (isset($active[$k])) continue;
    tg("$icon <b>Распределение лидов · " . $h($name) . "</b>\n" . $h($body) . ($link ? "\n$link" : '') . "\n$time");
    $active[$k] = ['ts' => $ts, 'name' => $name, 'title' => $title]; $sent++;
}
// Исчезнувшие проблемы: одно сообщение «восстановилось»
foreach ($active as $k => $a) {
    if (isset($found[$k])) continue;
    $acc = explode('|', (string) $k)[0];
    $rr = $reg->get($acc);
    if (empty($rr['uninstalled_at']) && empty($rr['revoked_at']))
        tg("✅ <b>Распределение лидов · " . $h((string) $a['name']) . "</b>\nВосстановилось: " . $h((string) $a['title']) . ' (было с ' . (new DateTime('@' . (int) $a['ts']))->setTimezone($tz)->format('d.m H:i') . ")\n$time");
    unset($active[$k]); $sent++;
}
foreach ($st as $k => $t) if ($k !== 'active' && is_int($t) && $t < $ts - 86400) unset($st[$k]);
$st['active'] = $active;
// ── Клиенты: установки, незавершённые установки, отключения (05.11.2026). Только в сводке, без отдельных сообщений (07.11.2026)
$onb = ['new' => [], 'stuck' => [], 'gone' => []];
foreach ($reg->all() as $acc => $rec) {
    if (DealDist\Accounts\AccountRegistry::isInternal((string) $acc, $rec)) continue;
    $stg  = $reg->stage((string) $acc, $rec);
    $who  = (($rec['name'] ?? '') ?: ($rec['domain'] ?? $acc)) . (!empty($rec['installer']['email']) ? ' (' . $rec['installer']['email'] . ')' : '');
    $inst = (int) ($rec['installed_at'] ?? 0);
    if ($inst > $ts - 86400 && empty($rec['backfilled'])) $onb['new'][] = $who;
    if ($stg['stuck']) {
        $why = ['installed' => 'настройки не открывали, распределение не работает', 'opened' => 'настройки открыли, но правил нет, распределение не работает', 'rules' => 'правила есть, но ни одной сделки не роздано'][$stg['stage']] ?? $stg['stage'];
        $onb['stuck'][] = $who . ' - установили ' . (int) floor(($ts - $inst) / 86400) . ' дн. назад, ' . $why;
    }
    $gone = max((int) ($rec['uninstalled_at'] ?? 0), (int) ($rec['revoked_at'] ?? 0));
    if ($gone > $ts - 86400) $onb['gone'][] = $who;
}
file_put_contents($stF, json_encode($st, JSON_UNESCAPED_UNICODE));
if ($mode === '--report') {
    $lines = ["📊 <b>Распределение лидов - сводка по клиентам · " . $now->format('d.m') . "</b>"];
    foreach ($report as $r) {
        $a = ($r['cnt']['assigned'] ?? 0) + ($r['cnt']['history_match'] ?? 0);
        $q = $r['cnt']['queued'] ?? 0; $s = ($r['cnt']['skipped_schedule'] ?? 0) + ($r['cnt']['skipped_offline'] ?? 0);
        if ($r['info'] !== null) { $lines[] = 'ℹ️ <b>' . $h($r['name']) . '</b>: ' . $h($r['info']); continue; }
        $l = '• <b>' . $h($r['name']) . "</b>: роздано $a, в очереди {$r['waiting']}" . ($q ? ", ночью $q" : '') . ($s ? ", пропущено $s" : '') . " · на смене {$r['onShift']}/{$r['mgr']}";
        $l .= $r['probs'] ? "\n  ❗ " . $h(implode('; ', array_column($r['probs'], 1))) : "\n  ✅ всё в порядке";
        $lines[] = $l;
    }
    if ($onb['new'])   $lines[] = "🆕 Новые установки: " . $h(implode('; ', $onb['new']));
    if ($onb['stuck']) $lines[] = "⏳ Установка не завершена:\n• " . $h(implode("\n• ", $onb['stuck'])) . "\nЧто делать: написать клиенту, предложить помощь";
    if ($onb['gone'])  $lines[] = "🔻 Отключили виджет: " . $h(implode('; ', $onb['gone']));
    tg(implode("\n", $lines));
}
echo "checked " . count($report) . " accounts, messages sent $sent\n";
