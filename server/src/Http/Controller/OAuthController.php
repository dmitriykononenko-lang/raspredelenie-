<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\AmoCRM\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the OAuth 2.0 authorization code callback from AmoCRM.
 *
 * Flow:
 *   1. User installs the widget in AmoCRM.
 *   2. AmoCRM redirects to GET /oauth/callback?code=XXX&referer=domain.amocrm.ru&client_id=YYY
 *   3. We exchange the code for access + refresh tokens.
 *   4. We save the tokens mapped to the account (base domain).
 */
class OAuthController
{
    public function __construct(private readonly Logger $logger) {}

    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $code   = $params['code']    ?? null;
        $domain = $params['referer'] ?? null; // e.g. "mycompany.amocrm.ru"

        $clientId     = $_ENV['AMO_CLIENT_ID']     ?? '';
        $clientSecret = $_ENV['AMO_CLIENT_SECRET'] ?? '';
        $redirectUri  = $_ENV['AMO_REDIRECT_URI']  ?? '';
        $longTerm     = trim((string) ($_ENV['AMO_LONG_TERM_TOKEN'] ?? ''));

        // (3) Лог входа: видно, что callback вызван и в каком режиме.
        $this->logger->info('OAuth callback', [
            'domain'    => $domain,
            'has_code'  => $code ? 'yes' : 'no',
            'client_id' => $clientId !== '' ? substr($clientId, 0, 8) . '…' : '(пусто)',
            'mode'      => ($code && $clientSecret) ? 'oauth-exchange' : 'confirm-only',
        ]);

        // (2) Режим confirm-only: нет кода/домена, либо не настроен OAuth
        // (долгосрочный токен / пустые креды). Просто подтверждаем установку 200 —
        // amoCRM активирует виджет, не откатывая его в «Отключено».
        if (!$code || !$domain || $longTerm !== '' || !$clientId || !$clientSecret || !$redirectUri) {
            $this->logger->warning('OAuth: режим confirm-only, обмен пропущен', ['domain' => $domain]);
            return $this->html($response, 'Установка завершена. Можно закрыть это окно.');
        }

        // (2) OAuth-обмен в try/catch. Наружу ВСЕГДА отдаём 200 (html) — любая
        // ошибка обмена не должна ронять установку. Причину пишем в лог.
        try {
            $http     = new Client(['timeout' => 15]);
            $apiResp  = $http->post("https://$domain/oauth2/access_token", [
                'json' => [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $redirectUri,
                ],
            ]);

            $tokens = json_decode((string) $apiResp->getBody(), true, 512, JSON_THROW_ON_ERROR);

            // AmoCRM does not include account_id in the token response —
            // fetch it from /api/v4/account using the fresh access token.
            $accountResp = $http->get("https://$domain/api/v4/account", [
                'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
            ]);
            $accountData = json_decode((string) $accountResp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $accountId   = (string) ($accountData['id'] ?? md5($domain));

            (new ApiClient($this->logger))->saveTokens($accountId, $domain, $tokens);

            // (3) Лог успеха.
            $this->logger->info('OAuth: токены получены и сохранены', ['account_id' => $accountId, 'domain' => $domain]);

            return $this->html($response, 'Авторизация успешна. Можно закрыть это окно.');
        } catch (RequestException $e) {
            // (3) Лог неуспеха: http-статус + ПОЛНОЕ тело ответа amoCRM + redirect_uri.
            $this->logger->error('OAuth-обмен НЕ удался', [
                'http_status'  => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
                'amo_response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : '(нет тела ответа)',
                'redirect_uri' => $redirectUri,
                'hint'         => 'Сверьте client_id/client_secret и redirect_uri буква-в-букву с кабинетом интеграции',
            ]);
            return $this->html($response, 'Установка завершена. Авторизацию нужно повторить (см. логи сервера).');
        } catch (\Throwable $e) {
            $this->logger->error('OAuth callback: непредвиденная ошибка', ['error' => $e->getMessage()]);
            return $this->html($response, 'Установка завершена. Авторизацию нужно повторить (см. логи сервера).');
        }
    }

    private function text(ResponseInterface $response, string $text, int $status = 200): ResponseInterface
    {
        $response->getBody()->write($text);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/plain');
    }

    private function html(ResponseInterface $response, string $message, int $status = 200): ResponseInterface
    {
        $html = '<!doctype html><meta charset="utf-8"><title>Распределение сделок</title>'
              . '<div style="font:15px/1.5 Roboto,Arial,sans-serif;color:#141414;'
              . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
              . '<div style="text-align:center"><div style="font-size:22px;font-weight:700;'
              . 'color:#d22730;margin-bottom:8px">KO:AGENCY</div>' . htmlspecialchars($message) . '</div></div>';
        $response->getBody()->write($html);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
