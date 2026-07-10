<?php
// Custom PSR-4 autoloader for WhichBrowser and MaxMind DB Reader in Modern Log Viewer

spl_autoload_register(function ($class) {
    $prefixes = [
        'WhichBrowser\\' => __DIR__ . '/whichbrowser/parser/',
        'MaxMind\\Db\\'   => __DIR__ . '/maxmind/db/Db/',
    ];

    foreach ($prefixes as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
});
