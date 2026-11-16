<?php

declare(strict_types=1);

// PHPUnit has already loaded the autoloader of the vendor it was installed in: app/vendor after a plain
// `composer install`, or the workspace dev/vendor (same lock, see dev/README.md). Loading a second Composer
// autoloader built from the same lock in one process would redeclare its classes.
if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

require_once dirname(__DIR__) . '/config/runtime-policy.php';
