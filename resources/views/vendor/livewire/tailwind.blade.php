@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $page = 'inline-flex h-[26px] min-w-[26px] items-center justify-center rounded-control border px-2 font-mono text-[11.5px] transition-colors';
    $idle = $page.' border-line bg-panel text-ink-5 hover:border-line-strong hover:text-ink';
    $off = $page.' cursor-default border-line bg-panel text-hairline';
    $current = $page.' cursor-default border-accent bg-accent-bg text-accent';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-between gap-3">
            <p class="font-mono text-[11.5px] text-mute-3">
                {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
            </p>

            <div class="flex items-center gap-1.5">
                @if ($paginator->onFirstPage())
                    <span class="{{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                        <x-ui.icon name="chevron-right" :size="14" class="rotate-180" />
                    </span>
                @else
                    <button type="button" class="{{ $idle }}" aria-label="{{ __('pagination.previous') }}"
                            wire:click="previousPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">
                        <x-ui.icon name="chevron-right" :size="14" class="rotate-180" />
                    </button>
                @endif

                <div class="hidden items-center gap-1.5 sm:flex">
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="px-1 font-mono text-[11.5px] text-hairline" aria-disabled="true">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $pageNumber => $url)
                                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $pageNumber }}">
                                    @if ($pageNumber == $paginator->currentPage())
                                        <span class="{{ $current }}" aria-current="page">{{ $pageNumber }}</span>
                                    @else
                                        <button type="button" class="{{ $idle }}"
                                                aria-label="{{ __('Go to page :page', ['page' => $pageNumber]) }}"
                                                wire:click="gotoPage({{ $pageNumber }}, '{{ $paginator->getPageName() }}')"
                                                x-on:click="{{ $scrollIntoViewJsSnippet }}">{{ $pageNumber }}</button>
                                    @endif
                                </span>
                            @endforeach
                        @endif
                    @endforeach
                </div>

                @if ($paginator->hasMorePages())
                    <button type="button" class="{{ $idle }}" aria-label="{{ __('pagination.next') }}"
                            wire:click="nextPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">
                        <x-ui.icon name="chevron-right" :size="14" />
                    </button>
                @else
                    <span class="{{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                        <x-ui.icon name="chevron-right" :size="14" />
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>
