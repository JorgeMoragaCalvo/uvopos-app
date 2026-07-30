<div>
    {{-- One @php block, not the inline @php(...) form: Blade extracts
         @php…@endphp with a non-greedy regex, so mixing the two shapes in
         one file makes the first inline tag swallow the markup between them. --}}
    @php
        $totalCustomers = array_sum($counts);

        $tileCaptions = [
            \App\Enums\PaymentStatus::ON_TIME => 'Sin acciones pendientes',
            \App\Enums\PaymentStatus::DUE_SOON => 'Requieren seguimiento',
            \App\Enums\PaymentStatus::OVERDUE => 'Requieren su atención',
        ];
    @endphp

    {{-- Status counts double as the filter control: each tile fires the same
         filterByStatus() call the old button row did. --}}
    <div class="pa-stats">
        <button type="button"
                class="pa-stat pa-stat--info {{ $statusFilter === '' ? 'is-active' : '' }}"
                aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}"
                wire:click="filterByStatus('')">
            <span class="pa-stat__icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </span>
            <span class="pa-stat__label">Todos</span>
            <span class="pa-stat__value">{{ $totalCustomers }}</span>
            <span class="pa-stat__meta">clientes registrados</span>
            <span class="pa-stat__foot">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M3 12h18"/>
                    <path d="M14 5l7 7-7 7"/>
                </svg>
                Ver la lista completa
            </span>
        </button>

        @foreach ([\App\Enums\PaymentStatus::ON_TIME, \App\Enums\PaymentStatus::DUE_SOON, \App\Enums\PaymentStatus::OVERDUE] as $filterStatus)
            <button type="button"
                    class="pa-stat pa-stat--{{ $filterStatus }} {{ $statusFilter === $filterStatus ? 'is-active' : '' }}"
                    aria-pressed="{{ $statusFilter === $filterStatus ? 'true' : 'false' }}"
                    wire:click="filterByStatus('{{ $filterStatus }}')">
                <span class="pa-stat__icon">
                    @if ($filterStatus === \App\Enums\PaymentStatus::ON_TIME)
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M8.5 12.5l2.5 2.5 4.5-5"/>
                        </svg>
                    @elseif ($filterStatus === \App\Enums\PaymentStatus::DUE_SOON)
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3.5 2"/>
                        </svg>
                    @else
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true" focusable="false">
                            <path d="M12 3.5 22 20H2L12 3.5Z"/>
                            <path d="M12 10v4"/>
                            <path d="M12 17.2h.01"/>
                        </svg>
                    @endif
                </span>
                <span class="pa-stat__label">{{ \App\Enums\PaymentStatus::label($filterStatus) }}</span>
                <span class="pa-stat__value">{{ $counts[$filterStatus] }}</span>
                <span class="pa-stat__meta">de {{ $totalCustomers }} clientes</span>
                <span class="pa-stat__foot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" focusable="false">
                        <path d="M3 12h18"/>
                        <path d="M14 5l7 7-7 7"/>
                    </svg>
                    {{ $tileCaptions[$filterStatus] }}
                </span>
            </button>
        @endforeach
    </div>

    <div class="pa-card">
        <div class="pa-card__header">
            <h2 class="pa-card__title">Estado de pago del cliente</h2>
        </div>
        <div class="pa-card__body">
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
            @elseif ($paymentResult === 'declined')
                <div class="alert alert-danger" role="alert">
                    El pago fue rechazado por el emisor de la tarjeta. No se realizó ningún cargo.
                </div>
            @elseif ($paymentResult === 'aborted')
                <div class="alert alert-warning" role="alert">
                    El pago se canceló o el formulario expiró. No se realizó ningún cargo.
                </div>
            @elseif ($paymentResult === 'error')
                {{-- Distinct from 'declined': we could not confirm with Transbank,
                     so a charge may exist. Staff must check before retrying. --}}
                <div class="alert alert-danger" role="alert">
                    No se pudo confirmar el pago con Transbank. Verifique el estado antes de reintentar.
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
                        <span class="badge badge-light">Plan: {{ $customer->plan_type }}</span>
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

    <div class="pa-card">
        <div class="pa-card__header">
            <h2 class="pa-card__title">
                Todos los clientes
                @if ($statusFilter !== '')
                    <small class="text-muted">— {{ \App\Enums\PaymentStatus::label($statusFilter) }}</small>
                @endif
            </h2>
            <div class="pa-card__tools">
                <button type="button"
                        class="pa-pill {{ $statusFilter === '' ? 'is-active' : '' }}"
                        aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}"
                        wire:click="filterByStatus('')">
                    Todos
                    <span class="pa-pill__count">{{ $totalCustomers }}</span>
                </button>
                @foreach ([\App\Enums\PaymentStatus::ON_TIME, \App\Enums\PaymentStatus::DUE_SOON, \App\Enums\PaymentStatus::OVERDUE] as $filterStatus)
                    <button type="button"
                            class="pa-pill pa-pill--{{ $filterStatus }} {{ $statusFilter === $filterStatus ? 'is-active' : '' }}"
                            aria-pressed="{{ $statusFilter === $filterStatus ? 'true' : 'false' }}"
                            wire:click="filterByStatus('{{ $filterStatus }}')">
                        {{ \App\Enums\PaymentStatus::label($filterStatus) }}
                        <span class="pa-pill__count">{{ $counts[$filterStatus] }}</span>
                    </button>
                @endforeach
                @if ($statusFilter !== '')
                    <button type="button" class="pa-link" wire:click="filterByStatus('')">Ver todos</button>
                @endif
            </div>
        </div>

        @if ($customers->isEmpty())
            <div class="pa-card__body">
                <div class="alert alert-secondary mb-0" role="alert">
                    No hay clientes en este estado.
                </div>
            </div>
        @else
            <div class="pa-card__body pa-card__body--flush">
                <div class="table-responsive">
                    <table class="table table-sm table-hover pa-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>RUT</th>
                                <th>Plan</th>
                                <th>Vence</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customers as $row)
                                <tr wire:click="selectCustomer({{ $row->id }})"
                                    wire:key="customer-{{ $row->id }}"
                                    style="cursor: pointer;"
                                    class="{{ $customer && $customer->id === $row->id ? 'table-active' : '' }}">
                                    <td>{{ $row->name }}</td>
                                    <td>{{ $row->formatted_rut }}</td>
                                    <td>{{ $row->plan_type }}</td>
                                    <td class="pa-due pa-due--{{ $row->payment_status }}">
                                        {{ $row->payment_date ? $row->payment_date->format('d-m-Y') : '—' }}
                                    </td>
                                    <td>
                                        <span class="badge {{ \App\Enums\PaymentStatus::badgeClass($row->payment_status) }}">
                                            {{ \App\Enums\PaymentStatus::label($row->payment_status) }}
                                        </span>
                                        @if (!$row->is_active)
                                            <span class="badge badge-dark">Suspendido</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="pa-card__foot">
                {{ $customers->links() }}
            </div>
        @endif
    </div>
</div>
