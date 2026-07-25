<div class="card">
    <div class="card-header">
        <strong>Iniciar sesión</strong>
    </div>
    <div class="card-body">
        <form wire:submit.prevent="authenticate">
            <div class="form-group">
                <label for="login-email">Correo electrónico</label>
                <input
                    type="email"
                    id="login-email"
                    class="form-control @error('email') is-invalid @enderror"
                    wire:model.defer="email"
                    autocomplete="username"
                >
                @error('email')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="login-password">Contraseña</label>
                <input
                    type="password"
                    id="login-password"
                    class="form-control @error('password') is-invalid @enderror"
                    wire:model.defer="password"
                    autocomplete="current-password"
                >
                @error('password')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group form-check">
                <input type="checkbox" id="login-remember" class="form-check-input" wire:model.defer="remember">
                <label class="form-check-label" for="login-remember">Recordarme</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="authenticate">Ingresar</span>
                <span wire:loading wire:target="authenticate">Ingresando…</span>
            </button>
        </form>
    </div>
</div>
