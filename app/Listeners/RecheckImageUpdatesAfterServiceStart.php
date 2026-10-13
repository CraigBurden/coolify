<?php

namespace App\Listeners;

use App\Events\ServiceStartFinished;
use App\Models\ImageUpdateCheck;
use App\Models\Service;

/**
 * A start can pull new images, so the found updates of the service are checked again.
 */
class RecheckImageUpdatesAfterServiceStart
{
    public function handle(ServiceStartFinished $event): void
    {
        $service = Service::query()->find($event->serviceId);
        if ($service) {
            ImageUpdateCheck::recheckPendingUpdates($service);
        }
    }
}
