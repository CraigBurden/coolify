<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\CheckImageUpdateJob;
use App\Models\Application;
use App\Models\ImageUpdateCheck;
use App\Models\Service;
use App\Services\ImageUpdates\ResourceImages;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ImageUpdates extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application|Service $resource;

    /**
     * @var array<string, array{frequency: ?string, on_update: string}> Keyed by check UUID.
     */
    public array $schedules = [];

    public function mount(): void
    {
        $this->authorize('view', $this->resource);

        if (Gate::allows('update', $this->resource)) {
            app(ResourceImages::class)->sync($this->resource);
        }

        $this->loadSchedules();
    }

    public function submit(): void
    {
        $this->authorize('update', $this->resource);

        $this->validate([
            'schedules' => ['array'],
            'schedules.*.frequency' => ['nullable', 'string', 'max:255'],
            'schedules.*.on_update' => ['required', Rule::in(ImageUpdateCheck::ON_UPDATE_OPTIONS)],
        ]);

        foreach ($this->schedules as $uuid => $schedule) {
            $frequency = trim((string) ($schedule['frequency'] ?? ''));
            if ($frequency !== '' && ! CronExpression::isValidExpression(VALID_CRON_STRINGS[$frequency] ?? $frequency)) {
                $this->addError("schedules.{$uuid}.frequency", 'Use hourly, daily, weekly, monthly, or a cron expression.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $checks = $this->resource->imageUpdateChecks()->get()->keyBy('uuid');
        foreach ($this->schedules as $uuid => $schedule) {
            $check = $checks->get($uuid);
            if (! $check) {
                continue;
            }

            $check->fill([
                'frequency' => filled(trim((string) $schedule['frequency'])) ? trim((string) $schedule['frequency']) : null,
                'on_update' => $schedule['on_update'],
            ]);
            $changedFields = auditChangedFields($check);
            if ($changedFields === []) {
                continue;
            }

            $check->save();
            auditLog('ui.image_update_check.updated', [
                'team_id' => $check->team_id,
                'resource_type' => $this->resource->type(),
                'resource_uuid' => $this->resource->uuid,
                'service_name' => $check->service_name,
                'changed_fields' => $changedFields,
            ]);
        }

        $this->loadSchedules();
        $this->dispatch('success', 'Image update checks saved.');
    }

    public function checkNow(string $uuid): void
    {
        $this->authorize('update', $this->resource);

        $check = $this->resource->imageUpdateChecks()->where('uuid', $uuid)->firstOrFail();
        CheckImageUpdateJob::dispatch($check);

        $this->dispatch('success', 'Checking the registry for a newer image.');
    }

    public function render(): View
    {
        return view('livewire.project.shared.image-updates', [
            'checks' => $this->checks(),
            'server' => $this->resource instanceof Application
                ? $this->resource->destination?->server
                : $this->resource->server,
        ]);
    }

    /**
     * The checks of the configured images, in configuration order.
     *
     * @return Collection<int, ImageUpdateCheck>
     */
    private function checks(): Collection
    {
        $checks = $this->resource->imageUpdateChecks()->get()->keyBy('service_name');

        return collect(app(ResourceImages::class)->configured($this->resource))
            ->map(function (string $image, string $serviceName) use ($checks): ?ImageUpdateCheck {
                $check = $checks->get($serviceName);
                $check?->setAttribute('configured_image', $image);

                return $check;
            })
            ->filter()
            ->values();
    }

    private function loadSchedules(): void
    {
        $this->schedules = $this->checks()
            ->mapWithKeys(fn (ImageUpdateCheck $check): array => [$check->uuid => [
                'frequency' => $check->frequency,
                'on_update' => $check->on_update,
            ]])
            ->all();
    }
}
