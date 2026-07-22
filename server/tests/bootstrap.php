<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Point STORAGE_PATH to a temp dir for all tests
$_ENV['STORAGE_PATH'] = sys_get_temp_dir() . '/deal_dist_tests_' . getmypid();
