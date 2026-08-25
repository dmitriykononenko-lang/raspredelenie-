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
        return '<!doctype html><meta charset="utf-8"><title>KO:AGENCY — Админка</title>'
            . '<div style="font:15px/1.5 Roboto,Arial,sans-serif;color:#141414;display:flex;align-items:center;'
            . 'justify-content:center;height:100vh;margin:0;background:#f6f8fc">'
            . '<div style="text-align:center;background:#fff;border:1px solid #e7e9ec;border-radius:12px;padding:28px 32px">'
            . '<div style="font-size:20px;font-weight:700;color:#d22730;margin-bottom:6px">KO:AGENCY</div>'
            . '<div style="color:#8b95a7;margin-bottom:14px">Админка · требуется ключ доступа</div>'
            . '<form method="get" action="/admin"><input name="key" type="password" placeholder="Ключ доступа" '
            . 'style="padding:10px 12px;border:1px solid #e1e3e7;border-radius:8px;font-size:14px;width:220px" autofocus>'
            . '<button style="margin-left:8px;padding:10px 18px;border:0;border-radius:8px;background:#d22730;color:#fff;'
            . 'font-weight:600;cursor:pointer">Войти</button></form></div></div>';
    }

    private function appHtml(): string
    {
        // Страница читает ключ из ?key= и дергает /admin/api/overview.
        return <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KO:AGENCY — Админка виджетов</title>
<style>
:root{--ink:#141414;--ink2:#586173;--muted:#8b95a7;--red:#d22730;--red-soft:#fdebec;--work:#f6f8fc;--br:#e7e9ec;--br2:#eef2f8;--ok:#1f9d57;--warn:#e0a500}
*{box-sizing:border-box}
body{margin:0;background:var(--work);color:var(--ink);font:14px/1.5 "IBM Plex Sans",Roboto,Arial,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:20px 18px 60px}
.top{display:flex;align-items:center;gap:12px;margin:6px 0 18px}
.badge{width:26px;height:26px;border-radius:7px;background:var(--red);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:14px}
.brand{font-weight:700}
.brand span{color:var(--muted);font-weight:400}
.refresh{margin-left:auto;padding:7px 14px;border:1px solid var(--br);border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:13px;color:var(--ink)}
.refresh:hover{background:#f5f6f7}
.tabs{display:flex;gap:2px;border-bottom:1px solid var(--br);margin-bottom:18px}
.tab{appearance:none;border:0;background:none;padding:10px 16px;font-size:14px;font-weight:600;color:#7b828b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.tab.on{color:var(--red);border-bottom-color:var(--red)}
.tab:hover{color:var(--ink)}
.kpis{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.kpi{background:#fff;border:1px solid var(--br);border-radius:10px;padding:12px 16px;min-width:130px}
.kpi b{display:block;font-size:22px;font-weight:700}
.kpi span{color:var(--muted);font-size:12px}
.card{background:#fff;border:1px solid var(--br);border-radius:12px;padding:16px 18px;margin-bottom:14px}
.card h3{margin:0 0 2px;font-size:16px}
.card .dom{color:var(--muted);font-size:12px}
.row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:10px}
.pill{display:inline-flex;align-items:center;gap:6px;border-radius:20px;padding:3px 11px;font-size:12px;font-weight:600;border:1px solid var(--br)}
.pill.ok{background:#e6f6ec;color:var(--ok);border-color:transparent}
.pill.bad{background:var(--red-soft);color:var(--red);border-color:transparent}
.pill.warn{background:#fff6e0;color:#a9791b;border-color:transparent}
.pill.mut{background:#f2f4f8;color:var(--ink2);border-color:transparent}
table{width:100%;border-collapse:collapse;margin-top:12px;font-size:12.5px}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--br2)}
th{color:var(--muted);font-weight:600}
td.num{color:var(--red);font-weight:700}
.rsn{font-weight:600}
.rsn.assigned{color:var(--ok)} .rsn.redistributed{color:#2b7de9}
.rsn.skipped_no_rule,.rsn.skipped_offline,.rsn.skipped_schedule{color:var(--warn)}
.muted{color:var(--muted)}
.empty{color:var(--muted);padding:14px 2px}
.note{background:#fff;border:1px dashed var(--br);border-radius:12px;padding:16px 18px;color:var(--ink2)}
.err{background:var(--red-soft);color:var(--red);padding:12px 14px;border-radius:10px}
details summary{cursor:pointer;color:var(--ink2);font-weight:600;font-size:12.5px;margin-top:6px}
</style></head>
<body><div class="wrap">
<div class="top"><span class="badge">K</span><span class="brand">KO:AGENCY <span>· Админка виджетов</span></span>
<button class="refresh" id="refresh">↻ Обновить</button></div>
<div class="tabs"><button class="tab on" data-t="raspredelenie">Распределение</button><button class="tab" data-t="dubli">Дубли</button></div>
<div id="content"><div class="empty">Загрузка…</div></div>
</div>
<script>
var KEY=new URLSearchParams(location.search).get('key')||'';
var DATA=null, TAB='raspredelenie';
function fmtTs(t){ if(!t) return '—'; try{var d=new Date(t*1000);return d.toLocaleString('ru-RU');}catch(e){return String(t);} }
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
var RSN={assigned:'Назначена',redistributed:'Перераспределена',skipped_no_rule:'Нет правила',skipped_offline:'Все офлайн',skipped_schedule:'Вне расписания',history_match:'По истории',error:'Ошибка',no_managers:'Нет менеджеров'};
function tokenPill(t){
  if(!t.present) return '<span class="pill bad">токен: нет</span>';
  var kind = t.kind==='oauth'?'OAuth':(t.kind==='long_term'?'долгосрочный':(t.kind==='env_long_term'?'env':'—'));
  var exp = t.exp? ' · до '+new Date(t.exp*1000).toLocaleDateString('ru-RU') : '';
  return '<span class="pill ok">токен: есть ('+kind+exp+')</span>';
}
function rulePill(a){ return a.rules_count? '<span class="pill mut">правил: '+a.rules_count+' · менеджеров '+a.managers_count+'</span>' : '<span class="pill warn">правил нет</span>'; }
function onlinePill(a){ return '<span class="pill '+(a.online_count?'ok':'mut')+'">онлайн '+a.online_count+'/'+a.users_total+'</span>'; }
function reasonCls(r){ return 'rsn '+(r||''); }
function render(){
  var c=document.getElementById('content');
  if(!DATA){ c.innerHTML='<div class="empty">Загрузка…</div>'; return; }
  var w=DATA.widgets[TAB];
  if(TAB==='dubli'){ c.innerHTML='<div class="note"><b>Дубли</b> — раздел подключается. '+esc(w.note||'')+'</div>'; return; }
  var accs=w.accounts||[];
  var tokOk=accs.filter(function(a){return a.token.present;}).length;
  var noRule=accs.filter(function(a){return !a.rules_count;}).length;
  var onl=accs.reduce(function(s,a){return s+a.online_count;},0);
  var html='<div class="kpis">'
    +'<div class="kpi"><b>'+accs.length+'</b><span>аккаунтов</span></div>'
    +'<div class="kpi"><b>'+tokOk+'</b><span>с токеном</span></div>'
    +'<div class="kpi"><b>'+noRule+'</b><span>без правила</span></div>'
    +'<div class="kpi"><b>'+onl+'</b><span>онлайн сейчас</span></div>'
    +'</div>';
  if(!accs.length){ html+='<div class="empty">Нет аккаунтов.</div>'; c.innerHTML=html; return; }
  accs.forEach(function(a){
    html+='<div class="card"><h3>'+esc(a.domain||('account '+a.account_id))+'</h3>'
      +'<div class="dom">id '+esc(a.account_id)+' · метод '+esc(a.method)+' · расписаний '+a.schedules+(a.pending?(' · повтор в очереди '+a.pending):'')+'</div>'
      +'<div class="row">'+tokenPill(a.token)+rulePill(a)+onlinePill(a)+'</div>';
    if(a.log && a.log.length){
      html+='<details><summary>Лог распределения ('+a.log.length+')</summary><table><tr><th>Время</th><th>Сделка</th><th>Этап</th><th>Менеджер</th><th>Причина</th></tr>';
      a.log.slice().reverse().forEach(function(e){
        html+='<tr><td class="muted">'+fmtTs(e.ts)+'</td><td>'+esc(e.lead_id||'—')+'</td><td class="muted">'+esc(e.stage_id||'—')+'</td><td>'+esc(e.manager_id||'—')+'</td><td class="'+reasonCls(e.reason)+'">'+esc(RSN[e.reason]||e.reason||'—')+'</td></tr>';
      });
      html+='</table></details>';
    } else { html+='<div class="muted" style="margin-top:8px">Лог пуст.</div>'; }
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
