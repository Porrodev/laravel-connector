@extends('porro-laravel::page.layout', ['title' => __('Set up nginx')])

@php
    $servers = $progress['servers'];
    $done = fn (bool $ok) => $ok ? '<span class="chip on">'.e(__('Done')).'</span>' : '<span class="chip">'.e(__('To do')).'</span>';
    $onServers = fn (int $n) => $servers === 0
        ? '<span class="chip">'.e(__('No server has checked in yet')).'</span>'
        : '<span class="chip '.($n === $servers ? 'on' : '').'">'.e(trans_choice('Done on :n of :count server|Done on :n of :count servers', $servers, ['n' => $n, 'count' => $servers])).'</span>';
@endphp

@section('content')
    <div class="head">
        <div>
            <p class="small" style="margin: 0"><a href="{{ route('porro.blocking') }}">← {{ __('Blocking') }}</a></p>
            <h1>{{ __('Set up nginx') }}</h1>
            <p class="sub">{{ __('nginx turns blocked IPs away before PHP runs. Porro keeps a deny file up to date; nginx reads it. Three one-time steps: the first once for the site, the other two on every server it runs on.') }}</p>
        </div>
    </div>

    @unless ($enforcement['production'])
        <div class="flash" style="border-color: var(--control)">{{ __('This copy isn’t production, so it doesn’t write the file. Do these steps for the production site and its servers.') }}</div>
    @endunless

    <section class="panel">
        <div class="panel-head"><span>{{ __('1 · Turn on nginx mode') }}</span>{!! $done($enforcement['nginx']) !!}</div>
        <div class="note">
            <p style="margin-top: 0">{{ __('Add this to the site’s environment: .env, or on Forge, the site’s Environment tab. Porro then writes the deny file every minute.') }}</p>
            <div class="copyable"><pre>PORRO_BLOCK_NGINX=true</pre><button class="btn" type="button" data-porro-copy="PORRO_BLOCK_NGINX=true">{{ __('Copy') }}</button></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('2 · Add one line to the site’s nginx config') }}</span>{!! $onServers($progress['included']) !!}</div>
        <div class="note">
            <p style="margin-top: 0">{{ __('Inside server { }, above the first location. On Forge: open the site, then Edit Nginx Configuration from the Files menu, and save.') }}</p>
            <div class="copyable"><pre>{{ $enforcement['include'] }}</pre><button class="btn" type="button" data-porro-copy="{{ $enforcement['include'] }}">{{ __('Copy') }}</button></div>
            <p class="small muted" style="margin-bottom: 4px">{{ __('Where it goes, in a trimmed copy of this site’s config:') }}</p>
            <pre class="example">@foreach ($example as $row)<span @class(['add' => $row['add']])>{{ $row['line'] }}@if ($row['add'])  <em>← {{ __('add this line') }}</em>@endif</span>@endforeach</pre>
            @if ($realIpInFile)
                <p class="small">{{ __('Behind Cloudflare: the file also carries Cloudflare’s real IP lines, so nginx turns away the visitor and not Cloudflare. Nothing else to add. If this config already sets real_ip_header, set PORRO_NGINX_REAL_IP=false.') }}</p>
            @elseif ($behindCloudflare)
                <p class="small">{{ __('This site is behind Cloudflare but PORRO_NGINX_REAL_IP is off, so the config needs Cloudflare’s real IP lines too. Without them nginx sees Cloudflare’s address and would deny Cloudflare, not the visitor:') }}</p>
                <div class="copyable"><pre>{{ $enforcement['realIp'] }}</pre><button class="btn" type="button" data-porro-copy="{{ $enforcement['realIp'] }}">{{ __('Copy') }}</button></div>
            @else
                <p class="small muted">{{ __('Behind Cloudflare? Porro adds Cloudflare’s real IP lines to the file for you once you open this page through it, or once a Cloudflare token is set.') }}</p>
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('3 · Reload nginx when the list changes') }}</span>{!! $onServers($progress['loaded']) !!}</div>
        <div class="note">
            <p style="margin-top: 0">{{ __('A scheduled job that runs every minute as root. On Forge: Server → Scheduler → New job, user root, frequency every minute.') }}</p>
            <div class="copyable"><pre>{{ $enforcement['reload'] }}</pre><button class="btn" type="button" data-porro-copy="{{ $enforcement['reload'] }}">{{ __('Copy') }}</button></div>
            <p class="small muted" style="margin-bottom: 0">{{ __('It reloads only when the file has changed and nginx -t passes, so a bad config never takes the site down.') }}</p>
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
            <p class="note">{{ __('No server has checked in yet. Once step 1 is done, each server checks itself every minute and shows up here.') }}</p>
        @endforelse
        <div class="note small muted" style="padding-top: 10px; border-top: 1px solid var(--hairline)">
            {{ __('Each server checks by looking, never by running anything: the file exists, a readable nginx config includes it, and the reload job has run since the last change.') }}
            @unless ($sharedCache)
                {{ __('This site’s cache isn’t shared between servers, so only this server shows here. Use Redis or the database cache to see them all.') }}
            @endunless
        </div>
    </section>
@endsection
