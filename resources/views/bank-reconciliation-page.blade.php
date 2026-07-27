<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conciliación bancaria</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    @livewireStyles
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-11">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h5 mb-0">Conciliación bancaria</h1>
                        <small class="text-muted">{{ auth()->user()->name }}</small>
                    </div>
                    <div class="d-flex align-items-center">
                        <a href="{{ route('payment-alert') }}" class="btn btn-sm btn-outline-primary mr-2">
                            Alerta de pago
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Cerrar sesión</button>
                        </form>
                    </div>
                </div>

                <livewire:bank-reconciliation />
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
