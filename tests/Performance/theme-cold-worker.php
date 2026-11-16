<?php

declare(strict_types=1);

use Nolviko\Tests\Performance\ThemeColdProbe;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/ThemeColdProbe.php';

echo json_encode(ThemeColdProbe::run(dirname(__DIR__, 2)), JSON_THROW_ON_ERROR) . "\n";
