<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t['title'] }} · {{ config('app.name') }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1rem}
        main{background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;max-width:30rem;width:100%;padding:1.5rem;box-shadow:0 10px 25px -10px rgba(15,23,42,.2)}
        h1{font-size:1.25rem;margin:0 0 .5rem}p{margin:.5rem 0;color:#475569}.app{color:#0f172a;font-weight:600}
        .where{font-family:ui-monospace,monospace;font-size:.85rem;background:#f1f5f9;border-radius:.375rem;padding:.25rem .5rem;word-break:break-all;display:inline-block}
        fieldset{border:0;padding:0;margin:1rem 0}legend{font-weight:600;margin-bottom:.5rem}
        label{display:flex;gap:.6rem;align-items:flex-start;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .75rem;margin-bottom:.5rem;cursor:pointer}
        label small{display:block;color:#64748b}input[type=radio]{margin-top:.3rem}
        .row{display:flex;gap:.5rem;margin-top:1rem}.row form{flex:1}
        button{width:100%;font:inherit;font-weight:600;border-radius:.5rem;padding:.6rem 1rem;border:1px solid #cbd5e1;background:#fff;color:#0f172a;cursor:pointer}
        button.primary{background:#0f172a;border-color:#0f172a;color:#fff}button:focus-visible,input:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
        .note{font-size:.875rem}.error{color:#b91c1c}a{color:#1d4ed8}
    </style>
</head>
<body>
<main>
    <h1>{{ $t['title'] }}</h1>
    <p><span class="app">{{ $client->name }}</span> {{ $t['asks'] }}</p>
    <p class="note">{{ $t['returns'] }} <span class="where">{{ $redirectHost }}</span></p>

    @if ($agents->isEmpty())
        <p>{{ $t['none'] }}</p>
        <p><a href="{{ $appUrl }}">{{ $t['open'] }}</a></p>
    @else
        <form method="POST" action="{{ route('oauth.connect') }}" id="approve">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <fieldset>
                <legend>{{ $t['choose'] }}</legend>
                @foreach ($agents as $agent)
                    <label>
                        <input type="radio" name="agent" value="{{ $agent->id }}" required @checked($loop->first)>
                        <span>{{ $agent->name }}<small>{{ $workspaces[$agent->workspace_id] ?? '' }}</small></span>
                    </label>
                @endforeach
            </fieldset>
            @error('agent')<p class="error">{{ $message }}</p>@enderror
            <p class="note">{{ $t['means'] }}</p>
        </form>
    @endif

    <div class="row">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">{{ $t['deny'] }}</button>
        </form>
        @unless ($agents->isEmpty())
            <form><button type="submit" form="approve" class="primary">{{ $t['allow'] }}</button></form>
        @endunless
    </div>
    <p class="note">{{ $t['signedIn'] }} {{ $user->email }}</p>
</main>
</body>
</html>
