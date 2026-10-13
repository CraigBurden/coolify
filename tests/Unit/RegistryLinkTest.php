<?php

use App\Services\ImageUpdates\RegistryLink;

it('links an image to its registry page', function (string $image, ?string $expected) {
    expect(RegistryLink::for($image))->toBe($expected);
})->with([
    'Docker Hub official image' => ['nginx:1.27-alpine', 'https://hub.docker.com/_/nginx/tags?name=1.27-alpine'],
    'Docker Hub library prefix' => ['docker.io/library/redis:7', 'https://hub.docker.com/_/redis/tags?name=7'],
    'Docker Hub user image' => ['grafana/grafana', 'https://hub.docker.com/r/grafana/grafana/tags'],
    'GitHub' => ['ghcr.io/acme/web:1.2', 'https://ghcr.io/acme/web'],
    'Quay' => ['quay.io/prometheus/node-exporter:v1.8.0', 'https://quay.io/repository/prometheus/node-exporter?tab=tags'],
    'Amazon ECR Public' => ['public.ecr.aws/nginx/nginx:1.27', 'https://gallery.ecr.aws/nginx/nginx'],
    'Microsoft' => ['mcr.microsoft.com/dotnet/aspnet:8.0', 'https://mcr.microsoft.com/artifact/mar/dotnet/aspnet/tags'],
    'private registry' => ['registry.example.com:5000/team/app:1', null],
    'unresolved variable' => ['nginx:${TAG}', null],
]);
