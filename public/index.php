<?php

declare(strict_types=1);

use Nolviko\Core\Http\FrontController;

// Front controller: the only PHP file under the web root.
require dirname(__DIR__) . '/vendor/autoload.php';

FrontController::run(dirname(__DIR__));
