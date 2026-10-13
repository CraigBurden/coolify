<?php

use App\Actions\Service\DeployServiceApplication;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CheckImageUpdateJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageUpdateCheck;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Services\ImageUpdates\ResourceImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
});

function imageUpdateDigest(string $character): string
{
    return 'sha256:'.str_repeat($character, 64);
}

/**
 * Fake the registry manifest (index digest "b", platform digest "c") and the repository digests of the running image.
 */
function fakeImageUpdateServer(string $runningDigest, ?string $registryError = null): void
{
    Process::fake(function ($process) use ($runningDigest, $registryError) {
        $command = (string) $process->command;

        if (str_contains($command, 'imagetools inspect')) {
            return $registryError !== null
                ? Process::result(errorOutput: $registryError, exitCode: 1)
                : Process::result(output: json_encode([
                    'schemaVersion' => 2,
                    'mediaType' => 'application/vnd.oci.image.index.v1+json',
                    'digest' => imageUpdateDigest('b'),
                    'manifests' => [['digest' => imageUpdateDigest('c'), 'platform' => ['architecture' => 'amd64', 'os' => 'linux']]],
                ], JSON_PRETTY_PRINT));
        }

        if (str_contains($command, 'RepoDigests')) {
            return Process::result(output: json_encode(["ghcr.io/acme/web@{$runningDigest}"]));
        }

        return Process::result(output: '');
    });
}

function imageUpdateComposeApplicationCheck(string $onUpdate = ImageUpdateCheck::ON_UPDATE_NOTIFY, array $attributes = []): ImageUpdateCheck
{
    $application = imageUpdateTestApplication([
        'docker_compose_raw' => "services:\n  web:\n    image: ghcr.io/acme/web:\${WEB_TAG:-1.2}\n",
        ...$attributes,
    ]);
    $check = app(ResourceImages::class)->sync($application)['web'];
    $check->update(['frequency' => 'daily', 'on_update' => $onUpdate]);

    return $check->fresh();
}

it('finds an update when the registry serves another digest for the tag', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    fakeImageUpdateServer(imageUpdateDigest('a'));
    $check = imageUpdateComposeApplicationCheck();

    CheckImageUpdateJob::dispatchSync($check);

    $check->refresh();
    expect($check->update_available)->toBeTrue()
        ->and($check->image)->toBe('ghcr.io/acme/web:1.2')
        ->and($check->current_digest)->toBe(imageUpdateDigest('a'))
        ->and($check->latest_digest)->toBe(imageUpdateDigest('b'))
        ->and($check->last_checked_at)->not->toBeNull()
        ->and($check->last_error)->toBeNull()
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
    Process::assertRan(fn ($process) => str_contains($process->command, "docker buildx imagetools inspect 'ghcr.io/acme/web:1.2'"));
});

it('is up to date when the running image matches the tag or one of its platform manifests', function (string $runningDigest) {
    fakeImageUpdateServer($runningDigest);
    $check = imageUpdateComposeApplicationCheck();
    $check->update(['update_available' => true]);

    CheckImageUpdateJob::dispatchSync($check);

    expect($check->fresh()->update_available)->toBeFalse();
})->with([
    'index digest' => [imageUpdateDigest('b')],
    'platform digest' => [imageUpdateDigest('c')],
]);

it('deploys an application once for each new digest when automatic deployment is on', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    fakeImageUpdateServer(imageUpdateDigest('a'));
    $check = imageUpdateComposeApplicationCheck(ImageUpdateCheck::ON_UPDATE_DEPLOY);

    CheckImageUpdateJob::dispatchSync($check);
    ApplicationDeploymentQueue::query()->update(['status' => 'finished']);
    CheckImageUpdateJob::dispatchSync($check->fresh());

    expect(ApplicationDeploymentQueue::where('application_id', $check->resource_id)->count())->toBe(1)
        ->and($check->fresh()->auto_deployed_digest)->toBe(imageUpdateDigest('b'));
});

it('does not deploy a stopped application', function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    fakeImageUpdateServer(imageUpdateDigest('a'));
    $check = imageUpdateComposeApplicationCheck(ImageUpdateCheck::ON_UPDATE_DEPLOY, ['status' => 'exited']);

    CheckImageUpdateJob::dispatchSync($check);

    expect(ApplicationDeploymentQueue::count())->toBe(0)
        ->and($check->fresh()->update_available)->toBeTrue()
        ->and($check->fresh()->auto_deployed_digest)->toBeNull();
});

it('pulls and deploys only the updated part of a service', function () {
    Queue::fake()->except([CheckImageUpdateJob::class]);
    fakeImageUpdateServer(imageUpdateDigest('a'));
    $service = imageUpdateTestService("services:\n  web:\n    image: ghcr.io/acme/web:1.2\n  db:\n    image: postgres:16\n");
    $web = ServiceApplication::create(['name' => 'web', 'service_id' => $service->id, 'image' => 'ghcr.io/acme/web:1.2', 'status' => 'running:healthy']);
    $check = app(ResourceImages::class)->sync($service)['web'];
    $check->update(['frequency' => 'daily', 'on_update' => ImageUpdateCheck::ON_UPDATE_DEPLOY_AND_NOTIFY]);

    CheckImageUpdateJob::dispatchSync($check->fresh());

    DeployServiceApplication::assertPushed(1);
    DeployServiceApplication::assertPushed(fn ($action, $arguments) => $arguments[0]->is($web) && $arguments[1] === true);
    expect($check->fresh()->auto_deployed_digest)->toBe(imageUpdateDigest('b'));
});

it('stores a registry error and keeps the previous result', function () {
    fakeImageUpdateServer(imageUpdateDigest('a'), registryError: 'ERROR: unauthorized: authentication required');
    $check = imageUpdateComposeApplicationCheck();
    $check->update(['update_available' => true, 'latest_digest' => imageUpdateDigest('b')]);

    CheckImageUpdateJob::dispatchSync($check);

    $check->refresh();
    expect($check->last_error)->toContain('unauthorized: authentication required')
        ->and($check->update_available)->toBeTrue()
        ->and($check->latest_digest)->toBe(imageUpdateDigest('b'));
});

it('stores an error for an image variable that is not set', function () {
    Process::fake();
    $application = imageUpdateTestApplication(['docker_compose_raw' => "services:\n  web:\n    image: ghcr.io/acme/web:\${WEB_TAG}\n"]);
    $check = app(ResourceImages::class)->sync($application)['web'];

    CheckImageUpdateJob::dispatchSync($check);

    expect($check->fresh()->last_error)->toBe('The variable WEB_TAG is not set.');
    Process::assertNothingRan();
});

it('deletes the check of an image that was removed from the compose file', function () {
    Process::fake();
    $check = imageUpdateComposeApplicationCheck();
    $check->resource->update(['docker_compose_raw' => "services:\n  api:\n    image: nginx:1\n"]);

    CheckImageUpdateJob::dispatchSync($check);

    expect(ImageUpdateCheck::find($check->id))->toBeNull();
    Process::assertNothingRan();
});

it('runs each lookup as one sudo command on a server with a non-root user', function () {
    fakeImageUpdateServer(imageUpdateDigest('a'));
    $check = imageUpdateComposeApplicationCheck();
    $check->server()->update(['user' => 'deploy']);
    Server::flushIdentityMap();

    CheckImageUpdateJob::dispatchSync($check->fresh());

    Process::assertRan(fn ($process) => str_contains($process->command, "sudo docker buildx imagetools inspect 'ghcr.io/acme/web:1.2'"));
    Process::assertRan(fn ($process) => str_contains($process->command, 'com.docker.compose.service=web')
        && preg_match_all('/^sudo /m', $process->command) === 1
        && ! str_contains($process->command, 'sudo docker ps'));
    expect($check->fresh()->update_available)->toBeTrue();
});
