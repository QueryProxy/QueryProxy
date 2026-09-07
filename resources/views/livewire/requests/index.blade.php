<div>
    <x-ui.page-header
        title="Query Requests"
        subtitle="Every statement anyone in this team has submitted, with its approval trail and execution outcome." />

    <div class="mb-3.5 flex flex-wrap items-center gap-1.5">
        <x-ui.chip wire:click="$set('status', '')" :active="$status === ''">All</x-ui.chip>
        @foreach (\App\Enums\QueryRequestStatus::cases() as $case)
            <x-ui.chip wire:click="$set('status', '{{ $case->value }}')" :active="$status === $case->value">{{ $case->label() }}</x-ui.chip>
        @endforeach
    </div>

    <x-ui.panel>
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th class="w-14">Req</x-ui.th>
                    <x-ui.th>Title / SQL</x-ui.th>
                    <x-ui.th class="w-40">Connection</x-ui.th>
                    <x-ui.th class="w-32">Requester</x-ui.th>
                    <x-ui.th class="w-16">Type</x-ui.th>
                    <x-ui.th class="w-28">Status</x-ui.th>
                    <x-ui.th class="w-24" align="right">Submitted</x-ui.th>
                </tr>
            </x-slot:head>

            @forelse ($requests as $request)
                <x-ui.tr>
                    <x-ui.td mono>
                        <a href="{{ route('requests.show', $request) }}" class="text-accent hover:brightness-125">#{{ $request->id }}</a>
                    </x-ui.td>
                    <x-ui.td truncate>
                        <a href="{{ route('requests.show', $request) }}" class="block truncate text-ink-2 hover:text-accent">
                            {{ $request->title ?? str($request->sql_original)->limit(60) }}
                        </a>
                    </x-ui.td>
                    <x-ui.td mono muted nowrap>{{ $request->connection->name }}</x-ui.td>
                    <x-ui.td muted nowrap>{{ $request->requester->name }}</x-ui.td>
                    <x-ui.td><x-ui.badge :tone="$request->type->tone()">{{ $request->type->label() }}</x-ui.badge></x-ui.td>
                    <x-ui.td><x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge></x-ui.td>
                    <x-ui.td mono muted nowrap align="right" title="{{ $request->created_at }}">{{ $request->created_at->diffForHumans() }}</x-ui.td>
                </x-ui.tr>
            @empty
                <x-ui.empty :colspan="7">No requests yet.</x-ui.empty>
            @endforelse
        </x-ui.table>

        @if ($requests->hasPages())
            <x-slot:footer>{{ $requests->links() }}</x-slot:footer>
        @endif
    </x-ui.panel>
</div>
