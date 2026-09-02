<?php

declare(strict_types=1);

namespace DealDist\Billing;

use GuzzleHttp\Client;

/**
 * Клиент ЮKassa (api.yookassa.ru). Basic-auth = shopId:secretKey.
 * Карточные данные через нас не проходят — используется hosted-страница ЮKassa.
 *
 * Конфиг из ENV (магазин ИМЕННО для Распределения — отдельный от Дублей):
 *   YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY, YOOKASSA_FISCAL (1/0), YOOKASSA_VAT_CODE
 */
final class YooKassaClient
{
    private string $shopId;
    private string $secret;

    public function __construct(?string $shopId = null, ?string $secret = null)
    {
        $this->shopId = $shopId ?? (string) ($_ENV['YOOKASSA_SHOP_ID'] ?? '');
        $this->secret = $secret ?? (string) ($_ENV['YOOKASSA_SECRET_KEY'] ?? '');
    }

    public function enabled(): bool
    {
        return $this->shopId !== '' && $this->secret !== '';
    }

    private function authHeader(): string
    {
        return 'Basic ' . base64_encode($this->shopId . ':' . $this->secret);
    }

    private function uuid(): string
    {
        // RFC-4122 v4 из random_bytes (без внешних зависимостей).
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Создать платёж. Возвращает разобранный ответ ЮKassa (в т.ч. confirmation.confirmation_url).
     *
     * @param array{amount:float,description:string,return_url:string,metadata:array,receipt?:array} $in
     * @return array<string,mixed>
     */
    public function createPayment(array $in): array
    {
        $body = [
            'amount'       => ['value' => number_format((float) $in['amount'], 2, '.', ''), 'currency' => 'RUB'],
            'capture'      => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $in['return_url']],
            'description'  => mb_substr((string) $in['description'], 0, 128),
            'metadata'     => (array) ($in['metadata'] ?? []),
        ];
        if (!empty($in['receipt'])) {
            $body['receipt'] = $in['receipt'];
        }
        $http = new Client(['timeout' => 20]);
        $resp = $http->post('https://api.yookassa.ru/v3/payments', [
            'headers' => [
                'Authorization'   => $this->authHeader(),
                'Idempotence-Key' => $this->uuid(),
                'Content-Type'    => 'application/json',
            ],
            'json'        => $body,
            'http_errors' => true,
        ]);
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Перепроверка платежа по id (телу вебхука не доверяем). @return array<string,mixed> */
    public function getPayment(string $id): array
    {
        $http = new Client(['timeout' => 20]);
        $resp = $http->get('https://api.yookassa.ru/v3/payments/' . rawurlencode($id), [
            'headers'     => ['Authorization' => $this->authHeader()],
            'http_errors' => true,
        ]);
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
