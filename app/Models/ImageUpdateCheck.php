<?php

namespace App\Models;

use App\Jobs\CheckImageUpdateJob;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Schedule and last result of the registry check for one image of an application or service.
 * service_name is the Compose service name, or STANDALONE_IMAGE for a Docker image application.
 */
class ImageUpdateCheck extends BaseModel
{
    use HasFactory;

    /**
     * service_name of the single image of a Docker image application. It is not a valid Compose service name.
     */
    public const STANDALONE_IMAGE = ':image';

    public const ON_UPDATE_NOTIFY = 'notify';

    public const ON_UPDATE_DEPLOY = 'deploy';

    public const ON_UPDATE_DEPLOY_AND_NOTIFY = 'deploy_and_notify';

    public const ON_UPDATE_OPTIONS = [
        self::ON_UPDATE_NOTIFY,
        self::ON_UPDATE_DEPLOY,
        self::ON_UPDATE_DEPLOY_AND_NOTIFY,
    ];

    protected $fillable = [
        'team_id',
        'resource_type',
        'resource_id',
        'service_name',
        'frequency',
        'on_update',
        'image',
        'current_digest',
        'latest_digest',
        'auto_deployed_digest',
        'update_available',
        'last_checked_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'update_available' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): ?Server
    {
        $resource = $this->resource;

        return match (true) {
            $resource instanceof Application => $resource->destination?->server,
            $resource instanceof Service => $resource->destination?->server ?? $resource->server,
            default => null,
        };
    }

    public function isScheduled(): bool
    {
        return filled($this->frequency);
    }

    public function shouldNotify(): bool
    {
        return in_array($this->on_update, [self::ON_UPDATE_NOTIFY, self::ON_UPDATE_DEPLOY_AND_NOTIFY], true);
    }

    public function shouldAutoDeploy(): bool
    {
        return in_array($this->on_update, [self::ON_UPDATE_DEPLOY, self::ON_UPDATE_DEPLOY_AND_NOTIFY], true);
    }

    public function displayName(): string
    {
        return $this->service_name === self::STANDALONE_IMAGE ? 'Application image' : $this->service_name;
    }

    /**
     * Check again the images with a found update, after a deployment or start may have pulled them.
     */
    public static function recheckPendingUpdates(Application|Service $resource): void
    {
        $resource->imageUpdateChecks()
            ->where('update_available', true)
            ->get()
            ->each(fn (self $check) => CheckImageUpdateJob::dispatch($check));
    }

    public function scheduleKey(): string
    {
        return "image-update-check:{$this->id}";
    }
}
