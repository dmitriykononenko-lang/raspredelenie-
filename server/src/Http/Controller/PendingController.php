<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Distribution\PendingStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Очередь ожидания для виджета: список и «раздать сейчас». */
class PendingController
{
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acc = (string) $request->getHeaderLine('X-Account-Id');
        $all = (new PendingStorage())->all($acc);
        $waiting = [];
        foreach ($all as $e) {
            if (($e['type'] ?? '') !== 'waiting') continue;
            $waiting[] = ['lead_id' => (int) $e['lead_id'], 'queued_at' => (int) ($e['queued_at'] ?? 0), 'reason' => (string) ($e['reason'] ?? ''), 'stage_id' => $e['stage_id'] ?? null];
        }
        usort($waiting, fn($a, $b) => $a['queued_at'] <=> $b['queued_at']);
        $response->getBody()->write(json_encode(['count' => count($waiting), 'items' => $waiting], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** Снять с очереди и распределить немедленно (force) — через обычный сервис. */
    public function release(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acc = (string) $request->getHeaderLine('X-Account-Id');
        $leadId = (int) ($args['leadId'] ?? 0);
        (new PendingStorage())->remove($acc, $leadId);
        $req = $request->withParsedBody(['account_id' => $acc, 'lead_id' => $leadId, 'force' => true]);
        return (new DistributeController(new \Monolog\Logger('pending')))->distribute($req, $response);
    }
}
