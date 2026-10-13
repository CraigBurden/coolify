<?php

namespace App\Jobs;

use App\Actions\Service\DeployServiceApplication;
use App\Models\Application;
use App\Models\ImageUpdateCheck;
use App\Models\Server;
use App\Models\Service;
use App\Services\ImageUpdates\ResourceImages;
use App\Services\ScheduledJobDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Compares the digest of the image a resource runs with the digest that its registry now serves for
 * the same tag. Both commands run on the resource's server, so its registry logins apply.
 */
class CheckImageUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REGISTRY_TIMEOUT_SECONDS = 60;

    public $tries = 1;

    public $timeout = 180;

    public function __construct(
        public ImageUpdateCheck $imageUpdateCheck,
        public ?string $occurrenceUuid = null,
    ) {
        $this->onQueue(crons_queue());
    }

    public function middleware(): array
    {
        return [
            ScheduledJobDeliveryService::withoutOverlapping('image-update-check-'.$this->imageUpdateCheck->id, $this->occurrenceUuid)
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function handle(ResourceImages $resourceImages): void
    {
        $deliveries = app(ScheduledJobDeliveryService::class);
        $claimToken = $this->job?->uuid() ?? $this->occurrenceUuid;
        if ($this->occurrenceUuid && ! $deliveries->claim($this->occurrenceUuid, $claimToken)) {
            return;
        }

        try {
            $this->check($resourceImages);
        } finally {
            if ($this->occurrenceUuid) {
                $deliveries->complete($this->occurrenceUuid, $claimToken);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->occurrenceUuid) {
            app(ScheduledJobDeliveryService::class)->fail($this->occurrenceUuid, $this->job?->uuid() ?? $this->occurrenceUuid);
        }
    }

    private function check(ResourceImages $resourceImages): void
    {
        $check = $this->imageUpdateCheck->fresh(['resource']);
        $resource = $check?->resource;
        if (! $resource instanceof Application && ! $resource instanceof Service) {
            $check?->delete();

            return;
        }

        $configuredImage = $resourceImages->configured($resource)[$check->service_name] ?? null;
        if ($configuredImage === null) {
            $check->delete();

            return;
        }

        $image = null;
        try {
            $image = $resourceImages->resolve($resource, $configuredImage);
            $server = $check->server();
            if (! $server || ! $server->isFunctional()) {
                throw new RuntimeException('The server is not reachable.');
            }

            $latest = $this->registryDigests($server, $image);
            $running = $this->runningDigests($server, $check, $resource, $image);
        } catch (Throwable $e) {
            $check->update([
                'image' => $image ?? $configuredImage,
                'last_checked_at' => now(),
                'last_error' => Str::limit(trim($e->getMessage()), 1000),
            ]);

            return;
        }

        $updateAvailable = array_intersect($running, $latest['all']) === [];

        $check->update([
            'image' => $image,
            'current_digest' => $running[0],
            'latest_digest' => $latest['digest'],
            'update_available' => $updateAvailable,
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        if ($updateAvailable && $check->shouldAutoDeploy() && $check->auto_deployed_digest !== $latest['digest']) {
            $this->deploy($check, $resource, $latest['digest']);
        }
    }

    /**
     * @return array{digest: string, all: list<string>} The digest of the tag, plus its platform manifests.
     */
    private function registryDigests(Server $server, string $image): array
    {
        $output = (string) instant_remote_process([
            'docker buildx imagetools inspect '.escapeshellarg($image).' --format '.escapeshellarg('{{json .Manifest}}'),
        ], $server, timeout: self::REGISTRY_TIMEOUT_SECONDS);

        // buildx prints the manifest as indented JSON over several lines.
        $jsonStart = strpos($output, '{');
        $manifest = $jsonStart === false ? null : json_decode(substr($output, $jsonStart), true);
        $digest = data_get($manifest, 'digest');
        if (! is_string($digest) || ! $this->isDigest($digest)) {
            throw new RuntimeException('The registry did not return a digest for '.$image.'.');
        }

        $platformDigests = collect(data_get($manifest, 'manifests', []))
            ->pluck('digest')
            ->filter(fn ($value): bool => is_string($value) && $this->isDigest($value));

        return ['digest' => $digest, 'all' => $platformDigests->prepend($digest)->unique()->values()->all()];
    }

    /**
     * The repository digests of the image the running container uses. Without a running container,
     * the image that the tag points to on the server.
     *
     * @return non-empty-list<string>
     */
    private function runningDigests(Server $server, ImageUpdateCheck $check, Application|Service $resource, string $image): array
    {
        $filters = $check->service_name === ImageUpdateCheck::STANDALONE_IMAGE
            ? ['label=coolify.pullRequestId=0']
            : ['label=com.docker.compose.service='.$check->service_name];
        $type = $resource instanceof Application ? 'application' : 'service';

        $script = containerIdsByOwnerScript($type, $resource->uuid, $filters, all: false)
            .'; ref=$(echo "$container_ids" | head -n 1)'
            .'; if [ -n "$ref" ]; then ref=$(docker inspect --format "{{.Image}}" "$ref"); else ref="$1"; fi'
            .'; docker image inspect --format "{{json .RepoDigests}}" "$ref" 2>/dev/null || echo "[]"';

        $output = (string) instant_remote_process(['sh -c '.escapeshellarg($script).' sh '.escapeshellarg($image)], $server);

        preg_match_all('/sha256:[a-f0-9]{64}/', $output, $matches);
        $digests = array_values(array_unique($matches[0]));
        if ($digests === []) {
            throw new RuntimeException('The image is not on the server yet. Deploy the resource first.');
        }

        return $digests;
    }

    private function deploy(ImageUpdateCheck $check, Application|Service $resource, string $digest): void
    {
        if (! $resource->isRunning()) {
            Log::info('Image update found, but the resource is not running, so it was not deployed.', [
                'image_update_check' => $check->uuid,
            ]);

            return;
        }

        if ($resource instanceof Application) {
            $result = queue_application_deployment(application: $resource, deployment_uuid: new_public_id());
            if (data_get($result, 'status') === 'queue_full') {
                $check->update(['last_error' => 'An update was found, but the deployment queue is full. It will be tried again on the next check.']);

                return;
            }
        } else {
            $serviceApplication = $resource->byName($check->service_name);
            if (! $serviceApplication) {
                return;
            }
            DeployServiceApplication::dispatch($serviceApplication, true);
        }

        $check->update(['auto_deployed_digest' => $digest]);
        auditLog('image_update.auto_deploy_queued', [
            'team_id' => $check->team_id,
            'resource_type' => $resource->type(),
            'resource_uuid' => $resource->uuid,
            'service_name' => $check->service_name,
            'image' => $check->image,
            'digest' => $digest,
        ]);
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1;
    }
}
