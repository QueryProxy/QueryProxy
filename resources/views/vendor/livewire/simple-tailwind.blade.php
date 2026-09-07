@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $page = 'inline-flex h-[26px] items-center gap-1.5 rounded-control border px-2.5 font-mono text-[11.5px] transition-colors';
    $idle = $page.' border-line bg-panel text-ink-5 hover:border-line-strong hover:text-ink';
    $off = $page.' cursor-default border-line bg-panel text-hairline';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex items-center justify-between gap-3">
            @if ($paginator->onFirstPage())
                <span class="{{ $off }}" aria-disabled="true">{!! __('pagination.previous') !!}</span>
            @else
                <button type="button" class="{{ $idle }}" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">{!! __('pagination.previous') !!}</button>
            @endif

            @if ($paginator->hasMorePages())
                <button type="button" class="{{ $idle }}" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">{!! __('pagination.next') !!}</button>
            @else
                <span class="{{ $off }}" aria-disabled="true">{!! __('pagination.next') !!}</span>
            @endif
        </nav>
    @endif
</div>
