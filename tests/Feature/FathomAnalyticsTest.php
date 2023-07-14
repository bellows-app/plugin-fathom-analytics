<?php

use Bellows\Plugins\FathomAnalytics;
use Bellows\PluginSdk\Facades\Project;
use Illuminate\Support\Facades\Http;

it('can choose an app from the list', function () {
    Http::fake([
        'sites?limit=100' => Http::response([
            'data' => [
                [
                    'id'   => '789',
                    'name' => Project::appName(),
                ],
            ],
        ]),
    ]);

    $result = $this->plugin(FathomAnalytics::class)
        ->expectsQuestion('Choose a site', Project::appName())
        ->deploy();

    expect($result->getEnvironmentVariables())->toEqual([
        'FATHOM_SITE_ID' => '789',
    ]);
});

it('can create a new app', function () {
    Http::fake([
        'sites?limit=100' => Http::response([
            'data' => [
                [
                    'id'   => '123',
                    'name' => 'Random Site',
                ],
            ],
        ]),
        'sites' => Http::response([
            'id'   => '789',
            'name' => 'Test App',
        ]),
    ]);

    $result = $this->plugin(FathomAnalytics::class)
        ->expectsConfirmation('Create new Fathom Analytics site?', 'yes')
        ->expectsQuestion('Enter your site name', 'Test App')
        ->deploy();

    $this->assertRequestWasSent('POST', 'sites', [
        'name' => 'Test App',
    ]);

    expect($result->getEnvironmentVariables())->toEqual([
        'FATHOM_SITE_ID' => '789',
    ]);
});
