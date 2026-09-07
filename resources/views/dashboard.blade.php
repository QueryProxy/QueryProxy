<x-layouts::app title="Dashboard">
    @php
        $user = auth()->user();
        $team = $user->currentTeam();
    @endphp

    <x-ui.page-header title="Dashboard">
        <p class="mt-1.5 text-[12.5px] leading-[1.55] text-mute">
            @if ($team)
                Queue health for <span class="font-medium text-ink-3">{{ $team->name }}</span>. Every number below is scoped to this team.
            @else
                You are not a member of any team yet. Ask an administrator to add you.
            @endif
        </p>
    </x-ui.page-header>

    @if ($team && class_exists(\App\Models\QueryRequest::class))
        @php
            $counts = \App\Models\QueryRequest::query()
                ->where('team_id', $team->id)
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status');
        @endphp

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-ui.stat label="Pending approval" tone="pending" :value="$counts['pending'] ?? 0" />
            <x-ui.stat label="Queued / running" tone="running" :value="($counts['queued'] ?? 0) + ($counts['running'] ?? 0)" />
            <x-ui.stat label="Completed" tone="ok" :value="$counts['completed'] ?? 0" />
            <x-ui.stat label="Failed / rejected" tone="danger" :value="($counts['failed'] ?? 0) + ($counts['rejected'] ?? 0)" />
        </div>
    @endif
</x-layouts::app>
