<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;

/**
 * A reachable server and an environment of a new team, for image update check tests.
 *
 * @return array{team: Team, server: Server, destination: StandaloneDocker, environment: Environment}
 */
function imageUpdateTestEnvironment(?Team $team = null): array
{
    $team ??= Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => (PrivateKey::where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]))->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [
        'team' => $team,
        'server' => $server->fresh(),
        'destination' => StandaloneDocker::where('server_id', $server->id)->firstOrFail(),
        'environment' => Environment::factory()->create(['project_id' => $project->id]),
    ];
}

function imageUpdateTestApplication(array $attributes = [], ?Team $team = null): Application
{
    $context = imageUpdateTestEnvironment($team);

    return Application::factory()->create([
        'environment_id' => $context['environment']->id,
        'destination_id' => $context['destination']->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'status' => 'running:healthy',
        ...$attributes,
    ]);
}

function imageUpdateTestService(string $compose, ?Team $team = null): Service
{
    $context = imageUpdateTestEnvironment($team);

    return Service::factory()->create([
        'environment_id' => $context['environment']->id,
        'destination_id' => $context['destination']->id,
        'destination_type' => StandaloneDocker::class,
        'server_id' => $context['server']->id,
        'docker_compose_raw' => $compose,
    ]);
}
