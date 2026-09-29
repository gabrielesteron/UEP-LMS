<?php

// Vercel invokes this function for dynamic requests. Keep Laravel's public
// front controller as the single HTTP entry point.
$storage = sys_get_temp_dir().'/uep-lms';

foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    if (! is_dir($storage.'/'.$directory) && ! @mkdir($storage.'/'.$directory, 0775, true) && ! is_dir($storage.'/'.$directory)) {
        throw new RuntimeException('Unable to prepare temporary Laravel storage.');
    }
}

$runtimePaths = [
    'LARAVEL_STORAGE_PATH' => $storage,
    'VIEW_COMPILED_PATH' => $storage.'/framework/views',
    'APP_CONFIG_CACHE' => $storage.'/framework/cache/config.php',
    'APP_EVENTS_CACHE' => $storage.'/framework/cache/events.php',
    'APP_PACKAGES_CACHE' => $storage.'/framework/cache/packages.php',
    'APP_ROUTES_CACHE' => $storage.'/framework/cache/routes-v7.php',
    'APP_SERVICES_CACHE' => $storage.'/framework/cache/services.php',
];

foreach ([
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'LOG_CHANNEL' => 'stderr',
    'LOG_LEVEL' => 'warning',
    'SESSION_SECURE_COOKIE' => 'true',
    'SESSION_ENCRYPT' => 'true',
] as $name => $value) {
    if (getenv($name) === false && ! isset($_ENV[$name]) && ! isset($_SERVER[$name])) {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}

foreach ($runtimePaths as $name => $path) {
    $_ENV[$name] = $path;
    $_SERVER[$name] = $path;
    putenv($name.'='.$path);
}

// Marks requests handled behind Vercel's HTTPS ingress for Laravel's proxy middleware.
$_ENV['LMS_VERCEL_RUNTIME'] = $_SERVER['LMS_VERCEL_RUNTIME'] = 'true';
putenv('LMS_VERCEL_RUNTIME=true');

require __DIR__.'/../public/index.php';
