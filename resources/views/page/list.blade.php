@extends('porro-laravel::page.layout', ['title' => $list === 'safelist' ? __('Safelist') : __('Blocklist')])

@php($query = fn (array $change) => route('porro.list', ['list' => $list, ...array_filter(['q' => $search, 'filter' => $filter === 'all' ? null : $filter, 'page' => null, ...$change])]))

@section('content')
    <div class="head">
        <div>
            <p class="small" style="margin: 0"><a href="{{ route('porro.blocking') }}">← {{ __('Blocking') }}</a></p>
            <h1>{{ $list === 'safelist' ? __('Safelist') : __('Blocklist') }}</h1>
            <p class="sub">{{ trans_choice(':count entry|:count entries', $total, ['count' => number_format($total)]) }}@if ($list === 'safelist') · {{ __('never blocked or flagged') }}@endif</p>
        </div>
    </div>

    @if ($failed)
        <div class="flash error">{{ __('The blocklist tables aren’t there yet. Run php artisan migrate on this site.') }}</div>
    @endif

    <section class="panel">
        <form method="GET" action="{{ route('porro.list', $list) }}" class="form" style="padding-top: 14px">
            @if ($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('Search: an IP, a range, a reason or who added it') }}" aria-label="{{ __('Search') }}" style="flex: 1 1 18rem">
            <button class="btn" type="submit">{{ __('Search') }}</button>
            @if ($search !== '')<a class="btn ghost" href="{{ $query(['q' => null]) }}">{{ __('Clear') }}</a>@endif
        </form>
        <div class="note filters" style="display: flex; flex-wrap: wrap; gap: 6px">
            @foreach ($filters as $f)
                @if ($f['count'] > 0 || $f['key'] === 'all' || $f['key'] === $filter)
                    <a href="{{ $query(['filter' => $f['key'] === 'all' ? null : $f['key']]) }}" class="chip" @if ($f['key'] === $filter) aria-current="true" @endif>{{ $f['label'] }} {{ number_format($f['count']) }}</a>
                @endif
            @endforeach
        </div>
        @include('porro-laravel::page.entries', [
            'entries' => $entries,
            'remove' => route($list === 'safelist' ? 'porro.unsafelist' : 'porro.unblock'),
            'empty' => $search !== '' || $filter !== 'all' ? __('Nothing matches.') : ($list === 'safelist' ? __('Nothing is safelisted.') : __('Nothing is blocked.')),
        ])
        @if ($pages > 1)
            <div class="row" style="justify-content: space-between">
                @if ($page > 1)<a class="btn" href="{{ $query(['page' => $page - 1]) }}">← {{ __('Newer') }}</a>@else<span></span>@endif
                <span class="small muted">{{ __('Page :page of :pages · :count shown', ['page' => $page, 'pages' => $pages, 'count' => number_format($shown)]) }}</span>
                @if ($page < $pages)<a class="btn" href="{{ $query(['page' => $page + 1]) }}">{{ __('Older') }} →</a>@else<span></span>@endif
            </div>
        @endif
    </section>
@endsection
