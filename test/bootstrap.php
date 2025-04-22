<?php

declare(strict_types=1);

namespace IsolatedSitesTest;

require dirname(__DIR__) . '/vendor/autoload.php';



spl_autoload_register(function ($class) {
    $prefixes = [
        'Omeka\\' => __DIR__ . '/stubs/',
        'IsolatedSites\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
    
});

require_once __DIR__ . '/../Module.php';
