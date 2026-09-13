<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/FakeApiClient.php';
$_ENV['STORAGE_PATH'] = sys_get_temp_dir() . '/dd-tests';
