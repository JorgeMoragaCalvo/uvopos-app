<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi cuenta</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    @livewireStyles
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h5 mb-0">Mi cuenta</h1>
                        <small class="text-muted">{{ auth()->user()->name }}</small>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Cerrar sesión</button>
                    </form>
                </div>

                <livewire:my-payment-status />
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
