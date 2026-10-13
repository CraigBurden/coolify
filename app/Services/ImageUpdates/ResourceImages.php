<?php

namespace App\Services\ImageUpdates;

use App\Models\Application;
use App\Models\ImageUpdateCheck;
use App\Models\Service;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Finds the registry images of an application or service and resolves their references the way
 * Docker Compose interpolates them from the resource's environment variables.
 */
class ResourceImages
{
    private const IMAGE_REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-\/:]*$/';

    private const MAX_IMAGE_REFERENCE_LENGTH = 512;

    /**
     * The images as written in the configuration, keyed by Compose service name. Services that
     * Compose builds from source are left out, because no registry holds their image.
     *
     * @return array<string, string>
     */
    public function configured(Application|Service $resource): array
    {
        if ($resource instanceof Application && $resource->build_pack === 'dockerimage') {
            return $this->dockerImageApplicationImage($resource);
        }

        if ($resource instanceof Application && $resource->build_pack !== 'dockercompose') {
            return [];
        }

        try {
            $compose = parseDockerComposeYaml((string) $resource->docker_compose_raw);
        } catch (Throwable) {
            return [];
        }

        $images = [];
        foreach ((array) data_get($compose, 'services', []) as $serviceName => $service) {
            if (! is_array($service) || array_key_exists('build', $service)) {
                continue;
            }
            $image = data_get($service, 'image');
            if (is_string($image) && filled(trim($image))) {
                $images[(string) $serviceName] = trim($image);
            }
        }

        return $images;
    }

    /**
     * Resolve the variables in an image reference with the resource's environment variables.
     *
     * @throws InvalidArgumentException When a variable is missing or the result is not a checkable tag.
     */
    public function resolve(Application|Service $resource, string $image): string
    {
        $variables = $resource->environment_variables()->get()->keyBy('key');
        $resolved = trim($this->interpolate($image, fn (string $name): ?string => $variables->has($name)
            ? (string) $variables->get($name)->real_value
            : null));

        if (str_contains($resolved, '@')) {
            throw new InvalidArgumentException('The image is pinned to a digest, so it cannot change.');
        }

        if (strlen($resolved) > self::MAX_IMAGE_REFERENCE_LENGTH || preg_match(self::IMAGE_REFERENCE_PATTERN, $resolved) !== 1) {
            throw new InvalidArgumentException("The image reference \"{$resolved}\" is not valid.");
        }

        return $resolved;
    }

    /**
     * Create a check for every configured image that has none and delete the checks of removed images.
     *
     * @return Collection<string, ImageUpdateCheck> Keyed by service name, in configuration order.
     */
    public function sync(Application|Service $resource): Collection
    {
        $configured = $this->configured($resource);
        $checks = $resource->imageUpdateChecks()->get()->keyBy('service_name');

        $checks->reject(fn (ImageUpdateCheck $check): bool => array_key_exists($check->service_name, $configured))->each->delete();

        return collect($configured)->map(fn (string $image, string $serviceName): ImageUpdateCheck => $checks->get($serviceName)
            ?? $resource->imageUpdateChecks()->create([
                'team_id' => $resource->team()->id,
                'service_name' => $serviceName,
                'on_update' => ImageUpdateCheck::ON_UPDATE_NOTIFY,
            ]));
    }

    /**
     * @return array<string, string>
     */
    private function dockerImageApplicationImage(Application $application): array
    {
        $name = trim((string) $application->docker_registry_image_name);
        if ($name === '') {
            return [];
        }

        $tag = trim((string) $application->docker_registry_image_tag);
        // A digest is stored as name "repo@sha256" and tag "sha256-<hex>" (see New\DockerImage).
        if (str_ends_with($name, '@sha256') && str_starts_with($tag, 'sha256-')) {
            return [ImageUpdateCheck::STANDALONE_IMAGE => $name.':'.substr($tag, 7)];
        }

        return [ImageUpdateCheck::STANDALONE_IMAGE => $name.':'.($tag !== '' ? $tag : 'latest')];
    }

    /**
     * Docker Compose interpolation: $$ escapes, ${VAR}, $VAR, and the :-, -, :? and ? operators.
     *
     * @param  Closure(string): ?string  $lookup  Returns null for a variable that is not set.
     */
    private function interpolate(string $value, Closure $lookup): string
    {
        $result = '';
        $length = strlen($value);
        $position = 0;

        while ($position < $length) {
            if ($value[$position] !== '$') {
                $result .= $value[$position];
                $position++;

                continue;
            }

            $next = $value[$position + 1] ?? '';
            if ($next === '$') {
                $result .= '$';
                $position += 2;

                continue;
            }

            if ($next === '{') {
                $braces = extractBalancedBraceContent($value, $position + 1);
                if ($braces === null) {
                    throw new InvalidArgumentException('The image reference has an unclosed variable.');
                }
                $result .= $this->expand($braces['content'], $lookup);
                $position = $braces['end'] + 1;

                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $value, $matches, 0, $position + 1) === 1) {
                $result .= $this->expand($matches[0], $lookup);
                $position += 1 + strlen($matches[0]);

                continue;
            }

            $result .= '$';
            $position++;
        }

        return $result;
    }

    /**
     * @param  Closure(string): ?string  $lookup
     */
    private function expand(string $expression, Closure $lookup): string
    {
        $parts = splitOnOperatorOutsideNested($expression);
        $name = $parts['variable'] ?? $expression;
        $value = $lookup($name);

        if ($parts === null) {
            return $value ?? throw new InvalidArgumentException("The variable {$name} is not set.");
        }

        return match ($parts['operator']) {
            ':-' => filled($value) ? $value : $this->interpolate($parts['default'], $lookup),
            '-' => $value ?? $this->interpolate($parts['default'], $lookup),
            ':?' => filled($value) ? $value : throw new InvalidArgumentException("The variable {$name} is not set."),
            default => $value ?? throw new InvalidArgumentException("The variable {$name} is not set."),
        };
    }
}
