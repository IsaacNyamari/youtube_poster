<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment pipeline (Settings → Deployment)
    |--------------------------------------------------------------------------
    |
    | The server pulls the configured branch from "origin", installs
    | dependencies, migrates and restarts the queue worker. Binaries can be
    | overridden when they are not on the PATH of the web/queue user.
    |
    */

    'branch' => env('DEPLOY_BRANCH', 'main'),

    'git' => env('DEPLOY_GIT_BINARY', 'git'),

    'php' => env('DEPLOY_PHP_BINARY', PHP_BINARY),

    'composer' => env('DEPLOY_COMPOSER_BINARY', 'composer'),

    'npm' => env('DEPLOY_NPM_BINARY', 'npm'),

    'build_assets' => (bool) env('DEPLOY_BUILD_ASSETS', true),

    'timeout' => (int) env('DEPLOY_TIMEOUT', 900),

    'webhook_secret' => env('DEPLOY_WEBHOOK_SECRET'),
];
