<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Porro · {{ $site }}</title>
    {{-- Everything inline: nothing to publish or build, and no requests elsewhere. --}}
    <style>
        :root {
            --canvas: #f6f6f3; --surface: #fff; --raised: #efeee9; --hairline: #e4e3dd; --control: #d6d5ce;
            --primary: #141413; --body: #3b3b38; --muted: #7a7973;
            --accent: #141413; --on-accent: #fff; --lime: #bfe84a;
            --critical: #d23f2f; --warning: #c77a12; --clear: #3d8b2f;
            --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: #101010; --surface: #171716; --raised: #20201e; --hairline: #2a2a27; --control: #3a3a36;
                --primary: #f2f1ec; --body: #c9c8c1; --muted: #8b8a83;
                --accent: #f2f1ec; --on-accent: #141413;
                --critical: #f0705f; --warning: #e8a64a; --clear: #8bc97a;
                color-scheme: dark;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--body); font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: inherit; }
        .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 16px 24px; padding: 12px 24px; background: var(--surface); border-bottom: 1px solid var(--hairline); }
        .brand { display: flex; align-items: center; gap: 8px; color: var(--primary); font-weight: 600; text-decoration: none; }
        .site { display: flex; align-items: center; gap: 8px; color: var(--primary); font-weight: 500; }
        .tag { font: 11px var(--mono); border: 1px solid var(--control); border-radius: 4px; padding: 1px 6px; color: var(--muted); }
        .tabs { display: flex; gap: 4px; flex: 1; }
        .tabs a { padding: 6px 12px; border-radius: 6px; color: var(--muted); text-decoration: none; }
        .tabs a[aria-current] { background: var(--raised); color: var(--primary); font-weight: 500; }
        .back { color: var(--muted); text-decoration: none; }
        main { max-width: 1100px; margin: 0 auto; padding: 32px 16px 56px; display: flex; flex-direction: column; gap: 20px; }
        .head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; }
        h1 { margin: 0; color: var(--primary); font-size: 26px; font-weight: 600; letter-spacing: -.01em; }
        .sub { margin: 4px 0 0; color: var(--muted); }
        .panel { background: var(--surface); border: 1px solid var(--hairline); border-radius: 10px; overflow: hidden; }
        .panel-head { display: flex; justify-content: space-between; gap: 12px; padding: 12px 16px; font: 11px var(--mono); text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .panel-body { padding: 0 16px 16px; }
        .row { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 16px; padding: 10px 16px; border-top: 1px solid var(--hairline); }
        .grow { flex: 1 1 14rem; min-width: 0; overflow-wrap: anywhere; }
        .title { color: var(--primary); font-weight: 500; }
        .muted { color: var(--muted); }
        .small { font-size: 12px; }
        .mono { font-family: var(--mono); font-size: 12.5px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; flex: none; margin-top: 7px; align-self: flex-start; }
        .critical { color: var(--critical); } .dot.critical { background: var(--critical); }
        .warning { color: var(--warning); } .dot.warning { background: var(--warning); }
        .notice { color: var(--muted); } .dot.notice { background: var(--control); }
        .label { font: 11px var(--mono); text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        code.fix { display: inline-block; margin-top: 4px; font: 12px var(--mono); color: var(--primary); }
        pre { margin: 8px 0 0; padding: 12px 14px; border-radius: 8px; background: var(--raised); color: var(--primary); font: 12px/1.6 var(--mono); white-space: pre-wrap; overflow-wrap: anywhere; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 12px; padding: 0 16px 14px; }
        .stat { background: var(--raised); border-radius: 8px; padding: 12px 14px; }
        .stat b { display: block; color: var(--primary); font-size: 22px; font-weight: 600; line-height: 1.2; }
        .chip { display: inline-block; font: 11.5px var(--mono); border: 1px solid var(--control); border-radius: 4px; padding: 0 6px; margin: 1px 2px 1px 0; color: var(--body); }
        .chip.on { border-color: var(--clear); color: var(--clear); }
        .chip.off { border-color: var(--critical); color: var(--critical); }
        .table { width: 100%; border-collapse: collapse; }
        .table-wrap { overflow-x: auto; }
        .table th { text-align: left; padding: 8px 16px 8px 0; font: 11px var(--mono); text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 400; }
        .table th:first-child, .table td:first-child { padding-left: 16px; }
        .table td { padding: 10px 16px 10px 0; border-top: 1px solid var(--hairline); vertical-align: middle; }
        .actions { display: flex; justify-content: flex-end; align-items: center; gap: 6px; white-space: nowrap; }
        .btn { font: inherit; font-size: 13px; padding: 5px 12px; border-radius: 6px; border: 1px solid var(--control); background: var(--surface); color: var(--primary); cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: var(--raised); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        .btn.ghost { border-color: transparent; background: none; color: var(--muted); }
        .btn.ghost:hover { color: var(--primary); }
        .btn.danger { border-color: transparent; background: none; color: var(--critical); }
        form.inline { display: inline; margin: 0; }
        .form { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 16px 14px; }
        input[type=text], select { font: 13px var(--mono); padding: 6px 10px; border-radius: 6px; border: 1px solid var(--control); background: var(--surface); color: var(--primary); min-width: 0; }
        details.protected summary { cursor: pointer; list-style: none; }
        details.protected summary::-webkit-details-marker { display: none; }
        details.protected summary::before { content: '›'; color: var(--muted); display: inline-block; transition: transform .15s; }
        details.protected[open] summary::before { transform: rotate(90deg); }
        .ranges { display: grid; grid-template-columns: repeat(auto-fill, minmax(24ch, 1fr)); gap: 2px 16px; max-height: 240px; overflow-y: auto; padding: 10px 14px; border-radius: 8px; background: var(--raised); color: var(--primary); font: 12px/1.6 var(--mono); }
        .flash { padding: 10px 14px; border-radius: 8px; border: 1px solid; }
        .flash.error { color: var(--critical); border-color: var(--critical); }
        .flash.success { color: var(--clear); border-color: var(--clear); }
        .note { padding: 0 16px 14px; }
        dialog.confirm { width: min(440px, calc(100vw - 32px)); padding: 0; border: 1px solid var(--hairline); border-radius: 12px; background: var(--surface); color: var(--body); box-shadow: 0 12px 40px rgb(0 0 0 / .18); }
        dialog.confirm::backdrop { background: rgb(20 20 19 / .35); }
        dialog.confirm form { display: grid; gap: 8px; padding: 20px; }
        dialog.confirm h2 { margin: 0; color: var(--primary); font-size: 16px; font-weight: 600; overflow-wrap: anywhere; }
        dialog.confirm p { margin: 0; }
        dialog.confirm .actions { margin-top: 12px; }
        .btn.solid-danger { background: var(--critical); border-color: var(--critical); color: #fff; }
        @media (max-width: 640px) { .bar { padding: 12px 16px; } .tabs { order: 3; flex-basis: 100%; } }
    </style>
</head>
<body>
    <header class="bar">
        <a class="brand" href="{{ route('porro.health') }}">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 1.5 18.5 10 10 18.5 1.5 10Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" fill="currentColor"/></svg>
            porro
        </a>
        <span class="site">{{ $site }} <span class="tag">{{ $environment }}</span></span>
        <nav class="tabs" aria-label="{{ __('Porro') }}">
            <a href="{{ route('porro.health') }}" @if ($tab === 'health') aria-current="page" @endif>{{ __('Site health') }}</a>
            <a href="{{ route('porro.activity') }}" @if (in_array($tab, ['activity', 'ip'], true)) aria-current="page" @endif>{{ __('Activity') }}</a>
            <a href="{{ route('porro.blocking') }}" @if (in_array($tab, ['blocking', 'nginx'], true)) aria-current="page" @endif>{{ __('Blocking') }}</a>
        </nav>
        <a class="back" href="{{ $backUrl }}">{{ __('Back to app') }} ↗</a>
    </header>
    <main>
        @if (session('error'))
            <div class="flash error" role="alert">{{ session('error') }}</div>
        @elseif (session('success'))
            <div class="flash success" role="status">{{ session('success') }}</div>
        @endif

        @yield('content')
    </main>
    <dialog id="porro-confirm" class="confirm" aria-labelledby="porro-confirm-title">
        <form method="dialog">
            <h2 id="porro-confirm-title" data-porro-title></h2>
            <p data-porro-body></p>
            <div class="actions">
                <button class="btn" value="cancel" data-porro-cancel>{{ __('Cancel') }}</button>
                <button class="btn solid-danger" value="ok" data-porro-ok="danger"></button>
                <button class="btn primary" value="ok" data-porro-ok="safe" hidden style="display: none"></button>
            </div>
        </form>
    </dialog>
    <script>{!! \Porro\Laravel\Activity\Confirm::script() !!}</script>
</body>
</html>
