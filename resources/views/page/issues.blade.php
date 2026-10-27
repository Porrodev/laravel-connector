@foreach ($issues as $issue)
    <div class="row" style="align-items: flex-start">
        <span class="dot {{ $issue['style']['tone'] }}" aria-hidden="true"></span>
        <div class="grow">
            <div class="title">
                @if ($issue['link'])
                    <a href="{{ $issue['link'] }}" target="_blank" rel="noopener">{{ $issue['title'] }}</a>
                @else
                    {{ $issue['title'] }}
                @endif
                @if ($issue['scope'] === 'server')<span class="muted"> · {{ __('server') }}</span>@endif
                @if ($issue['acknowledged'])<span class="muted"> · {{ __('acknowledged') }}</span>@endif
            </div>
            @if ($issue['detail'])<div class="small">{{ $issue['detail'] }}</div>@endif
            @if ($issue['fix'])<code class="fix">{{ $issue['fix'] }}</code>@endif
        </div>
        <span class="label {{ $issue['style']['tone'] }}">{{ __($issue['style']['label']) }}</span>
    </div>
@endforeach
