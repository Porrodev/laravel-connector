@extends('porro-laravel::page.layout', ['title' => __('Set up nginx')])

@section('content')
    <div class="head">
        <div>
            <p class="small" style="margin: 0"><a href="{{ route('porro.blocking') }}">← {{ __('Blocking') }}</a></p>
            <h1>{{ __('Set up nginx') }}</h1>
            <p class="sub">{{ __('nginx turns blocked IPs away before PHP runs. Porro writes a deny file on each server; nginx includes it and reloads when it changes. Two one-time steps, on every server this site runs on.') }}</p>
        </div>
    </div>

    @unless ($enforcement['nginx'])
        <div class="flash" style="border-color: var(--control)">
            {{ __('nginx mode is off. Add this to the site’s environment first, so Porro starts writing the file every minute:') }}
            <pre>PORRO_BLOCK_NGINX=true</pre>
        </div>
    @endunless

    @unless ($enforcement['production'])
        <div class="flash" style="border-color: var(--control)">{{ __('This copy isn’t production, so it doesn’t write the file. Set these steps up on the production servers.') }}</div>
    @endunless

    <section class="panel">
        <div class="panel-head"><span>{{ __('Step 1 · include the file') }}</span></div>
        <div class="note">
            <p style="margin-top: 0">{{ __('In the site’s nginx config, inside server { }. On Forge: Site → Edit Nginx Configuration.') }}</p>
            @if ($behindCloudflare)
                <p class="small">{{ __('This site is behind Cloudflare, so the real IP lines come first: without them nginx sees Cloudflare’s address and would deny Cloudflare, not the visitor.') }}</p>
                <pre>{{ $enforcement['realIp'] }}{{ $enforcement['include'] }}</pre>
            @else
                <pre>{{ $enforcement['include'] }}</pre>
                <p class="small muted">{{ __('Behind Cloudflare or another proxy? Its real IP lines have to come before the include, or nginx would deny the proxy instead of the visitor.') }}</p>
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('Step 2 · reload nginx when the list changes') }}</span></div>
        <div class="note">
            <p style="margin-top: 0">{{ __('A scheduled job as root, every minute. On Forge: Server → Scheduler → New job, user root. It reloads only when the file has changed and nginx -t passes, so a bad config never takes the site down.') }}</p>
            <pre>{{ $enforcement['reload'] }}</pre>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('Servers') }}</span><span>{{ __('checked every minute') }}</span></div>
        @forelse ($enforcement['servers'] as $server)
            <div class="row">
                <span class="mono grow">{{ $server['name'] }}</span>
                @if ($server['state'] === 'loaded' && $behindCloudflare && $server['realIp'] === false)
                    <span class="small warning">{{ __('Real IP lines missing') }}</span>
                @endif
                <span class="small muted">
                    @if ($server['reloaded']) {{ __('reloaded :ago', ['ago' => $server['reloaded']]) }} · @endif
                    {{ __('checked :ago', ['ago' => $server['checked']]) }}
                </span>
                <span class="chip {{ $server['ok'] ? 'on' : ($server['state'] === 'not_included' ? 'off' : '') }}">{{ $server['label'] }}</span>
            </div>
        @empty
            <p class="note">{{ __('No server has reported yet. Once PORRO_BLOCK_NGINX is on, each server checks itself every minute and shows up here.') }}</p>
        @endforelse
        <div class="note small muted" style="padding-top: 10px; border-top: 1px solid var(--hairline)">
            {{ __('Each server checks by looking, never by running anything: the file exists, a readable nginx config includes it, and the reload job has run since the last change.') }}
            @unless ($sharedCache)
                {{ __('This site’s cache isn’t shared between servers, so only this server shows here. Use Redis or the database cache to see them all.') }}
            @endunless
        </div>
    </section>
@endsection
