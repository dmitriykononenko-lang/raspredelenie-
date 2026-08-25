<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Админка KO:AGENCY — единая панель по виджетам.
 *
 * Маршруты (вне /api/, поэтому AuthMiddleware их не трогает — своя проверка ADMIN_TOKEN):
 *   GET /admin                 — HTML-страница (ключ в ?key=)
 *   GET /admin/api/overview    — JSON-агрегат по всем аккаунтам Распределения
 *
 * Данные читаются напрямую из STORAGE_PATH (settings/statuses/tokens/logs/schedules/pending).
 * Секреты (токены) наружу не отдаются — только факт наличия и срок.
 */
class AdminController
{
    private string $storage;

    public function __construct()
    {
        $this->storage = rtrim($_ENV['STORAGE_PATH'] ?? '/storage', '/');
    }

    private function authorized(ServerRequestInterface $request): bool
    {
        $expected = (string) ($_ENV['ADMIN_TOKEN'] ?? '');
        if ($expected === '') {
            return false;
        }
        $q = $request->getQueryParams();
        $provided = (string) ($q['key'] ?? '');
        if ($provided === '') {
            $provided = $request->getHeaderLine('X-Admin-Token');
        }
        return hash_equals($expected, $provided);
    }

    /* --------------------------- API: overview --------------------------- */

    public function overview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            $response->getBody()->write(json_encode(['error' => 'unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $ltAccount = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        $ltDomain  = trim((string) ($_ENV['AMO_BASE_DOMAIN'] ?? ''));
        $ltToken   = trim((string) ($_ENV['AMO_LONG_TERM_TOKEN'] ?? ''));

        $accounts = [];
        foreach ($this->accountIds() as $acc) {
            $settings = $this->readJson($this->storage . '/settings/' . $acc . '.json');
            $statuses = $this->readJson($this->storage . '/statuses/' . $acc . '.json');
            $tokenF   = $this->storage . '/tokens/' . $acc . '.json';
            $tokenD   = $this->readJson($tokenF);

            // домен
            $domain = '';
            if (!empty($tokenD['base_domain'])) {
                $domain = (string) $tokenD['base_domain'];
            } elseif ($ltAccount !== '' && $ltAccount === (string) $acc && $ltDomain !== '') {
                $domain = $ltDomain;
            }

            // токен
            $tokenPresent = false;
            $tokenKind    = 'none';
            $tokenExp     = null;
            if (is_file($tokenF)) {
                $tokenPresent = true;
                $tokenKind    = !empty($tokenD['long_term']) ? 'long_term' : 'oauth';
                $tokenExp     = $this->jwtExp($tokenD['access_token'] ?? '');
            } elseif ($ltToken !== '' && ($ltAccount === '' || $ltAccount === (string) $acc)) {
                $tokenPresent = true;
                $tokenKind    = 'env_long_term';
            }

            // правила / менеджеры
            $rules    = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
            $managers = [];
            foreach ($rules as $r) {
                foreach (($r['managers'] ?? []) as $m) {
                    $id = is_array($m) ? ($m['id'] ?? null) : $m;
                    if ($id !== null) { $managers[(string) $id] = true; }
                }
            }

            // онлайн
            $onlineIds = [];
            $totalUsers = 0;
            if (is_array($statuses)) {
                foreach ($statuses as $uid => $st) {
                    $totalUsers++;
                    if (!empty($st['online'])) { $onlineIds[] = (string) $uid; }
                }
            }

            // лог распределения (последние 25)
            $log = $this->tailNdjson($this->storage . '/logs/' . $acc . '.ndjson', 25);
            $lastReason = $log ? ($log[count($log) - 1]['reason'] ?? '') : '';
            $lastTs     = $log ? ($log[count($log) - 1]['ts'] ?? 0) : 0;

            // повторное распределение (pending)
            $pending = $this->readJson($this->storage . '/pending/' . $acc . '.json');
            $pendingCount = is_array($pending) ? count($pending) : 0;

            // расписания
            $schedDir = $this->storage . '/schedules/' . $acc;
            $schedCount = is_dir($schedDir) ? count(glob($schedDir . '/*.json') ?: []) : 0;

            $accounts[] = [
                'account_id'    => (string) $acc,
                'domain'        => $domain,
                'token'         => ['present' => $tokenPresent, 'kind' => $tokenKind, 'exp' => $tokenExp],
                'method'        => (string) ($settings['distribution_method'] ?? '—'),
                'rules_count'   => count($rules),
                'managers_count'=> count($managers),
                'managers'      => array_keys($managers),
                'online_count'  => count($onlineIds),
                'online_ids'    => $onlineIds,
                'users_total'   => $totalUsers,
                'schedules'     => $schedCount,
                'pending'       => $pendingCount,
                'last_reason'   => $lastReason,
                'last_ts'       => $lastTs,
                'log'           => $log,
            ];
        }

        usort($accounts, static fn($a, $b) => ($b['last_ts'] <=> $a['last_ts']));

        $out = [
            'generated_at' => time(),
            'widgets'      => [
                'raspredelenie' => [
                    'title'    => 'Распределение',
                    'accounts' => $accounts,
                ],
                'dubli' => [
                    'title'     => 'Дубли',
                    'available' => false,
                    'note'      => 'Подключается: нужна связка с БД Дублей (pgsql + сеть).',
                    'accounts'  => [],
                ],
            ],
        ];

        $response->getBody()->write(json_encode($out, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /* ----------------------------- helpers ------------------------------ */

    /** @return string[] уникальные account_id из всех подпапок storage */
    private function accountIds(): array
    {
        $ids = [];
        foreach (['settings', 'statuses', 'tokens', 'logs', 'pending'] as $dir) {
            foreach (glob($this->storage . '/' . $dir . '/*') ?: [] as $p) {
                $b = basename($p);
                $b = preg_replace('/\.(json|ndjson)$/', '', $b);
                $b = preg_replace('/\.history$/', '', (string) $b);
                if (preg_match('/^\d+$/', (string) $b)) { $ids[$b] = true; }
            }
        }
        foreach (glob($this->storage . '/schedules/*', GLOB_ONLYDIR) ?: [] as $p) {
            $b = basename($p);
            if (preg_match('/^\d+$/', $b)) { $ids[$b] = true; }
        }
        // долгосрочный аккаунт из env всегда показываем
        $lt = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        if ($lt !== '' && preg_match('/^\d+$/', $lt)) { $ids[$lt] = true; }
        return array_keys($ids);
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) { return null; }
        $d = json_decode((string) file_get_contents($file), true);
        return is_array($d) ? $d : null;
    }

    /** @return array<int,array> */
    private function tailNdjson(string $file, int $limit): array
    {
        if (!is_file($file)) { return []; }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach ($lines as $l) {
            $d = json_decode($l, true);
            if (is_array($d)) { $out[] = $d; }
        }
        return $out;
    }

    private function jwtExp(string $jwt): ?int
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) { return null; }
        $payload = json_decode($this->b64url($parts[1]), true);
        return is_array($payload) && isset($payload['exp']) ? (int) $payload['exp'] : null;
    }

    private function b64url(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) { $s .= str_repeat('=', 4 - $pad); }
        return (string) base64_decode($s);
    }

    /* ------------------------------- page ------------------------------- */

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authorized($request)) {
            $response->getBody()->write($this->loginHtml());
            return $response->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        $response->getBody()->write($this->appHtml());
        return $response->withStatus(200)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function loginHtml(): string
    {
        return <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KO:AGENCY — Админка</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#ececec;
  font-family:'IBM Plex Sans',Arial,sans-serif;letter-spacing:-0.02em;color:#141414;padding:24px}
.box{width:400px;max-width:100%;background:#fff;border:1.5px solid #141414;border-radius:12px;padding:40px 40px 36px}
.brand{font-family:'IBM Plex Mono',monospace;font-size:22px;font-weight:600;letter-spacing:0.02em}
.eyebrow{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:500;letter-spacing:0.08em;
  text-transform:uppercase;color:#8a8a8a;margin-top:8px}
.divider{height:1.5px;background:#141414;margin:22px 0 24px}
label{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:600;letter-spacing:0.06em;
  text-transform:uppercase;color:#141414;display:block;margin-bottom:8px}
input{width:100%;padding:12px 14px;border:1.5px solid #cfcfcf;border-radius:8px;font-size:15px;
  font-family:'IBM Plex Sans',sans-serif;color:#141414;outline:none;transition:border-color .15s}
input:focus{border-color:#141414}
button{width:100%;margin-top:16px;padding:13px;border:0;border-radius:8px;background:#d22730;color:#fff;
  font-size:13px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;
  font-family:'IBM Plex Mono',monospace;cursor:pointer;transition:background .15s}
button:hover{background:#b91f28}
</style></head>
<body>
<form class="box" method="get" action="/admin">
  <div class="brand">KO&nbsp;:&nbsp;AGENCY</div>
  <div class="eyebrow">Админка виджетов · доступ по ключу</div>
  <div class="divider"></div>
  <label for="key">Ключ доступа</label>
  <input id="key" name="key" type="password" placeholder="••••••••••••" autofocus autocomplete="off">
  <button type="submit">Войти</button>
</form>
</body></html>
HTML;
    }

    private function appHtml(): string
    {
        // Страница читает ключ из ?key= и дергает /admin/api/overview.
        return <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KO:AGENCY — Админка виджетов</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#141414;--red:#d22730;--red-dark:#b91f28;--muted:#8a8a8a;--line:#141414;--soft:#dcdcdc;--page:#ececec;--card:#fff}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font-family:'IBM Plex Sans',Arial,sans-serif;letter-spacing:-0.02em;font-size:14px;line-height:1.5}
.mono{font-family:'IBM Plex Mono',monospace;letter-spacing:0}
.wrap{max-width:1200px;margin:0 auto;padding:36px 28px 72px}
/* header */
.top{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;border-bottom:1.5px solid var(--line);padding-bottom:18px}
.brand{font-family:'IBM Plex Mono',monospace;font-size:22px;font-weight:600;letter-spacing:0.02em}
.eyebrow{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:500;letter-spacing:0.07em;text-transform:uppercase;color:var(--muted);margin-top:6px}
.top-r{display:flex;align-items:center;gap:16px}
.gen{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:0.04em;color:var(--muted);text-transform:uppercase;text-align:right}
.refresh{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;padding:9px 16px;border:1.5px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;color:var(--ink);transition:.15s}
.refresh:hover{background:var(--ink);color:#fff}
/* tabs */
.tabs{display:flex;gap:28px;margin:28px 0 22px}
.tab{appearance:none;border:0;background:none;padding:0 0 10px;font-family:'IBM Plex Mono',monospace;font-size:13px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent}
.tab.on{color:var(--ink);border-bottom-color:var(--red)}
.tab:hover{color:var(--ink)}
.tab .n{color:var(--red);margin-left:6px}
/* kpis */
.kpis{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:26px}
.kpi{background:var(--card);border:1.5px solid var(--line);border-radius:8px;padding:16px 20px;min-width:150px;flex:1}
.kpi b{display:block;font-family:'IBM Plex Mono',monospace;font-size:34px;font-weight:600;line-height:1;letter-spacing:-0.03em}
.kpi.red b{color:var(--red)}
.kpi span{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;color:var(--muted);margin-top:10px;display:block}
/* card */
.card{background:var(--card);border:1.5px solid var(--line);border-radius:8px;padding:22px 24px;margin-bottom:16px}
.card-h{display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap}
.card-h h3{margin:0;font-size:21px;font-weight:600;letter-spacing:-0.03em}
.card-h .idx{font-family:'IBM Plex Mono',monospace;font-size:12px;font-weight:600;color:var(--red);letter-spacing:0.04em}
.dom{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:0.03em;text-transform:uppercase;color:var(--muted);margin-top:8px}
.row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px}
.pill{display:inline-flex;align-items:center;gap:7px;border:1.5px solid var(--line);border-radius:20px;padding:5px 13px;font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase}
.pill .dot{width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block}
.pill.red{border-color:var(--red);color:var(--red)}
.pill.mut{border-color:var(--soft);color:var(--muted)}
.pill.solid{background:var(--red);border-color:var(--red);color:#fff}
/* table */
details{margin-top:16px}
details summary{cursor:pointer;font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--ink);list-style:none;display:inline-flex;align-items:center;gap:8px}
details summary::-webkit-details-marker{display:none}
details summary::before{content:'+';color:var(--red);font-weight:600}
details[open] summary::before{content:'–'}
table{width:100%;border-collapse:collapse;margin-top:14px;font-size:12.5px}
th{font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--muted);text-align:left;padding:6px 10px;border-bottom:1.5px solid var(--line)}
td{text-align:left;padding:8px 10px;border-bottom:1px solid #ededed}
td.mono{font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--muted)}
.rsn{font-weight:600;display:inline-flex;align-items:center;gap:6px}
.rsn::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.rsn.ok{color:var(--ink)} .rsn.ok::before{background:var(--red)}
.rsn.skip{color:var(--muted)}
.rsn.err{color:var(--red)}
.muted{color:var(--muted)}
.empty{color:var(--muted);padding:16px 2px;font-family:'IBM Plex Mono',monospace;font-size:12px;letter-spacing:0.03em}
.logempty{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:0.04em;text-transform:uppercase;color:var(--muted);margin-top:14px}
.note{background:var(--card);border:1.5px dashed var(--soft);border-radius:8px;padding:22px 24px;color:var(--ink)}
.note .lbl{display:inline-block;font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:600;letter-spacing:0.07em;text-transform:uppercase;color:var(--red);border:1.5px solid var(--red);border-radius:6px;padding:4px 9px;margin-bottom:12px}
.note b{font-weight:600}
.err{border:1.5px solid var(--red);color:var(--red);padding:14px 16px;border-radius:8px;font-size:13px}
</style></head>
<body><div class="wrap">
<div class="top">
  <div><div class="brand">KO&nbsp;:&nbsp;AGENCY</div><div class="eyebrow">Админка виджетов · панель управления</div></div>
  <div class="top-r"><div class="gen" id="gen"></div><button class="refresh" id="refresh">↻ Обновить</button></div>
</div>
<div class="tabs">
  <button class="tab on" data-t="raspredelenie">Распределение<span class="n" id="tabn"></span></button>
  <button class="tab" data-t="dubli">Дубли</button>
</div>
<div id="content"><div class="empty">Загрузка…</div></div>
</div>
<script>
var KEY=new URLSearchParams(location.search).get('key')||'';
var DATA=null, TAB='raspredelenie';
function fmtTs(t){ if(!t) return '—'; try{var d=new Date(t*1000);return d.toLocaleString('ru-RU');}catch(e){return String(t);} }
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
var RSN={assigned:'Назначена',redistributed:'Перераспределена',skipped_no_rule:'Нет правила',skipped_offline:'Все офлайн',skipped_schedule:'Вне расписания',history_match:'По истории',error:'Ошибка',no_managers:'Нет менеджеров'};
function tokenPill(t){
  if(!t.present) return '<span class="pill red"><span class="dot"></span>токен: нет</span>';
  var kind = t.kind==='oauth'?'OAuth':(t.kind==='long_term'?'долгосрочный':(t.kind==='env_long_term'?'env':'—'));
  var exp = t.exp? ' · до '+new Date(t.exp*1000).toLocaleDateString('ru-RU') : '';
  return '<span class="pill"><span class="dot"></span>токен '+kind+exp+'</span>';
}
function rulePill(a){ return a.rules_count? '<span class="pill">правил '+a.rules_count+' · менеджеров '+a.managers_count+'</span>' : '<span class="pill red">правил нет</span>'; }
function onlinePill(a){ return '<span class="pill '+(a.online_count?'solid':'mut')+'">онлайн '+a.online_count+'/'+a.users_total+'</span>'; }
function reasonCls(r){ r=r||''; if(r==='error') return 'rsn err'; if(r.indexOf('skip')===0||r==='no_managers') return 'rsn skip'; return 'rsn ok'; }
function render(){
  var c=document.getElementById('content');
  if(!DATA){ c.innerHTML='<div class="empty">Загрузка…</div>'; return; }
  var g=document.getElementById('gen'); if(g&&DATA.generated_at){ g.textContent='обновлено '+new Date(DATA.generated_at*1000).toLocaleTimeString('ru-RU'); }
  var w=DATA.widgets[TAB];
  if(TAB==='dubli'){ c.innerHTML='<div class="note"><span class="lbl">Скоро</span><br><b>Дубли</b> — раздел подключается. '+esc(w.note||'')+'</div>'; return; }
  var accs=w.accounts||[];
  var tn=document.getElementById('tabn'); if(tn){ tn.textContent=accs.length; }
  var tokOk=accs.filter(function(a){return a.token.present;}).length;
  var noRule=accs.filter(function(a){return !a.rules_count;}).length;
  var onl=accs.reduce(function(s,a){return s+a.online_count;},0);
  var html='<div class="kpis">'
    +'<div class="kpi"><b>'+accs.length+'</b><span>аккаунтов</span></div>'
    +'<div class="kpi"><b>'+tokOk+'</b><span>с токеном</span></div>'
    +'<div class="kpi"><b>'+noRule+'</b><span>без правила</span></div>'
    +'<div class="kpi red"><b>'+onl+'</b><span>онлайн сейчас</span></div>'
    +'</div>';
  if(!accs.length){ html+='<div class="empty">Нет аккаунтов.</div>'; c.innerHTML=html; return; }
  accs.forEach(function(a,i){
    var idx=('0'+(i+1)).slice(-2);
    html+='<div class="card"><div class="card-h"><h3>'+esc(a.domain||('account '+a.account_id))+'</h3><span class="idx">'+idx+'</span></div>'
      +'<div class="dom">id '+esc(a.account_id)+' · метод '+esc(a.method)+' · расписаний '+a.schedules+(a.pending?(' · повтор в очереди '+a.pending):'')+'</div>'
      +'<div class="row">'+tokenPill(a.token)+rulePill(a)+onlinePill(a)+'</div>';
    if(a.log && a.log.length){
      html+='<details><summary>Лог распределения · '+a.log.length+'</summary><table><tr><th>Время</th><th>Сделка</th><th>Этап</th><th>Менеджер</th><th>Причина</th></tr>';
      a.log.slice().reverse().forEach(function(e){
        html+='<tr><td class="mono">'+fmtTs(e.ts)+'</td><td>'+esc(e.lead_id||'—')+'</td><td class="mono">'+esc(e.stage_id||'—')+'</td><td>'+esc(e.manager_id||'—')+'</td><td><span class="'+reasonCls(e.reason)+'">'+esc(RSN[e.reason]||e.reason||'—')+'</span></td></tr>';
      });
      html+='</table></details>';
    } else { html+='<div class="logempty">Лог пуст</div>'; }
    html+='</div>';
  });
  c.innerHTML=html;
}
function load(){
  var c=document.getElementById('content'); c.innerHTML='<div class="empty">Загрузка…</div>';
  fetch('/admin/api/overview?key='+encodeURIComponent(KEY)).then(function(r){
    if(!r.ok) throw new Error('HTTP '+r.status); return r.json();
  }).then(function(j){ DATA=j; render(); }).catch(function(e){
    c.innerHTML='<div class="err">Ошибка загрузки: '+esc(e.message)+'. Проверьте ключ доступа.</div>';
  });
}
document.querySelectorAll('.tab').forEach(function(t){ t.addEventListener('click',function(){
  document.querySelectorAll('.tab').forEach(function(x){x.classList.remove('on');}); t.classList.add('on'); TAB=t.dataset.t; render();
});});
document.getElementById('refresh').addEventListener('click',load);
load();
</script></body></html>
HTML;
    }
}
