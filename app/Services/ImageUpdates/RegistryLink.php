<?php

namespace App\Services\ImageUpdates;

/**
 * The web page of an image in its registry, for the registries that have a public page per image.
 */
class RegistryLink
{
    private const DOCKER_HUB_HOSTS = ['docker.io', 'index.docker.io', 'registry-1.docker.io'];

    public static function for(?string $image): ?string
    {
        if (blank($image) || str_contains($image, '$')) {
            return null;
        }

        $reference = strtok($image, '@');
        $segments = explode('/', $reference);
        $registry = count($segments) > 1 && preg_match('/[.:]|^localhost$/', $segments[0]) === 1
            ? strtolower(array_shift($segments))
            : 'docker.io';

        $last = array_pop($segments);
        $tag = str_contains($last, ':') ? substr($last, strpos($last, ':') + 1) : null;
        $segments[] = strtok($last, ':');
        $repository = implode('/', $segments);

        return match (true) {
            in_array($registry, self::DOCKER_HUB_HOSTS, true) => count($segments) === 1 || $segments[0] === 'library'
                ? 'https://hub.docker.com/_/'.end($segments).'/tags'.($tag ? '?name='.rawurlencode($tag) : '')
                : "https://hub.docker.com/r/{$repository}/tags".($tag ? '?name='.rawurlencode($tag) : ''),
            // ghcr.io redirects a browser to the package page on GitHub.
            $registry === 'ghcr.io' => "https://ghcr.io/{$repository}",
            $registry === 'quay.io' => "https://quay.io/repository/{$repository}?tab=tags",
            $registry === 'public.ecr.aws' => "https://gallery.ecr.aws/{$repository}",
            $registry === 'mcr.microsoft.com' => "https://mcr.microsoft.com/artifact/mar/{$repository}/tags",
            default => null,
        };
    }
}
