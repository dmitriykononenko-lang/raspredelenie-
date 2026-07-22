<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Distribution\ScheduleChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CRUD for manager work schedules.
 *
 * GET  /api/schedules/{userId}         — load schedule for a manager
 * PUT  /api/schedules/{userId}         — save / replace schedule
 * DELETE /api/schedules/{userId}       — remove schedule (manager always available)
 * GET  /api/schedules                  — list all schedules for the account
 */
class ScheduleController
{
    private ScheduleChecker $checker;

    public function __construct()
    {
        $this->checker = new ScheduleChecker();
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $userId    = (int) ($args['userId'] ?? 0);

        if (!$accountId || !$userId) {
            return $this->json($response, ['error' => 'account_id and userId required'], 400);
        }

        $schedule = $this->checker->getSchedule($accountId, $userId);
        if ($schedule === null) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }

        return $this->json($response, $schedule);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $userId    = (int) ($args['userId'] ?? 0);
        $body      = (array) $request->getParsedBody();

        if (!$accountId || !$userId) {
            return $this->json($response, ['error' => 'account_id and userId required'], 400);
        }

        $errors = $this->validateSchedule($body);
        if ($errors) {
            return $this->json($response, ['error' => 'validation_failed', 'details' => $errors], 422);
        }

        $this->checker->saveSchedule($accountId, $userId, $body);

        return $this->json($response, ['status' => 'ok']);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $userId    = (int) ($args['userId'] ?? 0);

        if (!$accountId || !$userId) {
            return $this->json($response, ['error' => 'account_id and userId required'], 400);
        }

        $this->checker->deleteSchedule($accountId, $userId);

        return $this->json($response, ['status' => 'ok']);
    }

    public function listAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);

        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        return $this->json($response, $this->checker->listSchedules($accountId));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function accountId(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('X-Account-Id')
            ?: (string) ($request->getQueryParams()['account_id'] ?? '');
    }

    private function validateSchedule(array $data): array
    {
        $errors   = [];
        $dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        if (empty($data['timezone'])) {
            $errors[] = 'timezone is required';
        } elseif (!in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'timezone is invalid';
        }

        foreach ($dayNames as $day) {
            if (!array_key_exists($day, $data['days'] ?? [])) {
                continue; // missing day = no constraint
            }
            $slot = $data['days'][$day];
            if ($slot === null) {
                continue; // day off — ok
            }
            if (!preg_match('/^\d{2}:\d{2}$/', (string) ($slot['start'] ?? ''))) {
                $errors[] = "$day.start must be HH:MM";
            }
            if (!preg_match('/^\d{2}:\d{2}$/', (string) ($slot['end'] ?? ''))) {
                $errors[] = "$day.end must be HH:MM";
            }
        }

        return $errors;
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
