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
use DealDist\Http\Controller\StatusController;
use DealDist\Http\Controller\TemplateController;
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
        // Загрузка .env из /app/.env (монтируется файлом, см. docker-compose.shared.yml).
        // createImmutable НЕ перезаписывает уже заданные переменные окружения —
        // поэтому инфраструктурные значения из compose `environment:` (STORAGE_PATH)
        // остаются источником истины, а прикладные (AMO_CLIENT_ID/SECRET,
        // WIDGET_SECRET и т.д.) берутся из смонтированного файла и читаются на
        // каждый запрос → правки .env применяются по `restart`, без --force-recreate.
        // env_file в compose НЕ используем намеренно (иначе значения бэкаются в
        // контейнер при создании и «залипают»).
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
        $app->add(new AuthMiddleware());

        // OPTIONS preflight — must be declared before other routes
        $app->options('/{routes:.+}', function ($request, $response) {
            return $response;
        });

        // Health check (для reverse-proxy / мониторинга)
        $app->get('/health', function ($request, $response) {
            $response->getBody()->write(json_encode([
                'status'  => 'ok',
                'service' => 'deal-distribution',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
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

        // Templates (шаблоны распределения)
        $app->get('/api/templates',                     TemplateController::class   . ':listAll');
        $app->post('/api/templates',                    TemplateController::class   . ':create');
        $app->put('/api/templates/{id}',                TemplateController::class   . ':update');
        $app->delete('/api/templates/{id}',             TemplateController::class   . ':delete');

        // Online status (менеджер в распределении / вне)
        $app->get('/api/status',                        StatusController::class     . ':listAll');
        $app->get('/api/status/history',                StatusController::class     . ':history');
        $app->put('/api/status/{userId:[0-9]+}',        StatusController::class     . ':set');

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
