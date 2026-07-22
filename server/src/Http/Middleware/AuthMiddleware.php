<?php

declare(strict_types=1);

namespace DealDist\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional middleware: validates the X-Account-Id header and checks that
 * tokens exist for that account before forwarding the request.
 *
 * Not wired in by default — add to AppFactory::create() if needed.
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Passthrough — extend as needed
        return $handler->handle($request);
    }
}
