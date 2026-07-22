<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DealDist\App\AppFactory;

$app = AppFactory::create();
$app->run();
