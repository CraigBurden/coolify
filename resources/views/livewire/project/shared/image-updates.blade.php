<form wire:submit="submit" wire:poll.15s class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" targets="schedules" />

    <x-application.settings-section id="image-updates-section" title="Image updates" flush
        helper="Check the registry for a newer image with the same tag. Checks run on the server that hosts this resource, so they use its registry logins. Leave the schedule empty to never check an image.">
        @forelse ($checks as $check)
            @php
                [$statusLabel, $statusType] = match (true) {
                    filled($check->last_error) => ['Check failed', 'error'],
                    $check->update_available => ['Update available', 'warning'],
                    $check->last_checked_at !== null => ['Up to date', 'success'],
                    default => ['Not checked yet', 'neutral'],
                };
            @endphp
            <div wire:key="image-update-check-{{ $check->uuid }}"
                class="flex flex-col gap-4 border-b border-neutral-200 px-4 py-4 last:border-b-0 dark:border-white/[0.07]">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $check->displayName() }}</h4>
                            <x-status-badge :status="$statusLabel" :type="$statusType" :title="$check->last_error" />
                        </div>
                        @php($registryUrl = \App\Services\ImageUpdates\RegistryLink::for($check->image ?? $check->configured_image))
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 break-all font-mono text-xs text-neutral-600 dark:text-fg-dim">
                            <span>{{ $check->configured_image }}</span>
                            @if (filled($check->image) && $check->image !== $check->configured_image)
                                <span class="text-neutral-400 dark:text-fg-dim/70">→ {{ $check->image }}</span>
                            @endif
                            @if ($registryUrl)
                                <a href="{{ $registryUrl }}" target="_blank" rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-sans text-[13px] underline underline-offset-2 hover:text-black dark:hover:text-fg">
                                    Open in registry
                                    <x-external-link class="inline-flex size-3 text-current" />
                                </a>
                            @endif
                        </p>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-[auto_minmax(0,1fr)]">
                            @foreach ([
                                ['Running', $check->current_digest, 'Unknown until the first successful check'],
                                ['Registry', $check->latest_digest, 'Unknown until the first successful check'],
                            ] as [$digestLabel, $digest, $emptyText])
                                <dt class="text-neutral-500 dark:text-fg-dim">{{ $digestLabel }}</dt>
                                <dd class="flex min-w-0 items-center gap-1">
                                    @if ($digest)
                                        <code class="truncate font-mono text-xs text-neutral-700 dark:text-fg" title="{{ $digest }}">{{ str($digest)->limit(19, '…') }}</code>
                                        <x-copy-button :value="$digest" label="Copy {{ strtolower($digestLabel) }} digest" />
                                    @else
                                        <span class="text-neutral-400 dark:text-fg-dim/70">{{ $emptyText }}</span>
                                    @endif
                                </dd>
                            @endforeach
                            <dt class="text-neutral-500 dark:text-fg-dim">Last check</dt>
                            <dd class="min-w-0">
                                @if ($check->last_checked_at)
                                    <span @class([
                                        'text-error' => filled($check->last_error),
                                        'text-warning' => blank($check->last_error) && $check->update_available,
                                        'text-neutral-700 dark:text-fg' => blank($check->last_error) && ! $check->update_available,
                                    ])>
                                        @if (filled($check->last_error))
                                            Failed: {{ $check->last_error }}
                                        @elseif ($check->update_available)
                                            The registry has a newer image for this tag
                                        @else
                                            The running image matches the registry
                                        @endif
                                    </span>
                                    <span class="text-neutral-500 dark:text-fg-dim">· {{ formatDateInServerTimezone($check->last_checked_at, $server) }}</span>
                                @else
                                    <span class="text-neutral-400 dark:text-fg-dim/70">Never checked</span>
                                @endif
                            </dd>
                        </dl>
                    </div>
                    <x-forms.button wire:click="checkNow('{{ $check->uuid }}')" canGate="update" :canResource="$resource">
                        <x-reicon name="refresh" class="size-3.5" />
                        Check now
                    </x-forms.button>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="schedules.{{ $check->uuid }}.frequency" label="Check schedule"
                        placeholder="Never" helper="Use hourly, daily, weekly, monthly, or a cron expression in the server's timezone. Leave it empty to never check this image."
                        canGate="update" :canResource="$resource" />
                    <x-forms.listbox id="schedules.{{ $check->uuid }}.on_update" htmlId="image-update-on-update-{{ $check->uuid }}"
                        label="When an update is found" canGate="update" :canResource="$resource" :options="[
                            ['value' => 'notify', 'label' => 'Show a notice', 'description' => 'Show Update available next to the resource status.'],
                            ['value' => 'deploy', 'label' => 'Deploy automatically', 'description' => 'Pull the new image and redeploy while the resource is running.'],
                            ['value' => 'deploy_and_notify', 'label' => 'Deploy and show a notice', 'description' => 'Redeploy, and show Update available until the new image runs.'],
                        ]" />
                </div>
            </div>
        @empty
            <x-empty size="sm" title="No registry images"
                description="Images that this resource pulls from a registry appear here. Images that Docker Compose builds from source cannot be checked."
                icon-name="layers" />
        @endforelse
    </x-application.settings-section>
</form>
