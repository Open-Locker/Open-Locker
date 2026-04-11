<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Verify email') }} - {{ config('app.name', 'Open Locker') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('storage/assets/logo.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('storage/assets/logo.svg') }}">

    <style>
        :root {
            color-scheme: light;
            --page-bg: #f5f7fb;
            --surface: #ffffff;
            --text: #111827;
            --text-muted: #6b7280;
            --success: #027a48;
            --success-soft: #ecfdf3;
            --primary: #5b50d6;
            --primary-soft: #eeecff;
            --shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top, rgba(91, 80, 214, 0.12), transparent 30%),
                linear-gradient(180deg, #f8f9ff 0%, var(--page-bg) 100%);
            color: var(--text);
        }

        main {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px 16px;
        }

        .panel {
            width: min(100%, 460px);
            background: var(--surface);
            border: 1px solid rgba(91, 80, 214, 0.12);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 28px;
        }

        .brand {
            margin-bottom: 24px;
            color: #111827;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 1.75rem;
            line-height: 1.15;
            letter-spacing: -0.02em;
        }

        p {
            margin: 0;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .feedback {
            margin-top: 18px;
            border-radius: 16px;
            padding: 14px 16px;
            line-height: 1.5;
            background: var(--success-soft);
            color: var(--success);
        }

        .hint {
            margin-top: 18px;
            border-radius: 16px;
            padding: 14px 16px;
            line-height: 1.5;
            background: var(--primary-soft);
            color: var(--primary);
        }
    </style>
</head>
<body>
<main>
    <section class="panel" aria-labelledby="verify-email-title">
        <div class="brand">
            @include('filament.brand')
        </div>

        <h1 id="verify-email-title">
            {{ $alreadyVerified ? __('Email already verified') : __('Email verified successfully') }}
        </h1>
        <p>
            {{ $alreadyVerified
                ? __('This email address is already verified. You can continue in the app.')
                : __('The email address :email is now verified. You can continue in the app.', ['email' => $email]) }}
        </p>

        <div class="feedback" role="status">
            {{ $alreadyVerified
                ? __('No further action is required.')
                : __('Verification is complete. If the app was already open, you can return to it now.') }}
        </div>

        <div class="hint">
            {{ __('If you did not expect this email, you can ignore this page.') }}
        </div>
    </section>
</main>
</body>
</html>
