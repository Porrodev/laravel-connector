@extends('porro-laravel::page.layout', ['title' => $details['ip']])

@section('content')
    <style>
        .badge { display: inline-block; font: 11.5px var(--mono); border: 1px solid var(--control); border-radius: 4px; padding: 1px 7px; margin: 0 4px 4px 0; }
        .badge.danger { color: var(--critical); border-color: var(--critical); }
        .badge.warning { color: var(--warning); border-color: var(--warning); }
        .bars { display: flex; align-items: flex-end; gap: 2px; height: 44px; padding: 0 16px; }
        .bars span { flex: 1; min-height: 2px; border-radius: 1px; background: var(--hairline); }
        .bars span.some { background: var(--warning); }
        .bars span.lots { background: var(--critical); }
        .kv { display: grid; grid-template-columns: 9rem 1fr; gap: 0 16px; padding: 9px 16px; border-top: 1px solid var(--hairline); }
        .kv dt { color: var(--muted); } .kv dd { margin: 0; color: var(--primary); overflow-wrap: anywhere; }
    </style>

    <div class="head">
        <div>
            <p class="small" style="margin: 0"><a href="{{ route('porro.activity') }}">← {{ __('Activity') }}</a></p>
            <h1 class="mono" style="font-size: 24px">{{ $details['ip'] }}</h1>
            <p class="sub">
                {{ $details['country']['label'] ?? __('Country unknown') }}
                @if ($details['activity'] && $details['activity']['first']) · {{ __('first seen here :ago', ['ago' => $details['activity']['first']]) }} @endif
                @if ($details['blocked']) · <span class="critical">{{ __('blocked') }}</span> @endif
                @if ($details['safelisted']) · <span class="muted">{{ __('safelisted') }}</span> @endif
            </p>
            @if ($details['badges'])
                <div style="margin-top: 8px">
                    @foreach ($details['badges'] as $badge)<span class="badge {{ $badge['tone'] }}">{{ $badge['label'] }}</span>@endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($details['crawler'])
        <div class="flash {{ $details['crawler']['real'] ? 'success' : 'error' }}" role="note">
            <strong>{{ $details['crawler']['title'] }}.</strong> {{ $details['crawler']['body'] }}
        </div>
    @endif

    @if ($canManage && ! $details['blocked'] && ! $details['safelisted'])
        <div class="actions" style="justify-content: flex-start; flex-wrap: wrap">
            @foreach ([[__('Block permanently'), 0, $details['ip'], 'primary'], [__('Block 7 days'), 7, $details['ip'], ''], ...($details['range'] ? [[__('Block :range', ['range' => $details['range']]), 7, $details['range'], '']] : [])] as [$label, $days, $target, $style])
                <form method="POST" action="{{ route('porro.block') }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(\Porro\Laravel\Activity\Confirm::block($target, $days)) }})">
                    @csrf
                    <input type="hidden" name="ip" value="{{ $target }}">
                    <input type="hidden" name="reason" value="{{ $details['reason'] }}">
                    <input type="hidden" name="days" value="{{ $days }}">
                    @if ($days)<input type="hidden" name="suggested" value="1">@endif
                    <button class="btn {{ $style }}" type="submit">{{ $label }}</button>
                </form>
            @endforeach
            <form method="POST" action="{{ route('porro.safelist') }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(\Porro\Laravel\Activity\Confirm::safelist($details['ip'])) }})">
                @csrf
                <input type="hidden" name="ip" value="{{ $details['ip'] }}">
                <input type="hidden" name="label" value="{{ __('From the IP details page') }}">
                <button class="btn ghost" type="submit">{{ __('Safelist') }}</button>
            </form>
        </div>
    @endif

    <section class="panel">
        <div class="panel-head"><span>{{ __('Network') }}</span></div>
        @forelse ($details['network'] as $label => $value)
            <dl class="kv"><dt>{{ __($label) }}</dt><dd class="{{ in_array($label, ['Hostname', 'User agent'], true) ? 'mono' : '' }}">{{ $value }}</dd></dl>
        @empty
            <p class="note">{{ __('Nothing more is known about this network. Connect Cloudflare for the network and its type.') }}</p>
        @endforelse
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('What it did here') }}</span><span>{{ __('signals per hour · last 48 h') }}</span></div>
        @if ($details['activity'])
            <div class="bars" aria-hidden="true">
                @foreach ($details['activity']['hours'] as $n)
                    <span class="{{ $n > 10 ? 'lots' : ($n > 0 ? 'some' : '') }}" style="height: {{ $n ? max(6, (int) round($n / $details['activity']['peak'] * 44)) : 2 }}px"></span>
                @endforeach
            </div>
            <div class="row small muted" style="border: 0; padding-top: 4px"><span class="grow">{{ __('48 h ago') }}</span><span>{{ __('now') }}</span></div>
            @foreach ($details['activity']['requests'] as $r)
                <div class="row mono">
                    <span class="muted" style="width: 3.2rem">{{ $r['method'] }}</span>
                    <span class="grow">{{ $r['path'] }}</span>
                    <span class="{{ $r['status'] >= 200 && $r['status'] < 300 ? 'critical' : 'muted' }}">{{ $r['status'] ?: '—' }}</span>
                    <span class="muted">{{ $r['time'] }}</span>
                </div>
            @endforeach
        @else
            <p class="note">{{ __('Nothing flagged from this IP in the last 48 hours.') }}</p>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><span>{{ __('Reputation') }}</span><span>AbuseIPDB</span></div>
        @if ($details['reputation'])
            <dl class="kv"><dt>{{ __('Abuse confidence') }}</dt><dd class="{{ $details['reputation']['score'] >= 50 ? 'critical' : '' }}">{{ $details['reputation']['score'] }}%</dd></dl>
            <dl class="kv"><dt>{{ __('Reports') }}</dt><dd>{{ number_format($details['reputation']['reports']) }}@if ($details['reputation']['last_reported']) · {{ __('last :ago', ['ago' => \Carbon\Carbon::parse($details['reputation']['last_reported'])->diffForHumans()]) }}@endif</dd></dl>
            @if ($details['reputation']['isp'])<dl class="kv"><dt>{{ __('ISP') }}</dt><dd>{{ $details['reputation']['isp'] }}@if ($details['reputation']['usage']) · {{ $details['reputation']['usage'] }}@endif</dd></dl>@endif
        @elseif ($details['canCheckReputation'] && $canManage)
            <div class="note">
                <form method="POST" action="{{ route('porro.reputation', $details['ip']) }}" class="inline">
                    @csrf
                    <button class="btn" type="submit">{{ __('Check on AbuseIPDB') }}</button>
                </form>
                <span class="small muted">{{ __('Sends this IP to AbuseIPDB.') }}</span>
            </div>
        @else
            <p class="note small muted">{{ __('Not checked. Set PORRO_ABUSEIPDB_KEY to check an IP’s reputation on request (it sends the IP to AbuseIPDB).') }}</p>
        @endif
    </section>

    <p class="small muted">
        {{ __('Sources') }}: {{ implode(' · ', $details['sources']) }} · {{ $details['fetched'] }}
        @if ($details['attribution']) · <a href="{{ $details['attribution']['url'] }}" target="_blank" rel="noopener">{{ $details['attribution']['text'] }}</a>@endif
    </p>
@endsection
