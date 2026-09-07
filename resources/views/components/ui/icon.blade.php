@props(['name', 'size' => 16, 'stroke' => 1.6])

@php
    /** Stroke icons on a 24px grid, lifted from the Ops Console design canvas. */
    $paths = match ($name) {
        'dashboard' => '<rect x="3" y="3" width="7.5" height="9" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="5" rx="1.5"/><rect x="13.5" y="12" width="7.5" height="9" rx="1.5"/><rect x="3" y="16" width="7.5" height="5" rx="1.5"/>',
        'terminal' => '<rect x="2.5" y="4" width="19" height="16" rx="2"/><path d="M7 9.5l3 2.5-3 2.5"/><path d="M12.5 15h5"/>',
        'list' => '<path d="M8.5 6h11.5"/><path d="M8.5 12h11.5"/><path d="M8.5 18h11.5"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
        'shield' => '<path d="M12 3l7 2.8v5.4c0 4.1-2.9 7.4-7 8.8-4.1-1.4-7-4.7-7-8.8V5.8z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
        'database' => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3V6"/><path d="M4.5 12c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3"/>',
        'mask' => '<path d="M3 3l18 18"/><path d="M10.6 5.2A9.6 9.6 0 0112 5c5 0 9 4.5 9 7 0 .9-.5 2-1.4 3.1"/><path d="M6.3 7.3C3.9 8.8 3 10.6 3 12c0 2 4 7 9 7 1.5 0 2.9-.4 4.1-1.1"/><path d="M9.9 10.1a3 3 0 004.2 4.2"/>',
        'chat' => '<path d="M4 5.5h16v11h-8.2L7 20.5V16.5H4z"/><path d="M8 11h8"/>',
        'audit' => '<path d="M14 3v5h5"/><path d="M19 12.5V8l-5-5H6v18h5.5"/><circle cx="17" cy="17" r="4"/><path d="M17 15.3V17l1.3 1"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3 2.9-4.6 5.5-4.6s4.9 1.6 5.5 4.6"/><path d="M16 5.6a3 3 0 010 5.4"/><path d="M17.6 14.7c2 .5 3.3 2 3.8 4.3"/>',
        'user' => '<circle cx="12" cy="8" r="3.4"/><path d="M5 19.5c.7-3.4 3.5-5.2 7-5.2s6.3 1.8 7 5.2"/>',
        'bell' => '<path d="M18 9a6 6 0 10-12 0c0 5-2 6-2 6h16s-2-1-2-6"/><path d="M10.3 19.5a2 2 0 003.4 0"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M15.8 15.8L20 20"/>',
        'chevron-down' => '<path d="M6 9.5l6 5.5 6-5.5"/>',
        'chevron-right' => '<path d="M9.5 6l6 6-6 6"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7"/>',
        'x' => '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.2v5.1l3.2 2"/>',
        'plus' => '<path d="M12 5.5v13M5.5 12h13"/>',
        'play' => '<path d="M7.5 4.8l11.5 7.2-11.5 7.2z"/>',
        'download' => '<path d="M12 4v11"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M4.5 19.5h15"/>',
        'zap' => '<path d="M13.5 3L5.5 13.5h5.2L10 21l8.5-10.5h-5.4z"/>',
        'filter' => '<path d="M4 5.5h16l-6.2 7.2v6.3l-3.6 1.7v-8z"/>',
        'refresh' => '<path d="M20 12a8 8 0 10-2.4 5.7"/><path d="M20 6.5V12h-5.4"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="9.5" rx="2"/><path d="M8 10.5V7.6a4 4 0 018 0v2.9"/><path d="M12 14.3v2"/>',
        'key' => '<circle cx="8" cy="12" r="4"/><path d="M12 12h9"/><path d="M17.5 12v3.5"/><path d="M20.5 12v2.5"/>',
        'dots' => '<circle cx="12" cy="5.5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="18.5" r="1.2"/>',
        'arrow-right' => '<path d="M5 12h13"/><path d="M13 7l5 5-5 5"/>',
        'arrow-left' => '<path d="M19 12H6"/><path d="M11 7l-5 5 5 5"/>',
        'logout' => '<path d="M15 8.5V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2h7a2 2 0 002-2v-2.5"/><path d="M10.5 12H21"/><path d="M17.5 8.5L21 12l-3.5 3.5"/>',
        'trash' => '<path d="M4.5 7h15"/><path d="M9.5 7V5.5a1.5 1.5 0 011.5-1.5h2a1.5 1.5 0 011.5 1.5V7"/><path d="M6.5 7l.8 12.1a1.5 1.5 0 001.5 1.4h6.4a1.5 1.5 0 001.5-1.4L17.5 7"/>',
        'edit' => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 00-3-3L5 17v3z"/><path d="M14.5 6.5l3 3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.2 5.2l1.4 1.4M17.4 17.4l1.4 1.4M18.8 5.2l-1.4 1.4M6.6 17.4l-1.4 1.4"/>',
        'moon' => '<path d="M20 13.5A8.5 8.5 0 1110.5 4a6.8 6.8 0 009.5 9.5z"/>',
        'slack' => '<rect x="3.5" y="10" width="6.5" height="3.5" rx="1.75"/><rect x="10.5" y="3.5" width="3.5" height="6.5" rx="1.75"/><rect x="14" y="10.5" width="6.5" height="3.5" rx="1.75"/><rect x="10" y="14" width="3.5" height="6.5" rx="1.75"/>',
        default => '',
    };
@endphp

<svg {{ $attributes->class(['shrink-0']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">{!! $paths !!}</svg>
