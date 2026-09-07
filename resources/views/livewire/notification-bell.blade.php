<x-ui.dropdown width="w-80">
    <x-slot:trigger>
        <button type="button" @click="$wire.markAllRead()"
                class="relative flex size-7 items-center justify-center rounded-control text-ink-5 transition-colors hover:bg-raised hover:text-ink"
                aria-label="Notifications">
            <x-ui.icon name="bell" />
            @if ($unreadCount > 0)
                <span class="absolute -top-0.5 -right-0.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-pending px-1 font-mono text-[9px] font-semibold text-canvas">
                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                </span>
            @endif
        </button>
    </x-slot:trigger>

    <div class="eyebrow border-b border-line px-3 py-2">Notifications</div>

    @forelse ($notifications as $notification)
        <a href="{{ isset($notification->data['query_request_id']) ? route('requests.show', $notification->data['query_request_id']) : '#' }}"
           class="block border-b border-line px-3 py-2 transition-colors last:border-b-0 hover:bg-raised {{ $notification->read_at ? 'text-mute' : 'font-medium text-ink-2' }}">
            {{ $notification->data['message'] ?? '' }}
            <span class="mt-0.5 block font-mono text-[10.5px] font-normal text-mute-4">{{ $notification->created_at->diffForHumans() }}</span>
        </a>
    @empty
        <x-ui.empty>No notifications yet.</x-ui.empty>
    @endforelse
</x-ui.dropdown>
