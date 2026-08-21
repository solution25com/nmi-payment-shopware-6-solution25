<?php

declare(strict_types=1);

$autoloaders = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (!is_file($autoloader)) {
        continue;
    }

    $loader = require $autoloader;
    if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $loader->addPsr4('NMIPayment\\', dirname(__DIR__) . '/src');
        $loader->addPsr4('NMIPayment\\Tests\\', __DIR__);
    }

    return;
}

throw new RuntimeException('Composer autoloader not found. Run composer install before the test suite.');
