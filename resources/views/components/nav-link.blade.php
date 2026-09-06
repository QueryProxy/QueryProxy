@props(['active' => false, 'href'])

<a href="{{ $href }}"
   {{ $attributes->merge(['class' => 'rounded-md px-3 py-1.5 transition '.($active ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white')]) }}>
    {{ $slot }}
</a>
