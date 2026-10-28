@extends('porro-laravel::page.layout', ['title' => __('Set up Cloudflare')])

@php
    $done = fn (bool $ok) => $ok ? '<span class="chip on">'.e(__('Done')).'</span>' : '<span class="chip">'.e(__('To do')).'</span>';
@endphp

@section('content')
    <div class="head">
        <div>
            <p class="small" style="margin: 0"><a href="{{ route('porro.blocking') }}">← {{ __('Blocking') }}</a></p>
            <h1>{{ __('Set up Cloudflare') }}</h1>
            <p class="sub">{{ __('For sites behind Cloudflare: blocked IPs are stopped at Cloudflare’s edge, before they reach your server. Three steps, once for the site.') }}</p>
        </div>
    </div>

    @if ($cloudflare['configured'] && ! $cloudflare['production'])
        <div class="flash" style="border-color: var(--control)">{{ __('This copy isn’t production, so it won’t sync the blocklist. You can still connect here to check the token.') }}</div>
    @endif

    <section class="panel">
        <div class="panel-head"><span>{{ __('1 · Create a Cloudflare API token') }}</span>{!! $done($steps['token']) !!}</div>
        <div class="note">
            <p style="margin-top: 0">{{ __('In Cloudflare, open') }} <a href="{{ $tokensUrl }}" target="_blank" rel="noopener">{{ __('My Profile → API Tokens') }}</a>{{ __(', then Create Token → Create Custom Token. It needs two permission policies: one for the site’s domain, one for your account.') }}</p>
            @foreach ($policies as $i => $policy)
                <p class="small" style="margin: 12px 0 4px"><strong>{{ __('Policy :n', ['n' => $i + 1]) }}</strong> · {{ __('scope it to') }} {{ $i === 0 ? __('Specified Domains → :host', ['host' => $host ?: __('this site’s domain')]) : __($policy['scope']) }}@if ($i > 0) {{ __('(Add policy, then pick Entire Account)') }}@endif</p>
                <table class="table">
                    <thead><tr><th>{{ __('Search for') }}</th><th>{{ __('Tick') }}</th><th>{{ __('Access') }}</th></tr></thead>
                    <tbody>
                        @foreach ($policy['permissions'] as $p)
                            <tr><td class="mono">{{ $p['search'] }}</td><td>{{ $p['tick'] }}@if ($p['group'])<span class="small muted"> · {{ __('under :group', ['group' => $p['group']]) }}</span>@endif</td><td>{{ $p['access'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
            <p class="small muted" style="margin: 10px 0 0">{{ __('Create the token and copy it: Cloudflare only shows it once.') }}</p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('2 · Add it to the site’s environment') }}</span>{!! $done($steps['token']) !!}</div>
        <div class="note">
            <p style="margin-top: 0">{{ __('Paste the token after the = in .env, or on Forge, the site’s Environment tab. It stays in the environment, never the database.') }}</p>
            <div class="copyable"><pre>{{ $envLine }}your-token-here</pre><button class="btn" type="button" data-porro-copy="{{ $envLine }}">{{ __('Copy') }}</button></div>
            @if ($steps['token'])
                <p class="small" style="margin-bottom: 0">{{ __('Token found.') }}</p>
            @else
                <p class="small muted" style="margin-bottom: 0">{{ __('This page ticks this off once the site can see the token. If you cache config, run php artisan config:cache after saving it.') }}</p>
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('3 · Connect') }}</span>{!! $done($steps['connected']) !!}</div>
        <div class="note">
            @if ($cloudflare['connected'])
                <p style="margin-top: 0">{{ __('Connected to :zone. Blocks go to the porro_blocklist list, with one rule blocking it next to your existing rules.', ['zone' => $cloudflare['zone']]) }}</p>
                <p class="small {{ $cloudflare['error'] ? 'critical' : 'muted' }}" style="margin-bottom: 0">
                    @if ($cloudflare['error']) {{ $cloudflare['error'] }}
                    @elseif ($cloudflare['synced']) {{ __('Last synced :ago. It syncs after every change and on the scheduled task.', ['ago' => $cloudflare['synced']]) }}
                    @else {{ __('Waiting for the first sync.') }}
                    @endif
                </p>
            @else
                <p style="margin-top: 0">{{ __('Finds the zone for :host, creates the porro_blocklist list (shared by your sites on the account) and adds one rule blocking it, next to your existing rules, never replacing them.', ['host' => $host ?: __('this site')]) }}</p>
                @if ($canManage)
                    <form method="POST" action="{{ route('porro.cloudflare.connect') }}" class="inline">
                        @csrf
                        <button class="btn primary" type="submit" @disabled(! $steps['token'])>{{ __('Connect to Cloudflare') }}</button>
                    </form>
                @endif
                <p class="small muted" style="margin-bottom: 0">{{ __('Or on the server:') }} <code>{{ $command }}</code></p>
            @endif
            @if ($cloudflare['error'] && ! $cloudflare['connected'])
                <p class="small critical" style="margin-bottom: 0">{{ $cloudflare['error'] }}</p>
            @endif
        </div>
    </section>
@endsection
