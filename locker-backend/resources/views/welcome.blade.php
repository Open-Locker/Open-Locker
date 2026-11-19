<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Open Locker') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('storage/assets/logo.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('storage/assets/logo.svg') }}">

    <style>
        :root { color-scheme: light dark; }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple Color Emoji", "Segoe UI Emoji";
            background: #ffffff;
            color: #111827;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #0b1220;
                color: #e5e7eb;
            }
        }

        main {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 16px;
            text-align: center;
        }

        .logo {
            width: 88px;
            height: 88px;
            margin: 0 auto 16px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 650;
            letter-spacing: -0.01em;
        }

        p {
            margin: 0 0 14px;
            max-width: 56ch;
            line-height: 1.6;
            opacity: 0.82;
        }

        a { color: inherit; }

        .login {
            display: inline-block;
            margin: 6px 0 18px;
            padding: 12px 22px;
            border-radius: 12px;
            background: #4338ca;
            color: #ffffff;
            font-weight: 650;
            text-decoration: none;
        }
    </style>
</head>
<body>
<main>
    <div>
        <img class="logo" src="{{ asset('storage/assets/logo.svg') }}" alt="Open-Locker Logo">
        <h1>Open-Locker</h1>
        <p>Open-Source Projekt für ein IoT-basiertes Locker-/Schließfach-System (Software, Hardware-Bauanleitung &amp; Kit).</p>
        {{-- Managers only know the domain; the admin login has to be findable from here (#373). --}}
        <a class="login" href="{{ filament()->getPanel('admin')->getLoginUrl() }}">{{ __('Sign in to the admin panel') }}</a>
        <p>{{ __('App users sign in with the Open-Locker mobile app.') }}</p>
        <p>
            GitHub: <a href="https://github.com/Open-Locker/Open-Locker" rel="noopener noreferrer" target="_blank">Open-Locker/Open-Locker</a>
        </p>
        <p style="opacity: 0.6; font-size: 12px; margin-top: 8px;">
            Version: {{ config('app.version', 'dev') }}
        </p>
    </div>
</main>
</body>
</html>
