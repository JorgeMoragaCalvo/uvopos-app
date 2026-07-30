{{-- Shared navigation for the two admin pages. It lives in the page shell,
     not inside a Livewire component, so a Livewire round trip never
     re-renders it and request()->routeIs() cannot go stale mid-page.

     $pendingMovements is set by the including shell (a plain COUNT(*)),
     the same read-only pattern both pages already use for the badge. --}}
<aside class="pa-sidebar">
    <div class="pa-sidebar__brand">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M12 2.2 21.2 12 12 21.8 2.8 12 12 2.2Zm0 5.1L7.9 12l4.1 4.7 4.1-4.7L12 7.3Z"/>
        </svg>
        <span class="pa-sidebar__brand-name">Uvopos</span>
    </div>

    <nav class="pa-sidebar__nav">
        <a href="{{ route('payment-alert') }}"
           class="pa-nav-item {{ request()->routeIs('payment-alert') ? 'is-active' : '' }}"
           @if (request()->routeIs('payment-alert')) aria-current="page" @endif>
            <svg class="pa-nav-item__icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" focusable="false">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
            </svg>
            <span class="pa-nav-item__label">Alerta de pago</span>
        </a>

        <a href="{{ route('bank-reconciliation') }}"
           class="pa-nav-item {{ request()->routeIs('bank-reconciliation') ? 'is-active' : '' }}"
           @if (request()->routeIs('bank-reconciliation')) aria-current="page" @endif>
            <svg class="pa-nav-item__icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" focusable="false">
                <path d="M3 10 12 4l9 6"/>
                <path d="M5 10v8M10 10v8M14 10v8M19 10v8"/>
                <path d="M3 20h18"/>
            </svg>
            <span class="pa-nav-item__label">Conciliación bancaria</span>
            @if (($pendingMovements ?? 0) > 0)
                <span class="pa-nav-item__badge">{{ $pendingMovements }}</span>
            @endif
        </a>
    </nav>

    <div class="pa-sidebar__spacer"></div>

    <div class="pa-promo">
        <svg class="pa-promo__icon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"
             aria-hidden="true" focusable="false">
            <path d="M12 2.5l1.9 5.1 5.1 1.9-5.1 1.9L12 16.5l-1.9-5.1L5 9.5l5.1-1.9L12 2.5Z"/>
            <path d="M18.5 15.5l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9.9-2.3Z"/>
        </svg>
        <span class="pa-promo__title">Cobranza al día</span>
        <span class="pa-promo__text">
            Revise los pagos por vencer y concilie las transferencias del día.
        </span>
    </div>
</aside>
