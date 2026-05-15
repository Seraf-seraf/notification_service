<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function (): array {
    return [
        'service' => config('app.name'),
        'status' => 'ok',
        'dependencies' => [
            'postgresql' => config('database.default') === 'pgsql' ? 'configured' : 'not_configured',
            'redis' => config('cache.default') === 'redis' ? 'configured' : 'not_configured',
            'rabbitmq' => config('rabbitmq.host') !== null ? 'configured' : 'not_configured',
        ],
    ];
})->name('api.health');
