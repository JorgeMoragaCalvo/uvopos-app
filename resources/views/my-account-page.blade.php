<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi cuenta</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    {{-- Page-level design tokens and shell chrome. The bar itself is styled
         inside the Livewire component. Inline rather than compiled because
         the app has no shared layout and no built assets (see CLAUDE.md). --}}
    <style>
        :root {
            --pa-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                       "Helvetica Neue", Arial, sans-serif;

            --pa-page-bg: #f6f7f9;
            --pa-surface: #ffffff;
            --pa-text: #1c2430;
            --pa-text-muted: #6b7787;
            --pa-border: #e4e8ee;

            --pa-radius: 10px;
            --pa-radius-pill: 999px;
            --pa-shadow-sm: 0 1px 2px rgba(16, 24, 40, .06);
            --pa-shadow-md: 0 4px 16px rgba(16, 24, 40, .08);

            /* One accent triple per PaymentStatus value. */
            --pa-on-time-strong: #1a7f4b;
            --pa-on-time-tint: #e7f6ee;
            --pa-on-time-border: #b7e3ca;

            /* Same orange family as .alert-orange, so due-soon stays
               visually consistent with the staff view. */
            --pa-due-soon-strong: #d96a06;
            --pa-due-soon-tint: #fff1e3;
            --pa-due-soon-border: #ffd6ad;

            --pa-overdue-strong: #c62828;
            --pa-overdue-tint: #fdeceb;
            --pa-overdue-border: #f5c2c0;

            --pa-neutral-strong: #4a5568;
            --pa-neutral-tint: #eef1f5;
            --pa-neutral-border: #dbe1ea;
        }

        body {
            font-family: var(--pa-font);
            background-color: var(--pa-page-bg);
            color: var(--pa-text);
            min-height: 100vh;
        }

        .pa-page {
            max-width: 1080px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3rem;
        }

        .pa-session {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .75rem;
            flex-wrap: wrap;
        }

        .pa-session__user {
            font-size: .875rem;
            color: var(--pa-text-muted);
        }

        .pa-session__logout {
            appearance: none;
            border: 1px solid var(--pa-border);
            background: var(--pa-surface);
            color: var(--pa-text-muted);
            border-radius: var(--pa-radius-pill);
            padding: .35rem 1rem;
            font-size: .8125rem;
            line-height: 1.4;
            box-shadow: var(--pa-shadow-sm);
            transition: color .15s ease, border-color .15s ease, background-color .15s ease;
        }

        .pa-session__logout:hover {
            color: var(--pa-text);
            border-color: #c9d1dc;
            background: #fbfcfd;
        }

        .pa-session__logout:focus-visible {
            outline: 2px solid var(--pa-neutral-strong);
            outline-offset: 2px;
        }
    </style>
    @livewireStyles
</head>
<body>
    <h1 class="sr-only">Mi cuenta</h1>

    <livewire:my-payment-status />

    <div class="pa-page">
        <div class="pa-session">
            <span class="pa-session__user">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="pa-session__logout">Cerrar sesión</button>
            </form>
        </div>
    </div>

    @livewireScripts
</body>
</html>
