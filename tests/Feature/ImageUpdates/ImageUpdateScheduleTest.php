<?php

use App\Jobs\CheckImageUpdateJob;
use App\Jobs\ScheduledJobManager;
use App\Models\ImageUpdateCheck;
use App\Models\ScheduledJobDelivery;
use App\Models\Server;
use App\Services\ImageUpdates\ResourceImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['constants.coolify.self_hosted' => true]);
});

function scheduledImageUpdateCheck(?string $frequency): ImageUpdateCheck
{
    $application = imageUpdateTestApplication(['docker_compose_raw' => "services:\n  web:\n    image: nginx:1\n"]);
    $check = app(ResourceImages::class)->sync($application)['web'];
    $check->update(['frequency' => $frequency]);

    return $check->fresh();
}

it('dispatches each scheduled image check once per occurrence and skips checks without a schedule', function () {
    $this->travelTo(now()->setTimezone('UTC')->startOfDay()->addMinute());
    Queue::fake([CheckImageUpdateJob::class]);
    $daily = scheduledImageUpdateCheck('daily');
    scheduledImageUpdateCheck(null);
    scheduledImageUpdateCheck('0 12 * * *');

    (new ScheduledJobManager('image-update-checks'))->handle();
    (new ScheduledJobManager('image-update-checks'))->handle();

    Queue::assertPushed(CheckImageUpdateJob::class, 1);
    Queue::assertPushed(CheckImageUpdateJob::class, fn (CheckImageUpdateJob $job) => $job->imageUpdateCheck->is($daily) && filled($job->occurrenceUuid));
});

it('records the occurrence as skipped when the server is not functional', function () {
    $this->travelTo(now()->setTimezone('UTC')->startOfDay()->addMinute());
    Queue::fake([CheckImageUpdateJob::class]);
    $check = scheduledImageUpdateCheck('daily');
    $check->server()->settings()->update(['is_reachable' => false]);
    Server::flushIdentityMap();

    (new ScheduledJobManager('image-update-checks'))->handle();

    Queue::assertNotPushed(CheckImageUpdateJob::class);
});

it('completes its occurrence after the check', function () {
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    $this->travelTo(now()->setTimezone('UTC')->startOfDay()->addMinute());
    Queue::fake([CheckImageUpdateJob::class]);
    Process::fake(['*' => Process::result(errorOutput: 'registry unavailable', exitCode: 1)]);
    $check = scheduledImageUpdateCheck('daily');

    (new ScheduledJobManager('image-update-checks'))->handle();
    $job = Queue::pushed(CheckImageUpdateJob::class)->first();
    $job->handle(app(ResourceImages::class));

    expect(ScheduledJobDelivery::query()->where('schedule_key', $check->scheduleKey())->exists())->toBeFalse()
        ->and($check->fresh()->last_error)->toContain('registry unavailable');
});
