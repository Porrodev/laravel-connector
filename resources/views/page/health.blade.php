@extends('porro-laravel::page.layout', ['title' => __('Site health')])

@section('content')
    @php($attention = $counts['critical'] + $counts['warning'])
    <div class="head">
        <div>
            <h1>{{ __('Site health') }}</h1>
            <p class="sub">
                @if ($attention > 0)
                    {{ trans_choice(':count thing needs attention|:count things need attention', $attention, ['count' => $attention]) }}
                @else
                    {{ __('All clear') }}
                @endif
                @if ($checkedAgo) · {{ __('checked :ago', ['ago' => $checkedAgo]) }} @endif
            </p>
        </div>
        <form method="POST" action="{{ route('porro.refresh') }}" class="inline">
            @csrf
            <button class="btn" type="submit">{{ __('Check again') }}</button>
        </form>
    </div>

    @if ($failed)
        <div class="panel"><p class="note" style="padding-top: 14px">{{ __('The checks couldn’t run just now. Try again in a minute; the error is in the Laravel log.') }}</p></div>
    @endif

    @if ($connected)
        <section class="panel">
            <div class="panel-head"><span>{{ __('From Porro') }}</span>@if ($porroUrl)<a href="{{ $porroUrl }}" target="_blank" rel="noopener">{{ __('Open in Porro') }} ↗</a>@endif</div>
            @if (! $porroOk)
                <p class="note">{{ $porroError ?? __('Couldn’t reach Porro just now.') }}</p>
            @elseif (empty($found))
                <p class="note">{{ __('Porro has nothing open for this site.') }}</p>
            @else
                @include('porro-laravel::page.issues', ['issues' => $found])
            @endif
        </section>
    @endif

    <section class="panel">
        <div class="panel-head"><span>{{ __('Checked on this site') }}</span><span>{{ count($local) }} {{ __('open') }}</span></div>
        @if (empty($local))
            <p class="note">{{ __('Nothing needs attention.') }}</p>
        @else
            @include('porro-laravel::page.issues', ['issues' => $local])
        @endif
        <div class="note small muted" style="padding-top: 10px; border-top: 1px solid var(--hairline)">
            @if (! empty($checked))<p style="margin: 0">{{ __('Checked') }}: {{ implode(' · ', $checked) }}.</p>@endif
            @foreach ($unavailable as $note)<p style="margin: 0">{{ $note }}.</p>@endforeach
        </div>
    </section>

    @if ($activity && ! empty($activity['flagged']))
        <p class="small"><a href="{{ route('porro.activity') }}">{{ trans_choice(':count IP looks suspicious|:count IPs look suspicious', count($activity['flagged']), ['count' => count($activity['flagged'])]) }} →</a></p>
    @endif

    @unless ($connected)
        <section class="panel">
            <div class="panel-head"><span>{{ __('Watch this site from outside too') }}</span></div>
            <div class="note">
                <p style="margin-top: 0">{{ __('These checks run inside the site. Porro also watches uptime, SSL, the domain and the server from outside, alongside every other site you look after. Add the site in Porro, then connect it:') }}</p>
                <pre>{{ $commands['connect'] ?? 'php artisan porro:connect <site key>' }}</pre>
            </div>
        </section>
    @endunless
@endsection
