<div>
    {{-- The customer view renders its own status bar instead of the Bootstrap
         alert used by the staff view. The Spanish copy is still kept in sync
         with payment-alert.blade.php; the markup deliberately is not.

         The bar collapses to the company name plus the pay button; the rest
         (status, RUT, due date, plan, notices) lives in a disclosure panel
         behind a click on the name. It is a native <details>/<summary>
         because the app loads no JS at all — see CLAUDE.md. The panel is
         server-rendered whether open or closed, which is also what the
         feature tests assert against.

         Design tokens (--pa-*) live in resources/views/my-account-page.blade.php. --}}
    <style>
        .pa-bar {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: var(--pa-surface);
            border-bottom: 1px solid var(--pa-border);
            box-shadow: var(--pa-shadow-md);
        }

        /* Status accent edge: readable at a glance without shouting. */
        .pa-bar::before {
            content: "";
            display: block;
            height: 4px;
            background: var(--pa-accent-strong, var(--pa-neutral-strong));
        }

        .pa-bar--on_time {
            --pa-accent-strong: var(--pa-on-time-strong);
            --pa-accent-tint: var(--pa-on-time-tint);
            --pa-accent-border: var(--pa-on-time-border);
        }

        .pa-bar--due_soon {
            --pa-accent-strong: var(--pa-due-soon-strong);
            --pa-accent-tint: var(--pa-due-soon-tint);
            --pa-accent-border: var(--pa-due-soon-border);
        }

        .pa-bar--overdue {
            --pa-accent-strong: var(--pa-overdue-strong);
            --pa-accent-tint: var(--pa-overdue-tint);
            --pa-accent-border: var(--pa-overdue-border);
        }

        .pa-bar__inner {
            max-width: 1080px;
            margin: 0 auto;
            padding: .875rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .pa-brand {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex: 0 0 auto;
            padding-right: 1.25rem;
            border-right: 1px solid var(--pa-border);
            align-self: stretch;
        }

        .pa-brand__mark {
            display: block;
            color: var(--pa-accent-strong, var(--pa-neutral-strong));
        }

        .pa-brand__name {
            font-size: 1.0625rem;
            font-weight: 600;
            letter-spacing: -.01em;
            color: var(--pa-text);
        }

        .pa-info {
            display: flex;
            flex-direction: column;
            gap: .25rem;
            min-width: 0;
        }

        /* The name is the disclosure trigger; the panel anchors to it. */
        .pa-menu {
            position: relative;
            min-width: 0;
        }

        .pa-menu__trigger {
            display: flex;
            align-items: center;
            gap: .5rem;
            min-width: 0;
            cursor: pointer;
            list-style: none;
            border-radius: var(--pa-radius-pill);
            padding: .2rem .6rem .2rem .5rem;
            transition: background-color .15s ease;
        }

        /* Kill the native disclosure triangle; the chevron replaces it. */
        .pa-menu__trigger::-webkit-details-marker {
            display: none;
        }

        .pa-menu__trigger::marker {
            content: "";
        }

        .pa-menu__trigger:hover {
            background: var(--pa-neutral-tint);
        }

        .pa-menu__trigger:focus-visible {
            outline: 2px solid var(--pa-accent-strong, var(--pa-neutral-strong));
            outline-offset: 2px;
        }

        /* Status colour stays glanceable while the label itself is hidden. */
        .pa-menu__dot {
            width: .5rem;
            height: .5rem;
            border-radius: 50%;
            flex: 0 0 auto;
            background: var(--pa-accent-strong, var(--pa-neutral-strong));
        }

        .pa-menu__name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--pa-text);
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .pa-menu__chevron {
            display: block;
            flex: 0 0 auto;
            color: var(--pa-text-muted);
            transition: transform .15s ease;
        }

        details[open] .pa-menu__chevron {
            transform: rotate(180deg);
        }

        .pa-menu__panel {
            position: absolute;
            top: calc(100% + .625rem);
            left: 0;
            z-index: 1;
            min-width: 20rem;
            max-width: min(90vw, 26rem);
            padding: .875rem 0 0;
            background: var(--pa-surface);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            box-shadow: var(--pa-shadow-md);
            overflow: hidden;
        }

        .pa-status {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            margin: 0 1rem .625rem;
            padding: .2rem .7rem .2rem .55rem;
            border-radius: var(--pa-radius-pill);
            background: var(--pa-accent-tint, var(--pa-neutral-tint));
            border: 1px solid var(--pa-accent-border, var(--pa-neutral-border));
            color: var(--pa-accent-strong, var(--pa-neutral-strong));
            font-size: .8125rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .pa-status::before {
            content: "";
            width: .5rem;
            height: .5rem;
            border-radius: 50%;
            background: currentColor;
            flex: 0 0 auto;
        }

        .pa-facts {
            margin: 0;
            padding: 0 1rem .875rem;
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        /* plan_type is free text with no bounded width — it must wrap. */
        .pa-facts__row {
            font-size: .875rem;
            color: var(--pa-text);
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .pa-facts__label {
            display: block;
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--pa-text-muted);
        }

        .pa-detail {
            font-size: .875rem;
            color: var(--pa-text-muted);
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .pa-action {
            margin-left: auto;
            flex: 0 0 auto;
        }

        .pa-pay {
            appearance: none;
            border: 0;
            border-radius: 8px;
            padding: .625rem 1.35rem;
            font-size: .9375rem;
            font-weight: 600;
            line-height: 1.4;
            color: #fff;
            background: var(--pa-accent-strong, var(--pa-neutral-strong));
            box-shadow: var(--pa-shadow-sm);
            white-space: nowrap;
            transition: filter .15s ease, transform .05s ease, box-shadow .15s ease;
        }

        .pa-pay:hover:not(:disabled) {
            filter: brightness(1.08);
            box-shadow: var(--pa-shadow-md);
        }

        .pa-pay:active:not(:disabled) {
            transform: translateY(1px);
        }

        .pa-pay:focus-visible {
            outline: 2px solid var(--pa-accent-strong, var(--pa-neutral-strong));
            outline-offset: 2px;
        }

        .pa-pay:disabled {
            opacity: .65;
            cursor: default;
        }

        /* Notices sit at the foot of the panel, full-bleed against its edges. */
        .pa-notice {
            border-top: 1px solid var(--pa-accent-border, var(--pa-neutral-border));
            background: var(--pa-accent-tint, var(--pa-neutral-tint));
            color: var(--pa-accent-strong, var(--pa-neutral-strong));
            padding: .625rem 1rem;
            font-size: .8125rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .pa-strip {
            max-width: 1080px;
            margin: 0 auto;
            padding: .625rem 1.25rem;
            font-size: .875rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .pa-result {
            border-bottom: 1px solid var(--pa-result-border);
            background: var(--pa-result-tint);
            color: var(--pa-result-strong);
        }

        .pa-result--success {
            --pa-result-strong: var(--pa-on-time-strong);
            --pa-result-tint: var(--pa-on-time-tint);
            --pa-result-border: var(--pa-on-time-border);
        }

        .pa-result--warning {
            --pa-result-strong: var(--pa-due-soon-strong);
            --pa-result-tint: var(--pa-due-soon-tint);
            --pa-result-border: var(--pa-due-soon-border);
        }

        .pa-result--danger {
            --pa-result-strong: var(--pa-overdue-strong);
            --pa-result-tint: var(--pa-overdue-tint);
            --pa-result-border: var(--pa-overdue-border);
        }

        @media (max-width: 576px) {
            .pa-bar__inner {
                flex-wrap: wrap;
                gap: .75rem;
                padding: .75rem 1rem;
            }

            .pa-brand {
                border-right: 0;
                padding-right: 0;
                width: 100%;
            }

            .pa-info {
                width: 100%;
            }

            .pa-menu {
                width: 100%;
            }

            /* Span the bar instead of overflowing the viewport. */
            .pa-menu__panel {
                right: 0;
                min-width: 0;
                max-width: none;
            }

            .pa-action {
                margin-left: 0;
                width: 100%;
            }

            .pa-pay {
                width: 100%;
            }

            .pa-strip {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
    </style>

    @php
        $status = $customer === null ? null : $customer->payment_status;
        $days = $customer === null ? null : $customer->days_past_due;
    @endphp

    <header class="pa-bar {{ $customer === null ? '' : 'pa-bar--' . $status }}">
        <div class="pa-bar__inner">
            <div class="pa-brand">
                <svg class="pa-brand__mark" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2.2 21.2 12 12 21.8 2.8 12 12 2.2Zm0 5.1L7.9 12l4.1 4.7 4.1-4.7L12 7.3Z"/>
                </svg>
                <span class="pa-brand__name">Uvopos</span>
            </div>

            @if ($customer === null)
                <div class="pa-info">
                    <span class="pa-detail">No hay una empresa asociada a su cuenta.</span>
                </div>
            @else
                <details class="pa-menu">
                    <summary class="pa-menu__trigger" title="Ver detalle de su cuenta">
                        <span class="pa-menu__dot" aria-hidden="true"></span>
                        <span class="pa-menu__name">{{ $customer->name }}</span>
                        <svg class="pa-menu__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </summary>

                    <div class="pa-menu__panel">
                        <span class="pa-status">{{ \App\Enums\PaymentStatus::label($status) }}</span>

                        <div class="pa-facts">
                            <div class="pa-facts__row">
                                <span class="pa-facts__label">RUT</span>
                                {{ $customer->formatted_rut }}
                            </div>

                            <div class="pa-facts__row">
                                <span class="pa-facts__label">Vencimiento</span>
                                @if ($customer->payment_date === null)
                                    Sin fecha de pago registrada.
                                @else
                                    Vence {{ $customer->payment_date->format('d-m-Y') }}
                                    @if ($days > 0)
                                        ({{ $days }} {{ $days === 1 ? 'día' : 'días' }} de atraso)
                                    @elseif ($days === 0)
                                        (vence hoy)
                                    @else
                                        (vence en {{ abs($days) }} {{ abs($days) === 1 ? 'día' : 'días' }})
                                    @endif
                                @endif
                            </div>

                            <div class="pa-facts__row">Plan: {{ $customer->plan_type }}</div>
                        </div>

                        @if ($status === \App\Enums\PaymentStatus::OVERDUE && $customer->is_active)
                            <div class="pa-notice">
                                @if ($customer->is_suspendable)
                                    Su servicio ya puede suspenderse por falta de pago.
                                @else
                                    Tiene {{ $customer->days_until_suspendable }}
                                    {{ $customer->days_until_suspendable === 1 ? 'día' : 'días' }}
                                    para regularizar el pago antes de la suspensión del servicio.
                                @endif
                            </div>
                        @endif

                        @if (!$customer->is_active)
                            <div class="pa-notice">
                                Servicio suspendido. Al pagar, su servicio se reactivará automáticamente.
                            </div>
                        @endif
                    </div>
                </details>

                @if ($this->can_pay)
                    <div class="pa-action">
                        <button type="button" class="pa-pay" wire:click="payWithWebpay" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="payWithWebpay">Pagar con Webpay</span>
                            <span wire:loading wire:target="payWithWebpay">Redirigiendo…</span>
                        </button>
                    </div>
                @endif
            @endif
        </div>
    </header>

    @if ($paymentResult === 'success')
        <div class="pa-result pa-result--success" role="alert">
            <div class="pa-strip">Pago realizado con éxito. Su estado se actualizó.</div>
        </div>
    @elseif ($paymentResult === 'declined')
        <div class="pa-result pa-result--danger" role="alert">
            <div class="pa-strip">Su tarjeta fue rechazada por el banco emisor. No se realizó ningún cargo.</div>
        </div>
    @elseif ($paymentResult === 'aborted')
        <div class="pa-result pa-result--warning" role="alert">
            <div class="pa-strip">Canceló el pago o el formulario expiró. No se realizó ningún cargo.</div>
        </div>
    @elseif ($paymentResult === 'error')
        {{-- Distinct from 'declined': the charge may have gone through,
             so do not tell the customer to simply try again. --}}
        <div class="pa-result pa-result--danger" role="alert">
            <div class="pa-strip">No pudimos confirmar su pago. Revise su cartola antes de reintentar o contáctenos.</div>
        </div>
    @elseif ($paymentResult === 'failed')
        <div class="pa-result pa-result--danger" role="alert">
            <div class="pa-strip">El pago no pudo procesarse. Intente nuevamente.</div>
        </div>
    @endif
</div>
