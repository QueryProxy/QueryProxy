<div x-data="{ open: false }" class="relative">
    <button @click="open = !open; if (open) $wire.markAllRead()" @click.outside="open = false"
            class="relative flex h-8 w-8 items-center justify-center rounded-full text-slate-300 hover:bg-slate-800 hover:text-white">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
        </svg>
        @if($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" x-transition.opacity x-cloak
         class="absolute right-0 z-20 mt-1 w-80 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg">
        <div class="border-b border-slate-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Notifications</div>
        @forelse($notifications as $notification)
            <a href="{{ isset($notification->data['query_request_id']) ? route('requests.show', $notification->data['query_request_id']) : '#' }}"
               class="block border-b border-slate-50 px-3 py-2 hover:bg-slate-50 {{ $notification->read_at ? 'text-slate-500' : 'font-medium text-slate-800' }}">
                {{ $notification->data['message'] ?? '' }}
                <span class="mt-0.5 block text-xs font-normal text-slate-400">{{ $notification->created_at->diffForHumans() }}</span>
            </a>
        @empty
            <div class="px-3 py-6 text-center text-slate-400">No notifications yet.</div>
        @endforelse
    </div>
</div>
