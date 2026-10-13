<?php

use App\Events\ServiceStartFinished;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CheckImageUpdateJob;
use App\Listeners\RecheckImageUpdatesAfterServiceStart;
use App\Livewire\Project\Application\Status;
use App\Livewire\Project\Shared\ImageUpdates;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageUpdateCheck;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\ImageUpdates\ResourceImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->application = imageUpdateTestApplication([
        'docker_compose_raw' => "services:\n  web:\n    image: ghcr.io/acme/web:1.2\n  cache:\n    image: redis:7\n",
    ], $this->team);
});

function actingAsImageUpdateUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

it('lists every registry image and saves a schedule and action for each one', function () {
    actingAsImageUpdateUser($this->admin, $this->team);

    $component = Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->assertSee('ghcr.io/acme/web:1.2')
        ->assertSee('redis:7');
    $checks = $this->application->imageUpdateChecks()->get()->keyBy('service_name');

    $component
        ->set("schedules.{$checks['web']->uuid}.frequency", 'hourly')
        ->set("schedules.{$checks['web']->uuid}.on_update", ImageUpdateCheck::ON_UPDATE_DEPLOY)
        ->set("schedules.{$checks['cache']->uuid}.frequency", '0 3 * * 1')
        ->call('submit')
        ->assertHasNoErrors();

    expect($checks['web']->fresh()->frequency)->toBe('hourly')
        ->and($checks['web']->fresh()->on_update)->toBe(ImageUpdateCheck::ON_UPDATE_DEPLOY)
        ->and($checks['cache']->fresh()->frequency)->toBe('0 3 * * 1')
        ->and($checks['cache']->fresh()->on_update)->toBe(ImageUpdateCheck::ON_UPDATE_NOTIFY);
});

it('shows the running and registry digests, the last result, and a registry link', function () {
    actingAsImageUpdateUser($this->admin, $this->team);
    $check = app(ResourceImages::class)->sync($this->application)['web'];
    $check->update([
        'image' => 'ghcr.io/acme/web:1.2',
        'current_digest' => 'sha256:'.str_repeat('a', 64),
        'latest_digest' => 'sha256:'.str_repeat('b', 64),
        'update_available' => true,
        'last_checked_at' => now(),
    ]);

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->assertSee('sha256:'.str_repeat('a', 64))
        ->assertSee('sha256:'.str_repeat('b', 64))
        ->assertSee('The registry has a newer image for this tag')
        ->assertSee('https://ghcr.io/acme/web');
});

it('turns a schedule off when it is cleared', function () {
    actingAsImageUpdateUser($this->admin, $this->team);
    $check = app(ResourceImages::class)->sync($this->application)['web'];
    $check->update(['frequency' => 'daily']);

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->set("schedules.{$check->uuid}.frequency", '')
        ->call('submit')
        ->assertHasNoErrors();

    expect($check->fresh()->frequency)->toBeNull();
});

it('rejects an invalid schedule or action', function (string $field, string $value) {
    actingAsImageUpdateUser($this->admin, $this->team);
    $check = app(ResourceImages::class)->sync($this->application)['web'];

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->set("schedules.{$check->uuid}.{$field}", $value)
        ->call('submit')
        ->assertHasErrors(["schedules.{$check->uuid}.{$field}"]);

    expect($check->fresh()->frequency)->toBeNull()
        ->and($check->fresh()->on_update)->toBe(ImageUpdateCheck::ON_UPDATE_NOTIFY);
})->with([
    'schedule' => ['frequency', 'every tuesday'],
    'action' => ['on_update', 'delete_everything'],
]);

it('does not change a check of another resource', function () {
    actingAsImageUpdateUser($this->admin, $this->team);
    $other = imageUpdateTestApplication(['docker_compose_raw' => "services:\n  api:\n    image: nginx:1\n"], $this->team);
    $otherCheck = app(ResourceImages::class)->sync($other)['api'];

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->set("schedules.{$otherCheck->uuid}", ['frequency' => 'hourly', 'on_update' => ImageUpdateCheck::ON_UPDATE_DEPLOY])
        ->call('submit')
        ->assertHasNoErrors();

    expect($otherCheck->fresh()->frequency)->toBeNull()
        ->and($otherCheck->fresh()->on_update)->toBe(ImageUpdateCheck::ON_UPDATE_NOTIFY);
});

it('queues a check of one image on request', function () {
    Queue::fake([CheckImageUpdateJob::class]);
    actingAsImageUpdateUser($this->admin, $this->team);
    $check = app(ResourceImages::class)->sync($this->application)['cache'];

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->call('checkNow', $check->uuid);

    Queue::assertPushed(CheckImageUpdateJob::class, fn (CheckImageUpdateJob $job) => $job->imageUpdateCheck->is($check) && $job->occurrenceUuid === null);
});

it('lets a member view the checks but not change or run them', function () {
    Queue::fake([CheckImageUpdateJob::class]);
    $check = app(ResourceImages::class)->sync($this->application)['web'];
    actingAsImageUpdateUser($this->member, $this->team);

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->assertSee('ghcr.io/acme/web:1.2')
        ->set("schedules.{$check->uuid}.frequency", 'hourly')
        ->call('submit')
        ->assertForbidden();

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])
        ->call('checkNow', $check->uuid)
        ->assertForbidden();

    expect($check->fresh()->frequency)->toBeNull();
    Queue::assertNotPushed(CheckImageUpdateJob::class);
});

it('does not create checks when a member opens the page', function () {
    actingAsImageUpdateUser($this->member, $this->team);

    Livewire::test(ImageUpdates::class, ['resource' => $this->application]);

    expect($this->application->imageUpdateChecks()->count())->toBe(0);
});

it('denies a user of another team', function () {
    $otherTeam = Team::factory()->create();
    $outsider = User::factory()->create();
    $outsider->teams()->attach($otherTeam, ['role' => 'owner']);
    actingAsImageUpdateUser($outsider, $otherTeam);

    Livewire::test(ImageUpdates::class, ['resource' => $this->application])->assertForbidden();

    expect($this->application->imageUpdateChecks()->count())->toBe(0);
});

it('shows Update available next to the status only for images that notify', function (string $onUpdate, bool $shown) {
    actingAsImageUpdateUser($this->admin, $this->team);
    $check = app(ResourceImages::class)->sync($this->application)['web'];
    $check->update(['update_available' => true, 'on_update' => $onUpdate]);

    $component = Livewire::test(Status::class, ['application' => $this->application]);

    $shown ? $component->assertSee('Update available') : $component->assertDontSee('Update available');
})->with([
    'notify' => [ImageUpdateCheck::ON_UPDATE_NOTIFY, true],
    'deploy and notify' => [ImageUpdateCheck::ON_UPDATE_DEPLOY_AND_NOTIFY, true],
    'deploy only' => [ImageUpdateCheck::ON_UPDATE_DEPLOY, false],
]);

it('checks found updates again after a successful deployment', function () {
    Process::fake(['*' => Process::result(output: '')]);
    Notification::fake();
    Queue::fake([CheckImageUpdateJob::class, ApplicationDeploymentJob::class]);
    $checks = app(ResourceImages::class)->sync($this->application);
    $checks['web']->update(['update_available' => true]);
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->application->destination->server->id,
        'destination_id' => $this->application->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);

    $job = new ApplicationDeploymentJob($deployment->id);
    (fn () => $this->completeDeployment())->call($job);

    Queue::assertPushed(CheckImageUpdateJob::class, 1);
    Queue::assertPushed(CheckImageUpdateJob::class, fn (CheckImageUpdateJob $job) => $job->imageUpdateCheck->is($checks['web']));
});

it('checks found updates of a service again after it starts', function () {
    Queue::fake([CheckImageUpdateJob::class]);
    $service = imageUpdateTestService("services:\n  web:\n    image: nginx:1\n  db:\n    image: postgres:16\n", $this->team);
    $checks = app(ResourceImages::class)->sync($service);
    $checks['db']->update(['update_available' => true]);

    Event::fake([ServiceStartFinished::class]);

    (new RecheckImageUpdatesAfterServiceStart)->handle(new ServiceStartFinished($service->id));

    Event::assertListening(ServiceStartFinished::class, RecheckImageUpdatesAfterServiceStart::class);
    Queue::assertPushed(CheckImageUpdateJob::class, 1);
    Queue::assertPushed(CheckImageUpdateJob::class, fn (CheckImageUpdateJob $job) => $job->imageUpdateCheck->is($checks['db']));
});

it('deletes the checks with the application', function () {
    $check = app(ResourceImages::class)->sync($this->application)['web'];

    Process::fake(['*' => Process::result(output: '')]);
    $this->application->forceDelete();

    expect(ImageUpdateCheck::find($check->id))->toBeNull();
});
