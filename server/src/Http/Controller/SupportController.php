<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Публичная страница поддержки виджета (ссылка «Техподдержка» в amoМаркете / Kommo).
 *   GET /            — RU (корень домена; раньше отдавал 404 — замечание модерации amoCRM)
 *   GET /support     — RU
 *   GET /support/en  — EN (Kommo: без упоминания amoCRM)
 */
final class SupportController
{
    private const STYLE = <<<'CSS'
  body{max-width:820px;margin:0 auto;padding:48px 24px 80px;background:#fff;color:#141414;
    font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.6;font-size:16px}
  h1{font-size:28px;letter-spacing:-.02em;margin:0 0 6px}
  .sub{color:#8a8a8a;font-size:14px;margin-bottom:32px}
  h2{font-size:19px;margin:34px 0 10px}
  a{color:#d22730}
  ol,ul{padding-left:22px} li{margin:6px 0}
  .contacts{background:#f6f6f6;border-left:3px solid #d22730;padding:14px 18px;border-radius:4px;font-size:15px}
  .contacts b{display:inline-block;min-width:90px}
  footer{margin-top:40px;color:#8a8a8a;font-size:13px;border-top:1px solid #e5e5e5;padding-top:16px}
CSS;

    public function ru(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $style = self::STYLE;
        $html = <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Поддержка — Распределение сделок (KO:AGENCY)</title>
<style>{$style}</style>
</head>
<body>
  <h1>Поддержка виджета «Распределение сделок»</h1>
  <div class="sub">Виджет для amoCRM · KO:AGENCY</div>

  <div class="contacts">
    <div><b>E-mail:</b> <a href="mailto:hello@koagency.me">hello@koagency.me</a></div>
    <div><b>Telegram:</b> <a href="https://t.me/koagency_bot">Support koagency team · @koagency_bot</a></div>
    <div><b>Сайт:</b> <a href="https://koagency.me">koagency.me</a></div>
    <div><b>Часы:</b> пн–пт, 10:00–19:00 (МСК). Отвечаем в течение рабочего дня.</div>
  </div>

  <h2>Как начать пользоваться</h2>
  <ol>
    <li>Установите виджет из amoМаркета и откройте его настройки (карточка виджета → «Настройки»).</li>
    <li>На вкладке <b>«Сотрудники»</b> добавьте менеджеров, между которыми распределяются сделки, и включите им онлайн-статус.</li>
    <li>На вкладке <b>«Правила»</b> создайте правило: воронка, этап, менеджеры, метод (round-robin или по загруженности), что делать, если никого нет на смене.</li>
    <li>При необходимости заполните месячный график на вкладке <b>«Графики»</b> и включите в правиле «Учитывать график работы».</li>
    <li>В <b>Digital Pipeline</b> добавьте действие «Распределение сделок» на этап, куда приходят новые сделки, и сохраните.</li>
    <li>Во вкладке <b>«История»</b> видно, кому и почему назначена каждая сделка.</li>
  </ol>

  <h2>Частые вопросы</h2>
  <ul>
    <li><b>Сделка не распределилась.</b> Проверьте, что действие добавлено на нужный этап Digital Pipeline, у правила выбран этот этап, а у менеджеров включён онлайн-статус (и они на смене, если включён график).</li>
    <li><b>Хочу временно отключить менеджера.</b> Выключите ему тумблер на вкладке «Сотрудники» — это приоритетный стоп, график при этом не учитывается.</li>
    <li><b>Подписка и оплата.</b> Вкладка «Подписка» в настройках виджета: срок действия, выбор плана и оплата. По счёту для юрлица — напишите нам.</li>
  </ul>

  <h2>Что прислать в обращении</h2>
  <p>Адрес аккаунта (поддомен), название воронки и этапа, ID сделки и примерное время события — так мы найдём запись в истории распределения и ответим быстрее.</p>

  <p><a href="/privacy">Политика конфиденциальности</a> · <a href="/support/en">English</a></p>
  <footer>KO:AGENCY — внедрение и автоматизация CRM · <a href="mailto:hello@koagency.me">hello@koagency.me</a></footer>
</body>
</html>
HTML;
        return $this->html($response, $html);
    }

    public function en(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $style = self::STYLE;
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Support — Lead Distribution by KO:AGENCY</title>
<style>{$style}</style>
</head>
<body>
  <h1>Lead Distribution — Support</h1>
  <div class="sub">CRM widget · KO:AGENCY</div>

  <div class="contacts">
    <div><b>E-mail:</b> <a href="mailto:hello@koagency.me">hello@koagency.me</a></div>
    <div><b>Telegram:</b> <a href="https://t.me/koagency_bot">Support koagency team · @koagency_bot</a></div>
    <div><b>Website:</b> <a href="https://koagency.me">koagency.me</a></div>
    <div><b>Hours:</b> Mon–Fri, 10:00–19:00 (UTC+3). We reply within one business day.</div>
  </div>

  <h2>Getting started</h2>
  <ol>
    <li>Install the widget from the marketplace and open its settings (widget card → “Settings”).</li>
    <li>On the <b>Employees</b> tab add the managers who receive leads and switch their online status on.</li>
    <li>On the <b>Rules</b> tab create a rule: pipeline, stage, managers, distribution method (round-robin or by workload) and what to do when nobody is on shift.</li>
    <li>Optionally fill in the monthly shift schedule on the <b>Schedules</b> tab and enable “Consider work schedule” in the rule.</li>
    <li>In the <b>Digital Pipeline</b> add the “Lead Distribution” action to the stage where new leads arrive and save.</li>
    <li>The <b>History</b> tab shows who received each lead and why.</li>
  </ol>

  <h2>FAQ</h2>
  <ul>
    <li><b>A lead was not distributed.</b> Check that the action is attached to the right Digital Pipeline stage, the rule targets that stage, and the managers are online (and on shift, if the schedule is enabled).</li>
    <li><b>Temporarily exclude a manager.</b> Switch the manager off on the Employees tab — this is a priority stop regardless of the schedule.</li>
    <li><b>Subscription and payment.</b> See the Subscription tab in the widget settings. For invoice payment, contact us.</li>
  </ul>

  <h2>What to include in a request</h2>
  <p>Your account subdomain, pipeline and stage names, the lead ID and the approximate time of the event — this lets us find the record in the distribution history and answer faster.</p>

  <p><a href="/privacy/kommo">Privacy policy</a> · <a href="/support">Русский</a></p>
  <footer>KO:AGENCY — CRM implementation and automation · <a href="mailto:hello@koagency.me">hello@koagency.me</a></footer>
</body>
</html>
HTML;
        return $this->html($response, $html);
    }

    private function html(ResponseInterface $response, string $body): ResponseInterface
    {
        $response->getBody()->write($body);
        return $response->withStatus(200)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
