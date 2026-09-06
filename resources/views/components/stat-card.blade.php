@props(['label', 'value', 'color' => 'slate'])

@php
    $dot = [
        'amber' => 'bg-amber-400',
        'sky' => 'bg-sky-400',
        'emerald' => 'bg-emerald-400',
        'rose' => 'bg-rose-400',
        'slate' => 'bg-slate-400',
    ][$color] ?? 'bg-slate-400';
@endphp

<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-center gap-2 text-sm text-slate-500">
        <span class="h-2 w-2 rounded-full {{ $dot }}"></span>
        {{ $label }}
    </div>
    <div class="mt-2 text-3xl font-bold tracking-tight">{{ $value }}</div>
</div>
