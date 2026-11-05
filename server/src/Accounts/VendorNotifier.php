<?php

declare(strict_types=1);

namespace DealDist\Accounts;

use DealDist\AmoCRM\ApiClient;
use Monolog\Logger;

/**
 * Сообщает нам о жизни клиента: установка, отключение, этапы.
 *
 *   • Telegram — TG_BOT_TOKEN / TG_CHAT_ID (если пусто — только лог);
 *   • сделка в нашей amoCRM (koagency) в воронке вендора — как у «Дублей»:
 *       RASP_VENDOR_ACCOUNT_ID (по умолчанию 30119215 — koagency, токен уже есть),
 *       VENDOR_PIPELINE_ID / VENDOR_STATUS_INSTALLED (по умолчанию воронка «Дублей» 11130042 / 87378418),
 *       VENDOR_FIELD_ACCOUNT_ID / VENDOR_FIELD_LINK (1173679 / 1195091),
 *       RASP_VENDOR_LEADS=0 — выключить создание сделок.
 * Никакие ошибки отсюда не должны ломать установку или распределение.
 */
final class VendorNotifier
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly Logger $logger,
        private readonly AccountRegistry $registry = new AccountRegistry(),
    ) {}

    public function installed(string $accountId, array $rec): void
    {
        if (AccountRegistry::isInternal($accountId, $rec)) {
            $this->logger->info('Install of internal account — no notification', ['account_id' => $accountId]);
            return;
        }
        $text = $this->describe($accountId, $rec, !empty($rec['reinstalled_at']) ? 'Переустановил' : 'Установил');
        $this->telegram('🆕 <b>Распределение: новая установка</b>' . "\n" . htmlspecialchars($text));
        try {
            $leadId = (int) ($rec['vendor_lead_id'] ?? 0);
            if ($leadId > 0) {
                $this->note($leadId, $text);
            } else {
                $leadId = $this->createLead($accountId, $rec, $text);
                if ($leadId > 0) {
                    $this->registry->save($accountId, ['vendor_lead_id' => $leadId]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Vendor lead failed', ['account_id' => $accountId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    public function uninstalled(string $accountId, array $rec, bool $verified): void
    {
        if (AccountRegistry::isInternal($accountId, $rec)) {
            return;
        }
        $who  = ($rec['name'] ?? '') ?: ($rec['domain'] ?? $accountId);
        $text = 'Виджет «Распределение» отключён: ' . $who . ' (' . ($rec['domain'] ?? $accountId) . ')' . ($verified ? '' : ' — подпись хука не подтверждена');
        $this->telegram('🔻 ' . htmlspecialchars($text));
        $leadId = (int) ($rec['vendor_lead_id'] ?? 0);
        if ($leadId > 0) {
            try { $this->note($leadId, $text); } catch (\Throwable $e) {}
        }
    }

    public function telegram(string $html): void
    {
        $tok  = (string) ($_ENV['TG_BOT_TOKEN'] ?? '');
        $chat = (string) ($_ENV['TG_CHAT_ID'] ?? '');
        if ($tok === '' || $chat === '') {
            $this->logger->info('Telegram not configured — message skipped', ['text' => mb_substr(strip_tags($html), 0, 200)]);
            return;
        }
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 1]),
            'timeout' => 8,
        ]]);
        if (@file_get_contents('https://api.telegram.org/bot' . $tok . '/sendMessage', false, $ctx) === false) {
            $this->logger->warning('Telegram send failed');
        }
    }

    public function describe(string $accountId, array $rec, string $verb = 'Установил'): string
    {
        $inst   = $rec['installer'] ?? [];
        $admins = array_filter(array_map(fn($a) => trim(($a['name'] ?? '') . ' ' . (!empty($a['email']) ? '<' . $a['email'] . '>' : '')), $rec['admins'] ?? []));
        $lines  = [
            ($rec['name'] ?? '') . ' — https://' . ($rec['domain'] ?? '?') . ' (аккаунт ' . $accountId . ')',
            'Страна: ' . (($rec['country'] ?? '') ?: '—') . ', активных пользователей: ' . ($rec['users_active'] ?? '—'),
            $verb . ': ' . (($inst['name'] ?? '') ?: '—') . (!empty($inst['email']) ? ' <' . $inst['email'] . '>' : '') . (!empty($inst['lang']) ? ', язык ' . $inst['lang'] : ''),
        ];
        if ($admins) {
            $lines[] = 'Админы: ' . implode('; ', array_slice($admins, 0, 5));
        }
        return implode("\n", $lines);
    }

    private function vendorAccount(): string
    {
        return (string) ($_ENV['RASP_VENDOR_ACCOUNT_ID'] ?? '30119215');
    }

    private function createLead(string $accountId, array $rec, string $text): int
    {
        if (in_array(strtolower((string) ($_ENV['RASP_VENDOR_LEADS'] ?? '1')), ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }
        $pipeline = (int) ($_ENV['VENDOR_PIPELINE_ID'] ?? 11130042);
        $status   = (int) ($_ENV['VENDOR_STATUS_INSTALLED'] ?? 87378418);
        $fAcc     = (int) ($_ENV['VENDOR_FIELD_ACCOUNT_ID'] ?? 1173679);
        $fLink    = (int) ($_ENV['VENDOR_FIELD_LINK'] ?? 1195091);
        $inst     = $rec['installer'] ?? [];

        $contact = ['name' => (($inst['name'] ?? '') ?: 'Админ') . ' · ' . (($rec['name'] ?? '') ?: ($rec['domain'] ?? $accountId))];
        if (!empty($inst['email'])) {
            $contact['custom_fields_values'] = [['field_code' => 'EMAIL', 'values' => [['value' => $inst['email'], 'enum_code' => 'WORK']]]];
        }
        $lead = [
            'name'        => 'Распределение — ' . (($rec['name'] ?? '') ?: ($rec['domain'] ?? $accountId)),
            'pipeline_id' => $pipeline,
            'status_id'   => $status,
            '_embedded'   => ['tags' => [['name' => 'Распределение']], 'contacts' => [$contact]],
        ];
        $withFields = $lead;
        $withFields['custom_fields_values'] = [
            ['field_id' => $fAcc,  'values' => [['value' => (string) $accountId]]],
            ['field_id' => $fLink, 'values' => [['value' => 'https://' . ($rec['domain'] ?? '')]]],
        ];
        try {
            $res = $this->api->api($this->vendorAccount(), 'POST', '/leads/complex', ['json' => [$withFields]]);
        } catch (\Throwable $e) {
            // поля могут быть другого типа — создаём без них, данные всё равно уйдут в примечание
            $this->logger->warning('Vendor lead with fields failed, retrying without', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            $res = $this->api->api($this->vendorAccount(), 'POST', '/leads/complex', ['json' => [$lead]]);
        }
        $leadId = (int) ($res[0]['id'] ?? 0);
        if ($leadId > 0) {
            $this->note($leadId, $text);
            $this->logger->info('Vendor lead created', ['account_id' => $accountId, 'vendor_lead_id' => $leadId]);
        }
        return $leadId;
    }

    private function note(int $leadId, string $text): void
    {
        $this->api->api($this->vendorAccount(), 'POST', '/leads/' . $leadId . '/notes', ['json' => [[
            'note_type' => 'common',
            'params'    => ['text' => $text],
        ]]]);
    }
}
