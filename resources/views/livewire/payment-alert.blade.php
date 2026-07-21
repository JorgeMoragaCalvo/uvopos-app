<div>
    {{-- Bootstrap 4 has no orange alert/badge variant --}}
    <style>
        .alert-orange {
            color: #7a3e00;
            background-color: #ffe5cc;
            border-color: #ffd6ad;
        }
        .badge-orange {
            color: #fff;
            background-color: #fd7e14;
        }
    </style>

    <div class="card">
        <div class="card-header">
            <strong>Estado de pago del cliente</strong>
        </div>
        <div class="card-body">
            <form wire:submit.prevent="lookup">
                <div class="form-group">
                    <label for="payment-alert-search">RUT o ID de cliente</label>
                    <div class="input-group">
                        <input
                            type="text"
                            id="payment-alert-search"
                            class="form-control @error('search') is-invalid @enderror"
                            placeholder="Ej: 12.345.678-5 o 42"
                            wire:model.defer="search"
                        >
                        <div class="input-group-append">
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="lookup">Buscar</span>
                                <span wire:loading wire:target="lookup">Buscando…</span>
                            </button>
                        </div>
                        @error('search')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </form>

            @if ($paymentResult === 'success')
                <div class="alert alert-success" role="alert">
                    Pago realizado con éxito. El estado del cliente se actualizó.
                </div>
            @elseif ($paymentResult === 'failed')
                <div class="alert alert-danger" role="alert">
                    El pago no pudo procesarse. Intente nuevamente.
                </div>
            @endif

            @if ($notFound)
                <div class="alert alert-secondary mb-0" role="alert">
                    No se encontró un cliente para <strong>{{ $search }}</strong>.
                </div>
            @endif

            @if ($customer)
                @php
                    $status = $customer->payment_status;
                    $days = $customer->days_past_due;
                    $canPay = in_array($status, [\App\Enums\PaymentStatus::DUE_SOON, \App\Enums\PaymentStatus::OVERDUE], true)
                        && $customer->is_active
                        && $customer->charge_amount !== null;
                @endphp

                <div class="alert {{ \App\Enums\PaymentStatus::alertClass($status) }} mb-3" role="alert">
                    <h5 class="alert-heading mb-2">
                        {{ \App\Enums\PaymentStatus::label($status) }}
                    </h5>
                    <p class="mb-1">
                        <strong>{{ $customer->name }}</strong>
                        &mdash; RUT {{ $customer->formatted_rut }} (ID {{ $customer->id }})
                    </p>
                    <p class="mb-0">
                        @if ($customer->payment_date === null)
                            Sin fecha de pago registrada.
                        @else
                            Fecha de pago: {{ $customer->payment_date->format('d-m-Y') }}
                            @if ($days > 0)
                                &mdash; {{ $days }} {{ $days === 1 ? 'día' : 'días' }} de atraso.
                            @elseif ($days === 0)
                                &mdash; vence hoy.
                            @else
                                &mdash; vence en {{ abs($days) }} {{ abs($days) === 1 ? 'día' : 'días' }}.
                            @endif
                        @endif
                    </p>

                    @if ($status === \App\Enums\PaymentStatus::OVERDUE && $customer->is_active)
                        <p class="mb-0 mt-2">
                            @if ($customer->is_suspendable)
                                <strong>El servicio ya puede suspenderse por falta de pago.</strong>
                            @else
                                <strong>
                                    Tiene {{ $customer->days_until_suspendable }}
                                    {{ $customer->days_until_suspendable === 1 ? 'día' : 'días' }}
                                    para regularizar el pago antes de la suspensión del servicio.
                                </strong>
                            @endif
                        </p>
                    @endif

                    @if (!$customer->is_active)
                        <p class="mb-0 mt-2"><strong>Servicio suspendido.</strong></p>
                    @endif
                </div>

                <div class="d-flex flex-wrap" style="gap: .5rem;">
                    @if ($canPay)
                        <button type="button" class="btn btn-primary" wire:click="payWithWebpay" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="payWithWebpay">Pagar con Webpay</span>
                            <span wire:loading wire:target="payWithWebpay">Redirigiendo…</span>
                        </button>
                    @endif

                    @if ($customer->is_suspendable)
                        @if (!$confirmingSuspend)
                            <button type="button" class="btn btn-outline-danger" wire:click="confirmSuspend">
                                Suspender servicio
                            </button>
                        @else
                            <span class="align-self-center">¿Confirmar suspensión?</span>
                            <button type="button" class="btn btn-danger" wire:click="suspend">Sí, suspender</button>
                            <button type="button" class="btn btn-outline-secondary" wire:click="cancelSuspend">Cancelar</button>
                        @endif
                    @endif

                    @if (!$customer->is_active)
                        <button type="button" class="btn btn-outline-success" wire:click="reactivate">
                            Reactivar servicio
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
