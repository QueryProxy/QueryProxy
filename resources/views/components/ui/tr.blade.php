@props(['selected' => false, 'hover' => true])

<tr {{ $attributes->class([
    'transition-colors',
    'hover:bg-raised' => $hover && ! $selected,
    'bg-raised' => $selected,
]) }}>{{ $slot }}</tr>
