@props(['resource'])

@php
    $pendingImageUpdates = $resource->imageUpdateChecks()
        ->where('update_available', true)
        ->whereIn('on_update', [\App\Models\ImageUpdateCheck::ON_UPDATE_NOTIFY, \App\Models\ImageUpdateCheck::ON_UPDATE_DEPLOY_AND_NOTIFY])
        ->get();
@endphp

@if ($pendingImageUpdates->isNotEmpty())
    @php
        $imageUpdatesUrl = route($resource instanceof \App\Models\Service ? 'project.service.image-updates' : 'project.application.image-updates', [
            'project_uuid' => $resource->environment->project->uuid,
            'environment_uuid' => $resource->environment->uuid,
            ($resource instanceof \App\Models\Service ? 'service_uuid' : 'application_uuid') => $resource->uuid,
        ]);
    @endphp
    <a href="{{ $imageUpdatesUrl }}" {{ wireNavigate() }} class="inline-flex">
        <x-status-badge status="Update available" type="warning"
            title="A newer image is available for {{ $pendingImageUpdates->map->displayName()->join(', ') }}." />
    </a>
@endif
