<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\ImageUpdateCheck;
use App\Models\Server;
use App\Services\ImageUpdates\ResourceImages;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

function setImageUpdateVariable(Application $application, string $key, ?string $value): void
{
    EnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
}

it('lists the registry images of a compose application and skips services that are built', function () {
    $application = imageUpdateTestApplication(['docker_compose_raw' => <<<'YAML'
        services:
          web:
            image: ghcr.io/acme/web:${WEB_TAG:-1.2}
          worker:
            build: ./worker
            image: acme/worker:dev
          cache:
            image: redis:7
        YAML]);

    expect(app(ResourceImages::class)->configured($application))->toBe([
        'web' => 'ghcr.io/acme/web:${WEB_TAG:-1.2}',
        'cache' => 'redis:7',
    ]);
});

it('lists the image of a Docker image application', function (string $name, ?string $tag, string $expected) {
    $application = imageUpdateTestApplication([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => $name,
        'docker_registry_image_tag' => $tag,
    ]);

    expect(app(ResourceImages::class)->configured($application))
        ->toBe([ImageUpdateCheck::STANDALONE_IMAGE => $expected]);
})->with([
    'tag' => ['nginx', '1.27-alpine', 'nginx:1.27-alpine'],
    'no tag' => ['ghcr.io/acme/app', null, 'ghcr.io/acme/app:latest'],
]);

it('lists no images for an application that Coolify builds', function () {
    $application = imageUpdateTestApplication(['build_pack' => 'nixpacks']);

    expect(app(ResourceImages::class)->configured($application))->toBe([]);
});

it('resolves image variables like Docker Compose', function (string $image, string $expected) {
    $application = imageUpdateTestApplication();
    setImageUpdateVariable($application, 'TAG', '2.0');
    setImageUpdateVariable($application, 'EMPTY', '');
    setImageUpdateVariable($application, 'REGISTRY', 'ghcr.io/acme');

    expect(app(ResourceImages::class)->resolve($application, $image))->toBe($expected);
})->with([
    'braced' => ['app:${TAG}', 'app:2.0'],
    'unbraced' => ['$REGISTRY/app:$TAG', 'ghcr.io/acme/app:2.0'],
    'set value wins over default' => ['app:${TAG:-1.0}', 'app:2.0'],
    'default for unset' => ['app:${MISSING:-1.0}', 'app:1.0'],
    'colon default for empty' => ['app:${EMPTY:-1.0}', 'app:1.0'],
    'nested default' => ['app:${MISSING:-${TAG}}', 'app:2.0'],
]);

it('reports an image that cannot be checked', function (string $image, string $message) {
    $application = imageUpdateTestApplication();

    expect(fn () => app(ResourceImages::class)->resolve($application, $image))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unset variable' => ['app:${MISSING}', 'The variable MISSING is not set.'],
    'required variable' => ['app:${MISSING:?set it}', 'The variable MISSING is not set.'],
    'digest' => ['nginx@sha256:'.str_repeat('a', 64), 'The image is pinned to a digest'],
    'shell characters' => ['nginx:1;reboot', 'is not valid'],
]);

it('creates a disabled check for each new image and deletes checks of removed images', function () {
    $application = imageUpdateTestApplication(['docker_compose_raw' => "services:\n  web:\n    image: nginx:1\n  db:\n    image: postgres:16\n"]);
    $removed = $application->imageUpdateChecks()->create(['team_id' => $application->team()->id, 'service_name' => 'old', 'frequency' => 'daily']);
    $kept = $application->imageUpdateChecks()->create(['team_id' => $application->team()->id, 'service_name' => 'db', 'frequency' => 'weekly']);

    $checks = app(ResourceImages::class)->sync($application);

    expect($checks->keys()->all())->toBe(['web', 'db'])
        ->and($checks['web']->frequency)->toBeNull()
        ->and($checks['web']->on_update)->toBe(ImageUpdateCheck::ON_UPDATE_NOTIFY)
        ->and($checks['web']->team_id)->toBe($application->team()->id)
        ->and($checks['db']->id)->toBe($kept->id)
        ->and($kept->fresh()?->frequency)->toBe('weekly')
        ->and(ImageUpdateCheck::find($removed->id))->toBeNull();
});
