<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conciliación bancaria</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    @include('partials.admin-styles')
    @livewireStyles
</head>
<body>
    @php($pendingMovements = \App\Models\BankMovement::pending()->credits()->count())

    <div class="pa-shell">
        @include('partials.admin-sidebar')

        <main class="pa-main">
            <div class="pa-main__inner">
                <header class="pa-header">
                    <div>
                        <h1 class="pa-header__title">Conciliación bancaria</h1>
                        <span class="pa-header__subtitle">Importe la cartola y revise los depósitos por conciliar</span>
                    </div>
                    <div class="pa-header__actions">
                        <div class="pa-user-chip">
                            <span class="pa-user-chip__avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                            <span>
                                <span class="pa-user-chip__name">{{ auth()->user()->name }}</span>
                                <span class="pa-user-chip__role">Administrador</span>
                            </span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="pa-logout">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true" focusable="false">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                    <path d="M16 17l5-5-5-5"/>
                                    <path d="M21 12H9"/>
                                </svg>
                                Cerrar sesión
                            </button>
                        </form>
                    </div>
                </header>

                <livewire:bank-reconciliation />

                <footer class="pa-foot">Uvopos · Panel de administración</footer>
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
