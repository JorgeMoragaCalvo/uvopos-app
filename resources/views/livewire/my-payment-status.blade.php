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
            <strong>Estado de mi pago</strong>
        </div>
        <div class="card-body">
            @if ($paymentResult === 'success')
                <div class="alert alert-success" role="alert">
                    Pago realizado con éxito. Su estado se actualizó.
                </div>
            @elseif ($paymentResult === 'failed')
                <div class="alert alert-danger" role="alert">
                    El pago no pudo procesarse. Intente nuevamente.
                </div>
            @endif

            @if ($customer === null)
                <div class="alert alert-secondary mb-0" role="alert">
                    No hay una empresa asociada a su cuenta.
                </div>
            @else
                @php
                    $status = $customer->payment_status;
                    $days = $customer->days_past_due;
                @endphp

                <div class="alert {{ \App\Enums\PaymentStatus::alertClass($status) }} mb-3" role="alert">
                    <h5 class="alert-heading mb-2">
                        {{ \App\Enums\PaymentStatus::label($status) }}
                    </h5>
                    <p class="mb-1">
                        <strong>{{ $customer->name }}</strong>
                        &mdash; RUT {{ $customer->formatted_rut }}
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
                                <strong>Su servicio ya puede suspenderse por falta de pago.</strong>
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
                        <p class="mb-0 mt-2">
                            <strong>Servicio suspendido.</strong>
                            Al pagar, su servicio se reactivará automáticamente.
                        </p>
                    @endif
                </div>

                @if ($this->can_pay)
                    <button type="button" class="btn btn-primary" wire:click="payWithWebpay" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="payWithWebpay">Pagar con Webpay</span>
                        <span wire:loading wire:target="payWithWebpay">Redirigiendo…</span>
                    </button>
                @endif
            @endif
        </div>
    </div>
</div>
