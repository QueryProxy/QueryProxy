<div class="overflow-x-auto">
    <table {{ $attributes->class(['w-full border-collapse text-[12.5px]']) }}>
        @isset($head)
            <thead>{{ $head }}</thead>
        @endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
