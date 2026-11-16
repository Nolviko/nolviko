<?php

declare(strict_types=1);

use Nolviko\Core\Runtime\Bootstrap;

// This file is included from other scopes, so it defines no variables of its own.
require_once dirname(__DIR__) . '/vendor/autoload.php';

return Bootstrap::boot(
    dirname(__DIR__),
    getenv('DB_DRIVER') ?: 'mysql',
);
