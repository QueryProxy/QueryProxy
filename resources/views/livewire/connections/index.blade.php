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
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                @forelse ($developers as $developer)
                                    <label class="inline-flex items-center gap-2 text-[12.5px] text-ink-3">
                                        <x-ui.checkbox wire:change="toggleGrant({{ $connection->id }}, {{ $developer->id }})"
                                                       @checked($connection->grantedUsers->contains($developer)) />
                                        {{ $developer->name }}
                                        <span class="font-mono text-[11px] text-mute-4">{{ $developer->email }}</span>
                                    </label>
                                @empty
                                    <p class="text-[12.5px] text-mute-4">This team has no developers yet.</p>
                                @endforelse
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty :colspan="6">No connections yet.</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.panel>
</div>
