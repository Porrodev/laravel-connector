@extends('porro-laravel::page.layout', ['title' => __('Blocking')])

@section('content')
    <div class="head">
        <div>
            <h1>{{ __('Blocking') }}</h1>
            <p class="sub">{{ trans_choice(':count blocked|:count blocked', count($blocks), ['count' => count($blocks)]) }} · {{ trans_choice(':count safelisted|:count safelisted', count($safe), ['count' => count($safe)]) }}</p>
        </div>
    </div>

    @if ($failed)
        <div class="panel"><p class="note" style="padding-top: 14px">{{ __('The blocklist tables aren’t there yet. Run php artisan migrate on this site.') }}</p></div>
    @endif

    <section class="panel">
        <div class="panel-head"><span>{{ __('Where blocks are enforced') }}</span></div>
        <div class="row">
            <div class="grow">
                <span class="title">{{ __('In the app') }}</span>
                <span class="chip {{ $enforcement['app'] ? 'on' : '' }}">{{ $enforcement['app'] ? __('active') : __('off') }}</span>
                <div class="small">{{ __('Blocked IPs get a 403 from middleware before routing. Turn off with PORRO_BLOCK_APP=false.') }}</div>
            </div>
        </div>
        <div class="row">
            <div class="grow">
                <span class="title">Cloudflare WAF</span>
                <span class="chip {{ $cloudflare['connected'] && ! $cloudflare['error'] && $cloudflare['synced'] ? 'on' : ($cloudflare['error'] ? 'off' : '') }}">
                    @if (! $cloudflare['configured']) {{ __('not set up') }}
                    @elseif (! $cloudflare['connected']) {{ __('token set, not connected') }}
                    @elseif ($cloudflare['error']) {{ __('sync failed') }}
                    @elseif (! $cloudflare['production']) {{ __('production only') }}
                    @elseif ($cloudflare['synced']) {{ __('synced :ago', ['ago' => $cloudflare['synced']]) }}
                    @else {{ __('waiting for the first sync') }}
                    @endif
                </span>
                @if ($cloudflare['connected'])
                    <div class="small">{{ __('Blocked at the edge, before traffic reaches the server · zone :zone · list porro_blocklist', ['zone' => $cloudflare['zone']]) }}@if ($cloudflare['result'] && $cloudflare['result']['shared']) · {{ trans_choice(':count already blocked by another site|:count already blocked by other sites', $cloudflare['result']['shared'], ['count' => $cloudflare['result']['shared']]) }}@endif</div>
                    @if ($cloudflare['error'])<div class="small critical">{{ $cloudflare['error'] }}</div>@endif
                @elseif ($cloudflare['configured'])
                    <div class="small">{{ __('Run this on the server to find the zone, create the porro_blocklist list and add the rule next to your existing ones:') }}</div>
                    <pre>php artisan porro:cloudflare connect</pre>
                    @if ($cloudflare['error'])<div class="small critical">{{ $cloudflare['error'] }}</div>@endif
                @else
                    <div class="small">{{ __('Blocks at Cloudflare’s edge for sites behind it. Create a token with Account Filter Lists Edit, Zone WAF Edit and Zone Read, then:') }}</div>
                    <pre>PORRO_CLOUDFLARE_TOKEN=your-token-here
php artisan porro:cloudflare connect</pre>
                @endif
            </div>
        </div>
        <div class="row">
            <div class="grow">
                <span class="title">nginx</span>
                <span class="chip {{ $enforcement['nginx'] && $enforcement['servers'] && collect($enforcement['servers'])->every('ok') ? 'on' : '' }}">
                    @if (! $enforcement['nginx']) {{ __('not set up') }}
                    @elseif (! $enforcement['production']) {{ __('production only') }}
                    @elseif ($enforcement['nginxWritten']) {{ __('written :ago', ['ago' => $enforcement['nginxWritten']]) }}
                    @else {{ __('waiting for the first sync') }}
                    @endif
                </span>
                @php($loaded = collect($enforcement['servers'])->where('ok', true)->count())
                @if ($enforcement['servers'])
                    <div class="small">{{ trans_choice(':loaded of :count server loaded|:loaded of :count servers loaded', count($enforcement['servers']), ['loaded' => $loaded, 'count' => count($enforcement['servers'])]) }} · <a href="{{ route('porro.nginx') }}">{{ __('Setup and servers') }} →</a></div>
                @else
                    <div class="small">{{ __('For sites not behind Cloudflare, or as well as it. Two one-time steps on each server.') }} <a href="{{ route('porro.nginx') }}">{{ __('Set up nginx') }} →</a></div>
                @endif
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('Blocklist') }}</span><span>{{ count($blocks) }}</span></div>
        @if ($canManage)
            <form method="POST" action="{{ route('porro.block') }}" class="form">
                @csrf
                <input type="hidden" name="form" value="block">
                <input type="text" name="ip" required value="{{ old('form') === 'block' ? old('ip') : '' }}" placeholder="{{ __('IP or range') }}" aria-label="{{ __('IP or range') }}" style="flex: 0 1 16rem">
                <input type="text" name="reason" value="{{ old('form') === 'block' ? old('reason') : '' }}" placeholder="{{ __('Reason') }}" aria-label="{{ __('Reason') }}" style="flex: 1 1 10rem">
                <select name="days" aria-label="{{ __('Expires') }}">
                    <option value="7">{{ __('7 days') }}</option>
                    <option value="30">{{ __('30 days') }}</option>
                    <option value="0">{{ __('Never') }}</option>
                </select>
                <button class="btn primary" type="submit">{{ __('Block') }}</button>
            </form>
        @endif
        @include('porro-laravel::page.entries', ['entries' => $blocks, 'remove' => route('porro.unblock'), 'empty' => __('Nothing is blocked.')])
    </section>

    <section class="panel" id="safelist">
        <div class="panel-head"><span>{{ __('Safelist') }}</span><span>{{ __('never blocked or flagged') }}</span></div>
        @if ($canManage)
            <form method="POST" action="{{ route('porro.safelist') }}" class="form">
                @csrf
                <input type="hidden" name="form" value="safelist">
                <input type="text" name="ip" required value="{{ old('form') === 'safelist' ? old('ip') : $prefill }}" placeholder="{{ __('IP or range') }}" aria-label="{{ __('IP or range') }}" style="flex: 0 1 16rem">
                <input type="text" name="label" required value="{{ old('form') === 'safelist' ? old('label') : '' }}" placeholder="{{ __('Label, e.g. 1994 office') }}" aria-label="{{ __('Label') }}" style="flex: 1 1 10rem">
                <label class="small muted" style="display: inline-flex; align-items: center; gap: 6px"><input type="checkbox" name="confirm_wide" value="1"> {{ __('Wide range is OK') }}</label>
                <button class="btn primary" type="submit">{{ __('Add') }}</button>
            </form>
        @endif
        @include('porro-laravel::page.entries', ['entries' => $safe, 'remove' => route('porro.unsafelist'), 'empty' => __('Nothing is safelisted.')])
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('Always protected') }}</span><span>{{ __('can’t be blocked') }}</span></div>
        <p class="note small">{{ __('Infrastructure that has to reach this site. Blocks that overlap these are refused, and removed if a list changes.') }}</p>
        @foreach ($protected as $row)
            <div class="row"><span class="grow">{{ $row['label'] }}</span><span class="muted small">{{ trans_choice(':count range|:count ranges', $row['count'], ['count' => $row['count']]) }}</span></div>
        @endforeach
    </section>
@endsection
