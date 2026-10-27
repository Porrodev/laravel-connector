@extends('porro-laravel::page.layout', ['title' => __('Activity')])

@section('content')
    <div class="head">
        <div>
            <h1>{{ __('Activity') }}</h1>
            <p class="sub">{{ __('Probes, failed logins, 404 bursts and form floods · last :hours hours · kept on this site, never sent anywhere', ['hours' => $activity['hours'] ?? 24]) }}</p>
        </div>
    </div>

    @if (! $activity)
        <div class="panel"><p class="note" style="padding-top: 14px">{{ __('Activity couldn’t be read just now.') }}</p></div>
    @else
        @unless ($activity['recording'])
            <div class="flash" style="border-color: var(--control)">{{ __('Not recording on this copy of the site: activity is only recorded in production. Set PORRO_ACTIVITY=true to record here.') }}</div>
        @endunless

        <section class="panel">
            <div class="panel-head"><span>{{ __('Suspicious activity') }}</span><span>{{ __('last :hours h', ['hours' => $activity['hours']]) }}</span></div>
            <div class="stats">
                @foreach ($activity['totals'] as $total)
                    <div class="stat"><b>{{ number_format($total['value']) }}</b><span class="small muted">{{ $total['label'] }}</span></div>
                @endforeach
            </div>

            @if (empty($activity['flagged']))
                <p class="note">{{ __('Nothing suspicious in the last :hours hours.', ['hours' => $activity['hours']]) }}</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('IP') }}</th><th>{{ __('Country') }}</th><th>{{ __('Signals') }}</th><th>{{ __('Last request') }}</th><th>{{ __('Last seen') }}</th>
                                @if ($canManage)<th></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activity['flagged'] as $ip)
                                <tr>
                                    <td class="mono" style="white-space: nowrap">
                                        <a href="{{ route('porro.ip', $ip['ip']) }}">{{ $ip['ip'] }}</a>
                                        @foreach ($ip['badges'] as $badge)<span class="chip {{ $badge['tone'] === 'danger' ? 'off' : '' }}" style="margin-left: 4px">{{ $badge['label'] }}</span>@endforeach
                                    </td>
                                    <td style="white-space: nowrap" title="{{ $ip['country']['code'] ?? '' }}">{{ $ip['country']['label'] ?? '—' }}</td>
                                    <td>@foreach ($ip['signals'] as $signal)<span class="chip">{{ $signal['label'] }} {{ $signal['count'] }}</span>@endforeach</td>
                                    <td class="mono" style="overflow-wrap: anywhere">{{ $ip['request'] }}</td>
                                    <td class="muted" style="white-space: nowrap">{{ $ip['seen'] }}</td>
                                    @if ($canManage)
                                        <td>
                                            <div class="actions">
                                                @if ($ip['blocked'])
                                                    <span class="chip off">{{ __('blocked') }}</span>
                                                @else
                                                    <form method="POST" action="{{ route('porro.block') }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(\Porro\Laravel\Activity\Confirm::block($ip['ip'], 7)) }})">
                                                        @csrf
                                                        <input type="hidden" name="ip" value="{{ $ip['ip'] }}">
                                                        <input type="hidden" name="reason" value="{{ $ip['reason'] }}">
                                                        <input type="hidden" name="days" value="7">
                                                        <input type="hidden" name="suggested" value="1">
                                                        <button class="btn" type="submit">{{ __('Block 7 days') }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('porro.block') }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(\Porro\Laravel\Activity\Confirm::block($ip['ip'])) }})">
                                                        @csrf
                                                        <input type="hidden" name="ip" value="{{ $ip['ip'] }}">
                                                        <input type="hidden" name="reason" value="{{ $ip['reason'] }}">
                                                        <button class="btn" type="submit">{{ __('Block permanently') }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('porro.safelist') }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(\Porro\Laravel\Activity\Confirm::safelist($ip['ip'])) }})">
                                                        @csrf
                                                        <input type="hidden" name="ip" value="{{ $ip['ip'] }}">
                                                        <input type="hidden" name="label" value="{{ __('From the activity table') }}">
                                                        <button class="btn ghost" type="submit">{{ __('Safelist') }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="note small muted" style="padding-top: 10px; border-top: 1px solid var(--hairline)">
                <a href="{{ route('porro.blocking') }}">{{ trans_choice(':count IP blocked|:count IPs blocked', $activity['blocked'], ['count' => $activity['blocked']]) }} · {{ trans_choice(':count safelisted|:count safelisted', $activity['safelisted'], ['count' => $activity['safelisted']]) }}</a>
                @if ($activity['countries']) · <a href="{{ $activity['countries']['url'] }}" target="_blank" rel="noopener">{{ $activity['countries']['attribution'] }}</a>@endif
            </div>
        </section>
    @endif
@endsection
