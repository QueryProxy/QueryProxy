<div>
    <x-ui.page-header
        title="Connections"
        subtitle="Target databases this team can reach through QueryProxy. Credentials are encrypted at rest and are never rendered back into the browser.">
        <x-slot:actions>
            <x-ui.btn wire:click="openCreate" variant="primary" icon="plus">New connection</x-ui.btn>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($testResult)
        <x-ui.alert :tone="$testResult['ok'] ? 'ok' : 'danger'" :icon="$testResult['ok'] ? 'check' : 'x'" class="mb-3.5">
            @if ($testResult['ok'])
                Connection OK — {{ $testResult['latency_ms'] }} ms
            @else
                Connection failed: {{ $testResult['error'] }}
            @endif
            @php($tlsState = $testResult['tls']['state'] ?? 'none')
            @if ($tlsState !== 'none')
                <span class="block">
                    TLS:
                    @switch($tlsState)
                        @case('enforced') required ({{ $testResult['tls']['mode'] }}) @break
                        @case('not_enforced') not enforced ({{ $testResult['tls']['mode'] }}) @break
                        @default left to the driver default
                    @endswitch
                    @if ($testResult['ok'])
                        —
                        @if ($testResult['tls']['encrypted'] === true) this session is encrypted
                        @elseif ($testResult['tls']['encrypted'] === false) this session is NOT encrypted
                        @else encryption of this session could not be read from the server
                        @endif
                    @endif
                </span>
            @endif
        </x-ui.alert>
    @endif

    @if ($showForm)
        <x-ui.panel padded class="mb-3.5 border-accent-line">
            <h2 class="mb-3.5 font-display text-[13px] font-semibold text-ink">{{ $editingId ? 'Edit connection' : 'New connection' }}</h2>

            <form wire:submit="save" class="grid grid-cols-1 gap-3.5 sm:grid-cols-3">
                <x-ui.field label="Name" :error="$errors->first('name')">
                    <x-ui.input type="text" wire:model="name" placeholder="e.g. orders-prod" />
                </x-ui.field>

                <x-ui.field label="Driver">
                    <x-ui.select wire:model.live="driver">
                        @foreach ($drivers as $d)
                            <option value="{{ $d->value }}">{{ $d->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div></div>

                @if ($driver === 'sqlite' && $sqliteDirUnrestricted)
                    <x-ui.alert tone="pending" icon="alert" class="sm:col-span-3">
                        <code class="font-mono">QUERYPROXY_SQLITE_ALLOWED_DIR</code> is not set, so this connection may point at any SQLite file the application can read. Set it to pin SQLite connections to one directory.
                    </x-ui.alert>
                @endif

                @if ($driver !== 'sqlite')
                    <x-ui.field label="Host" :error="$errors->first('host')">
                        <x-ui.input type="text" wire:model="host" />
                    </x-ui.field>

                    <x-ui.field label="Port" hint="blank = default" :error="$errors->first('port')">
                        <x-ui.input type="number" wire:model="port" />
                    </x-ui.field>
                @endif

                <x-ui.field :label="$driver === 'sqlite' ? 'Database file path' : 'Database'" :error="$errors->first('database')">
                    <x-ui.input type="text" wire:model="database" class="font-mono" />
                </x-ui.field>

                @if ($driver !== 'sqlite')
                    <x-ui.field label="Username" :error="$errors->first('username')">
                        <x-ui.input type="text" wire:model="username" autocomplete="off" />
                    </x-ui.field>

                    <x-ui.field label="Password" :hint="$editingId ? 'blank = keep current' : null" :error="$errors->first('password')">
                        <x-ui.input type="password" wire:model="password" autocomplete="new-password" />
                    </x-ui.field>

                    <x-ui.field label="Read-only username" hint="optional" :error="$errors->first('readUsername')">
                        <x-ui.input type="text" wire:model="readUsername" autocomplete="off" />
                    </x-ui.field>

                    <x-ui.field label="Read-only password" :hint="$editingId ? 'blank = keep current' : 'optional'" :error="$errors->first('readPassword')">
                        <x-ui.input type="password" wire:model="readPassword" autocomplete="new-password" />
                    </x-ui.field>

                    <div></div>

                    <div class="sm:col-span-3 border-t border-line pt-3.5">
                        <p class="eyebrow mb-2.5">TLS</p>
                    </div>

                    @if ($driver === 'pgsql')
                        <x-ui.field label="SSL mode" :error="$errors->first('tlsMode')">
                            <x-ui.select wire:model.live="tlsMode">
                                <option value="require">require (encrypted, server not verified)</option>
                                <option value="verify-ca">verify-ca</option>
                                <option value="verify-full">verify-full</option>
                                <option value="prefer">prefer (falls back to plaintext)</option>
                                <option value="disable">disable (plaintext)</option>
                            </x-ui.select>
                        </x-ui.field>
                    @else
                        <x-ui.field label="Encryption" :error="$errors->first('tlsMode')">
                            <x-ui.select wire:model.live="tlsMode">
                                <option value="required">required (fails instead of using plaintext)</option>
                                <option value="default">left to the driver default</option>
                            </x-ui.select>
                        </x-ui.field>
                    @endif

                    @if ($driver === 'mysql' || $driver === 'mariadb')
                        <label class="inline-flex items-end gap-2 pb-1.5 text-[12.5px] text-ink-3">
                            <x-ui.checkbox wire:model.live="tlsVerifyServerCert" />
                            Verify the server certificate
                        </label>
                    @elseif ($driver === 'sqlsrv')
                        <label class="inline-flex items-end gap-2 pb-1.5 text-[12.5px] text-ink-3">
                            <x-ui.checkbox wire:model="tlsTrustServerCertificate" />
                            Trust the server certificate (TrustServerCertificate)
                        </label>
                    @else
                        <div></div>
                    @endif
                    <div></div>

                    @if ($tlsMode === 'default')
                        <x-ui.alert tone="pending" icon="alert" class="sm:col-span-3">
                            TLS is left to the driver default: whether this connection is encrypted depends on the installed driver{{ $driver === 'sqlsrv' ? ' (for SQL Server, the ODBC driver version)' : '' }}.
                        </x-ui.alert>
                    @elseif ($tlsMode === 'prefer' || $tlsMode === 'disable')
                        <x-ui.alert tone="pending" icon="alert" class="sm:col-span-3">
                            TLS is not enforced: if the server offers no TLS the connection continues in plaintext.
                        </x-ui.alert>
                    @elseif ($tlsMode === 'require' || ($tlsMode === 'required' && $driver !== 'sqlsrv' && ! $tlsVerifyServerCert))
                        <p class="text-[12.5px] text-mute-4 sm:col-span-3">
                            The connection is encrypted but the server is not verified, so it does not protect against a man-in-the-middle.
                        </p>
                    @endif

                    @if ($driver !== 'sqlsrv')
                        <p class="text-[12.5px] text-mute-4 sm:col-span-3">
                            Certificates are stored encrypted and are never shown again. {{ $editingId ? 'Leave a box blank to keep the stored value.' : '' }}
                        </p>

                        @foreach ([['tlsCa', 'ca', 'CA certificate (PEM)', 'tlsClearCa'], ['tlsCert', 'cert', 'Client certificate (PEM)', 'tlsClearCert'], ['tlsKey', 'key', 'Client private key (PEM)', 'tlsClearKey']] as [$model, $kind, $label, $clearModel])
                            <x-ui.field :label="$label" :hint="($storedPem[$kind] ?? false) ? 'stored' : 'optional'" :error="$errors->first($model)">
                                <x-ui.textarea wire:model="{{ $model }}" rows="4" class="font-mono" autocomplete="off" spellcheck="false" placeholder="-----BEGIN ...-----" />
                                @if ($storedPem[$kind] ?? false)
                                    <label class="mt-1.5 inline-flex items-center gap-2 text-[12px] text-ink-3">
                                        <x-ui.checkbox wire:model="{{ $clearModel }}" />
                                        Remove the stored one
                                    </label>
                                @endif
                            </x-ui.field>
                        @endforeach
                    @else
                        <p class="text-[12.5px] text-mute-4 sm:col-span-3">
                            SQL Server verifies the server certificate against the system trust store of the container; there is no certificate file to upload.
                        </p>
                    @endif

                    @if ($driver === 'sqlsrv')
                        <x-ui.alert tone="pending" icon="alert" class="sm:col-span-3">
                            SQL Server has no engine-level READ ONLY mode, and <code class="font-mono">ApplicationIntent=ReadOnly</code> does not protect against writes. Read requests are only as read-only as the database user they run as: set a read-only username and password above, using a login that has SELECT permission only.
                        </x-ui.alert>
                    @else
                        <p class="text-[12.5px] text-mute-4 sm:col-span-3">
                            Read requests run in a READ ONLY transaction. When a read-only user is set, they also log in as that user instead of the main one.
                        </p>
                    @endif
                @endif

                <div class="flex items-end gap-2 sm:col-span-3">
                    <x-ui.btn type="submit" variant="primary">{{ $editingId ? 'Save changes' : 'Create connection' }}</x-ui.btn>
                    <x-ui.btn type="button" wire:click="$set('showForm', false)">Cancel</x-ui.btn>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th class="w-48">Name</x-ui.th>
                    <x-ui.th class="w-24">Driver</x-ui.th>
                    <x-ui.th>Database</x-ui.th>
                    <x-ui.th class="w-44">TLS</x-ui.th>
                    <x-ui.th class="w-28">Developers</x-ui.th>
                    <x-ui.th class="w-52" align="right">Actions</x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($connections as $connection)
                <x-ui.tr :hover="false">
                    <x-ui.td mono class="text-ink-2">{{ $connection->name }}</x-ui.td>
                    <x-ui.td><x-ui.badge>{{ $connection->driver->label() }}</x-ui.badge></x-ui.td>
                    <x-ui.td mono muted truncate>{{ $connection->database }}</x-ui.td>
                    <x-ui.td>
                        @switch($connection->tlsState())
                            @case('enforced') <x-ui.badge tone="ok">TLS required</x-ui.badge> @break
                            @case('not_enforced') <x-ui.badge tone="pending">TLS not enforced</x-ui.badge> @break
                            @case('driver_default') <x-ui.badge tone="pending">TLS: driver default</x-ui.badge> @break
                            @default <span class="text-mute-4">—</span>
                        @endswitch
                    </x-ui.td>
                    <x-ui.td mono muted>{{ $connection->grantedUsers->count() }} granted</x-ui.td>
                    <x-ui.td align="right" nowrap>
                        <div class="flex items-center justify-end gap-2.5">
                            <x-ui.action wire:click="testConnection({{ $connection->id }})" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="testConnection({{ $connection->id }})">Test</span>
                                <span wire:loading wire:target="testConnection({{ $connection->id }})">Testing…</span>
                            </x-ui.action>
                            <x-ui.action wire:click="toggleGrants({{ $connection->id }})" tone="accent">Grants</x-ui.action>
                            <x-ui.action wire:click="openEdit({{ $connection->id }})" tone="accent">Edit</x-ui.action>
                            <x-ui.action wire:click="deleteConnection({{ $connection->id }})"
                                         wire:confirm="Delete connection {{ $connection->name }}?" tone="danger">Delete</x-ui.action>
                        </div>
                    </x-ui.td>
                </x-ui.tr>

                @if ($grantsForId === $connection->id)
                    <tr class="bg-canvas">
                        <td colspan="6" class="border-b border-line px-3.5 py-3.5">
                            <p class="eyebrow mb-2.5">Developer access to “{{ $connection->name }}”</p>

                            <div class="mb-3.5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <x-ui.field label="Access level" for="grant-level" :error="$errors->first('grantLevel')">
                                    <x-ui.select id="grant-level" wire:model.live="grantLevel">
                                        @foreach (\App\Enums\GrantAccessLevel::cases() as $level)
                                            <option value="{{ $level->value }}">{{ $level->label() }}{{ $level->allowsWrite() ? ' (writes and DDL, each request still approved)' : ' (SELECT only)' }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>

                                <x-ui.field label="For how long" for="grant-duration" :error="$errors->first('grantDuration')">
                                    <x-ui.select id="grant-duration" wire:model.live="grantDuration">
                                        <option value="permanent">Permanent</option>
                                        @foreach ($durationPresets as $minutes => $label)
                                            <option value="{{ $minutes }}">{{ $label }}</option>
                                        @endforeach
                                        <option value="custom">Custom…</option>
                                    </x-ui.select>
                                </x-ui.field>

                                @if ($grantDuration === 'custom')
                                    <div class="flex gap-2.5">
                                        <x-ui.field label="Amount" for="grant-custom-amount" class="w-24 shrink-0">
                                            <x-ui.input id="grant-custom-amount" type="number" min="1" step="1" inputmode="numeric" wire:model.live="grantCustomAmount" />
                                        </x-ui.field>
                                        <x-ui.field label="Unit" for="grant-custom-unit" class="flex-1">
                                            <x-ui.select id="grant-custom-unit" wire:model.live="grantCustomUnit">
                                                @foreach ($durationUnits as $unit)
                                                    <option value="{{ $unit }}">{{ ucfirst($unit) }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </x-ui.field>
                                    </div>
                                @endif

                                <x-ui.field label="Reason" hint="optional" for="grant-reason">
                                    <x-ui.input id="grant-reason" type="text" wire:model="grantReason" maxlength="1000" placeholder="e.g. incident 4711" />
                                </x-ui.field>
                            </div>

                            <p class="mb-2.5 text-[12px] text-mute-3">
                                The buttons below use these settings. Granting to a developer who already has access replaces that grant. Timed access ends on its own; times are shown as of the last refresh of this page.
                            </p>

                            @error('grant')
                                <x-ui.alert tone="danger" icon="alert" role="alert" class="mb-2.5">{{ $message }}</x-ui.alert>
                            @enderror

                            <ul class="flex flex-col">
                                @forelse ($developers as $developer)
                                    @php($grant = $connection->grantedUsers->firstWhere('id', $developer->id)?->pivot)

                                    <li wire:key="grant-{{ $connection->id }}-{{ $developer->id }}"
                                        class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line py-2 first:border-t-0">
                                        <div class="min-w-0 basis-52 flex-1">
                                            <span class="text-[12.5px] text-ink-3">{{ $developer->name }}</span>
                                            <span class="block truncate font-mono text-[11px] text-mute-4">{{ $developer->email }}</span>
                                        </div>

                                        <div class="flex min-w-[10rem] items-center gap-2">
                                            @if ($grant)
                                                <x-ui.badge :tone="$grant->access_level->value">{{ $grant->access_level->label() }}</x-ui.badge>
                                                @if ($grant->isPermanent())
                                                    <span class="font-mono text-[11px] text-mute-3">permanent</span>
                                                @else
                                                    <span class="font-mono text-[11px] text-mute-3"
                                                          title="until {{ $grant->expires_at->copy()->utc()->format('Y-m-d H:i') }} UTC">
                                                        {{ $grant->remaining() }} left
                                                    </span>
                                                @endif
                                            @else
                                                <span class="font-mono text-[11px] text-mute-4">no access</span>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-2.5">
                                            @if ($grant)
                                                <x-ui.btn size="sm" wire:click="startReplace({{ $connection->id }}, {{ $developer->id }})"
                                                          aria-label="Replace the grant of {{ $developer->name }}">Replace grant</x-ui.btn>
                                                <x-ui.action wire:click="revokeGrant({{ $connection->id }}, {{ $developer->id }})"
                                                             wire:confirm="Revoke {{ $developer->name }}'s access to {{ $connection->name }}?"
                                                             aria-label="Revoke access of {{ $developer->name }}" tone="danger">Revoke</x-ui.action>
                                            @else
                                                <x-ui.btn size="sm" wire:click="grantAccess({{ $connection->id }}, {{ $developer->id }})"
                                                          wire:loading.attr="disabled" wire:target="grantAccess({{ $connection->id }}, {{ $developer->id }})"
                                                          aria-label="Grant access to {{ $developer->name }}">Grant access</x-ui.btn>
                                            @endif
                                        </div>

                                        @if ($grant && $replacingKey === $connection->id.':'.$developer->id)
                                            <div class="flex basis-full flex-wrap items-center gap-x-3 gap-y-2" role="group" aria-label="Confirm replacing the grant of {{ $developer->name }}">
                                                <x-ui.alert tone="pending" icon="alert" class="min-w-0 flex-1">
                                                    Replace {{ $grant->describe() }} with {{ $replacementSummary }} for {{ $developer->name }}? The old grant is gone as soon as you confirm.
                                                </x-ui.alert>
                                                <x-ui.btn size="sm" variant="primary" wire:click="grantAccess({{ $connection->id }}, {{ $developer->id }})"
                                                          wire:loading.attr="disabled" wire:target="grantAccess({{ $connection->id }}, {{ $developer->id }})">Confirm replace</x-ui.btn>
                                                <x-ui.btn size="sm" wire:click="cancelReplace">Cancel</x-ui.btn>
                                            </div>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-[12.5px] text-mute-4">This team has no developers yet.</li>
                                @endforelse
                            </ul>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty :colspan="6">No connections yet.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>
</div>
