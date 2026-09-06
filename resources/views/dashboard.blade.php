<x-layouts::app title="Dashboard">
    @php
        $user = auth()->user();
        $team = $user->currentTeam();
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">
            @if($team)
                Team: <span class="font-medium text-slate-700">{{ $team->name }}</span>
            @else
                You are not a member of any team yet. Ask an administrator to add you.
            @endif
        </p>
    </div>

    @if($team && class_exists(\App\Models\QueryRequest::class))
        @php
            $counts = \App\Models\QueryRequest::query()
                ->where('team_id', $team->id)
                ->selectRaw("status, count(*) as c")
                ->groupBy('status')
                ->pluck('c', 'status');
        @endphp
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-stat-card label="Pending" :value="$counts['pending'] ?? 0" color="amber" />
            <x-stat-card label="Running" :value="($counts['queued'] ?? 0) + ($counts['running'] ?? 0)" color="sky" />
            <x-stat-card label="Completed" :value="$counts['completed'] ?? 0" color="emerald" />
            <x-stat-card label="Failed / Rejected" :value="($counts['failed'] ?? 0) + ($counts['rejected'] ?? 0)" color="rose" />
        </div>
    @endif
</x-layouts::app>
