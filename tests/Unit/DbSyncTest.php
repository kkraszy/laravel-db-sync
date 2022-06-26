<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('cannot run on production', function () {

    config(['app.env' => 'production']);

    $this->artisan('db:production-sync')
        ->expectsOutput('DB sync will only run on local and staging environments')
        ->assertExitCode(true);
});

test('can run on environment', function ($env) {

    config(['app.env' => $env]);

    $this->artisan('db:production-sync')
        ->doesntExpectOutput('DB sync will only run on local and staging environments');
})->with(['local', 'staging']);

test('fails with invalid credentials', function () {

    config(['app.env' => 'local']);

    $this->artisan('db:production-sync')
        ->expectsOutput('DB credentials not set, have you published the config and set ENV variables?')
        ->assertFailed();
});

test('runs with valid credentials', function () {

    config(['app.env' => 'local']);
    config(['dbsync.host' => '127.0.0.1']);
    config(['dbsync.username' => 'root']);
    config(['dbsync.database' => 'demo']);

    $this->artisan('db:production-sync')
        ->expectsOutput('DB Synced');
});
