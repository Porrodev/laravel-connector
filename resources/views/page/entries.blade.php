@if (empty($entries))
    <p class="note">{{ $empty }}</p>
@else
    @foreach ($entries as $entry)
        <div class="row">
            <span class="mono" style="min-width: 12rem">{{ $entry['cidr'] }}</span>
            <span class="grow">{{ $entry['note'] }}</span>
            <span class="small muted">{{ $entry['source'] }}@if ($entry['by']) · {{ $entry['by'] }}@endif</span>
            <span class="small muted" style="min-width: 6rem">{{ $entry['expires'] }}</span>
            @if ($canManage && ! $entry['fromConfig'])
                <form method="POST" action="{{ $remove }}" class="inline">
                    @csrf
                    <input type="hidden" name="ip" value="{{ $entry['cidr'] }}">
                    <button class="btn danger" type="submit">{{ __('Remove') }}</button>
                </form>
            @endif
        </div>
    @endforeach
@endif
