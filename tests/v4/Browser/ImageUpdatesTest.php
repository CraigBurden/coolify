<?php

use App\Models\ImageUpdateCheck;
use App\Services\ImageUpdates\ResourceImages;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-image-updates',
        'name' => 'Compose App',
        'build_pack' => 'dockercompose',
        'status' => 'running:healthy',
        'docker_compose_raw' => "services:\n  web:\n    image: ghcr.io/acme/web:\${WEB_TAG:-1.2}\n  cache:\n    image: redis:7\n  worker:\n    build: ./worker\n",
    ]);
    $checks = app(ResourceImages::class)->sync($this->application);
    $checks['web']->update([
        'frequency' => 'hourly',
        'on_update' => ImageUpdateCheck::ON_UPDATE_DEPLOY_AND_NOTIFY,
        'image' => 'ghcr.io/acme/web:1.2',
        'update_available' => true,
        'current_digest' => 'sha256:'.str_repeat('a', 64),
        'latest_digest' => 'sha256:'.str_repeat('b', 64),
        'last_checked_at' => now(),
    ]);
    $checks['cache']->update(['last_checked_at' => now(), 'last_error' => 'ERROR: unauthorized: authentication required']);
});

it('shows the images of a compose application and saves a schedule', function () {
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application).'/image-updates');

    $page->assertSee('Image updates')
        ->assertSee('ghcr.io/acme/web:${WEB_TAG:-1.2}')
        ->assertSee('redis:7')
        ->assertDontSee('./worker')
        ->assertSee('Update available')
        ->assertSee('Check failed')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'image-updates-page');

    $cache = $this->application->imageUpdateChecks()->where('service_name', 'cache')->first();
    $page->fill('[id^="schedules.'.$cache->uuid.'.frequency"]', 'weekly');
    submitLivewireForm($page);
    $page->wait(1)->screenshot(filename: 'image-updates-saved');

    expect($cache->fresh()->frequency)->toBe('weekly');
});
