<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Reset password') }} - {{ config('app.name', 'Open Locker') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('storage/assets/logo.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('storage/assets/logo.svg') }}">

    <style>
        :root {
            color-scheme: light;
            --page-bg: #f5f7fb;
            --surface: #ffffff;
            --surface-muted: #f8fafc;
            --text: #111827;
            --text-muted: #6b7280;
            --border: #dbe3f1;
            --primary: #5b50d6;
            --primary-dark: #4338ca;
            --primary-soft: #eeecff;
            --danger: #b42318;
            --danger-soft: #fef3f2;
            --success: #027a48;
            --success-soft: #ecfdf3;
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

        form {
            margin-top: 24px;
        }

        .stack {
            display: grid;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label {
            font-size: 0.95rem;
            font-weight: 600;
            color: #374151;
        }

        input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-muted);
            color: var(--text);
            padding: 0.9rem 1rem;
            font: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        input:focus {
            outline: none;
            border-color: rgba(91, 80, 214, 0.75);
            box-shadow: 0 0 0 4px rgba(91, 80, 214, 0.14);
            background: var(--surface);
        }

        .hint,
        .feedback {
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .hint {
            margin-top: 18px;
            background: var(--primary-soft);
            color: #4338ca;
        }

        .feedback-success {
            margin-top: 18px;
            background: var(--success-soft);
            color: var(--success);
        }

        .feedback-error {
            margin-top: 18px;
            background: var(--danger-soft);
            color: var(--danger);
        }

        .feedback-error ul {
            margin: 0;
            padding-left: 1.1rem;
        }

        .feedback-error li + li {
            margin-top: 0.35rem;
        }

        .button {
            width: 100%;
            border: 0;
            border-radius: 14px;
            padding: 0.95rem 1rem;
            font: inherit;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(180deg, #665df0 0%, var(--primary-dark) 100%);
            cursor: pointer;
            box-shadow: 0 16px 24px rgba(67, 56, 202, 0.22);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .button:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 28px rgba(67, 56, 202, 0.28);
        }

        .button:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(91, 80, 214, 0.18), 0 18px 28px rgba(67, 56, 202, 0.28);
        }

        .footer-note {
            margin-top: 18px;
            font-size: 0.9rem;
            text-align: center;
        }
    </style>
</head>
<body>
<main>
    <section class="panel" aria-labelledby="reset-password-title">
        <div class="brand">
            @include('filament.brand')
        </div>

        @php
            $hasSuccessStatus = session('status') && ! $errors->any();
        @endphp

        <h1 id="reset-password-title">
            {{ $hasSuccessStatus ? __('Password reset successful') : __('Reset your password') }}
        </h1>
        <p>
            {{ $hasSuccessStatus
                ? __('You can now sign in in the app with your new password.')
                : __('Choose a new password for your account. After resetting it, sign in again in the app with the new password.') }}
        </p>

        @if (session('status'))
            <div class="feedback feedback-success" role="status">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless ($hasSuccessStatus)
            <form method="POST" action="{{ route('password.reset.web.store') }}">
                @csrf

                <div class="stack">
                    <div class="field">
                        <label for="email">{{ __('Email address') }}</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            autocomplete="email"
                            value="{{ old('email', $email) }}"
                            required
                        >
                    </div>

                    @if (old('token', $token) !== '')
                        <input type="hidden" name="token" value="{{ old('token', $token) }}">
                        <div class="hint">
                            {{ __('Your reset token was loaded from the email link.') }}
                        </div>
                    @else
                        <div class="field">
                            <label for="token">{{ __('Reset token') }}</label>
                            <input
                                id="token"
                                name="token"
                                type="text"
                                autocomplete="off"
                                value="{{ old('token', $token) }}"
                                required
                            >
                        </div>
                    @endif

                    <div class="field">
                        <label for="password">{{ __('New password') }}</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="password_confirmation">{{ __('Confirm new password') }}</label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <button class="button" type="submit">{{ __('Reset password') }}</button>
                </div>
            </form>
        @endunless

        <p class="footer-note">{{ __('If you did not request a password reset, you can safely close this page.') }}</p>
    </section>
</main>
</body>
</html>
