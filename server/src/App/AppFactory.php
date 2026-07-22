<?php

declare(strict_types=1);

namespace DealDist\App;

use DealDist\Http\Middleware\AuthMiddleware;
use DealDist\Http\Middleware\CorsMiddleware;
use DealDist\Http\Middleware\JsonMiddleware;
use DealDist\Http\Controller\DistributeController;
use DealDist\Http\Controller\OAuthController;
use DealDist\Http\Controller\QueueController;
use DealDist\Http\Controller\ScheduleController;
use DealDist\Http\Controller\SettingsController;
use DealDist\Http\Controller\WebhookController;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Slim\Factory\AppFactory as SlimAppFactory;

class AppFactory
{
    public static function create(): \Slim\App
    {
        // Load .env
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->safeLoad();

        // DI container
        $builder = new ContainerBuilder();
        $builder->addDefinitions(self::definitions());
        $container = $builder->build();

        // Slim app
        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addErrorMiddleware(true, true, true);
        $app->add(new JsonMiddleware());
        $app->add(new CorsMiddleware());

        // OPTIONS preflight — must be declared before other routes
        $app->options('/{routes:.+}', function ($request, $response) {
            return $response;
        });

        // Routes
        $app->post('/api/distribute',                   DistributeController::class . ':distribute');
        $app->put('/api/settings',                      SettingsController::class   . ':save');
        $app->get('/api/settings',                      SettingsController::class   . ':get');

        // Schedules
        $app->get('/api/schedules',                     ScheduleController::class   . ':listAll');
        $app->get('/api/schedules/{userId:[0-9]+}',     ScheduleController::class   . ':get');
        $app->put('/api/schedules/{userId:[0-9]+}',     ScheduleController::class   . ':save');
        $app->delete('/api/schedules/{userId:[0-9]+}',  ScheduleController::class   . ':delete');

        // Queue management
        $app->get('/api/queue',                         QueueController::class      . ':listQueues');
        $app->post('/api/queue/{ruleHash}/reset',       QueueController::class      . ':resetQueue');
        $app->get('/api/log',                           QueueController::class      . ':getLog');

        // AmoCRM webhook (alternative to Digital Pipeline)
        $app->post('/webhook/leads',                    WebhookController::class    . ':handle');

        $app->get('/oauth/callback',                    OAuthController::class      . ':callback');

        return $app;
    }

    private static function definitions(): array
    {
        return [
            Logger::class => static function () {
                $level   = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO');
                $logger  = new Logger('deal-dist');
                $logger->pushHandler(new StreamHandler('php://stderr', Level::fromName($level)));
                return $logger;
            },
        ];
    }
}
