{{-- Design tokens and chrome for the two admin pages (/payment-alert and
     /conciliacion-bancaria). Inline rather than compiled because the app has
     no shared layout and no built assets: resources/css/app.css is empty and
     mix() throws without public/mix-manifest.json (see CLAUDE.md).

     This <style> must be included AFTER the Bootstrap 4 CDN link, because
     most of what follows are equal-specificity overrides of Bootstrap's own
     .btn / .badge / .alert / .table / .form-control / .page-link rules. --}}
<style>
    /* ============================================================
       00 · Tokens
       The base --pa-* block is an intentional twin of the one in
       resources/views/my-account-page.blade.php. /mi-cuenta keeps its
       own copy (it is a separate page shell); keep the two in sync.
       ============================================================ */
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

        /* --- admin-only additions below --- */

        /* A fourth, non-status accent: links, the active page, badge-primary
           and the "all customers" tile. Deliberately not a PaymentStatus. */
        --pa-info-strong: #1d4ed8;
        --pa-info-tint: #e8effd;
        --pa-info-border: #c3d4fb;

        --pa-dark-strong: #334155;
        --pa-dark-tint: #e2e8f0;
        --pa-dark-border: #cbd5e1;

        --pa-nav-bg: #16223a;
        --pa-nav-active-bg: #24344f;
        --pa-nav-text: #a7b4c8;
        --pa-nav-text-active: #ffffff;
        --pa-nav-border: rgba(255, 255, 255, .08);

        --pa-nav-width: 208px;
        --pa-content-max: 1280px;

        --pa-radius-sm: 8px;
        --pa-radius-lg: 14px;
        --pa-radius-tile: 12px;
        --pa-shadow-card: 0 1px 3px rgba(16, 24, 40, .05),
                          0 10px 24px -12px rgba(16, 24, 40, .18);

        --pa-promo-from: #2a4d8f;
        --pa-promo-to: #7c4dff;
    }

    /* ============================================================
       01 · Base
       ============================================================ */
    body {
        font-family: var(--pa-font);
        background-color: var(--pa-page-bg);
        color: var(--pa-text);
        min-height: 100vh;
    }

    h1, h2, h3, h4, h5, h6 {
        letter-spacing: -.01em;
    }

    a {
        color: var(--pa-info-strong);
    }

    a:hover {
        color: #163bb0;
    }

    /* Nothing here relies on hover alone, so keyboard focus must always
       be visible — there is no JS to compensate. */
    .pa-shell *:focus-visible {
        outline: 2px solid var(--pa-info-strong);
        outline-offset: 2px;
    }

    /* ============================================================
       02 · Shell
       ============================================================ */
    .pa-main {
        margin-left: var(--pa-nav-width);
        min-height: 100vh;
        min-width: 0;
        padding: 1.75rem 2rem 3rem;
    }

    .pa-main__inner {
        max-width: var(--pa-content-max);
        margin: 0 auto;
    }

    /* ============================================================
       03 · Sidebar
       ============================================================ */
    .pa-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: var(--pa-nav-width);
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        padding: 1.25rem .75rem;
        background: var(--pa-nav-bg);
        overflow-y: auto;
        z-index: 1030;
    }

    .pa-sidebar__brand {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .25rem .5rem .75rem;
        color: var(--pa-nav-text-active);
        border-bottom: 1px solid var(--pa-nav-border);
    }

    .pa-sidebar__brand-name {
        font-size: 1.125rem;
        font-weight: 700;
        letter-spacing: -.02em;
    }

    .pa-sidebar__nav {
        display: flex;
        flex-direction: column;
        gap: .2rem;
    }

    .pa-nav-item {
        display: flex;
        align-items: center;
        gap: .65rem;
        padding: .55rem .75rem;
        border-radius: var(--pa-radius-sm);
        color: var(--pa-nav-text);
        font-size: .875rem;
        line-height: 1.3;
        text-decoration: none;
        transition: background-color .15s ease, color .15s ease;
    }

    .pa-nav-item:hover {
        background: var(--pa-nav-active-bg);
        color: var(--pa-nav-text-active);
        text-decoration: none;
    }

    .pa-nav-item.is-active {
        background: var(--pa-nav-active-bg);
        color: var(--pa-nav-text-active);
        font-weight: 600;
        box-shadow: inset 2px 0 0 var(--pa-info-strong);
    }

    .pa-nav-item__icon {
        flex: 0 0 18px;
        opacity: .85;
    }

    .pa-nav-item.is-active .pa-nav-item__icon {
        opacity: 1;
    }

    .pa-nav-item__label {
        flex: 1 1 auto;
        min-width: 0;
    }

    .pa-nav-item__badge {
        flex: 0 0 auto;
        border-radius: var(--pa-radius-pill);
        background: var(--pa-info-strong);
        color: #fff;
        font-size: .6875rem;
        font-weight: 600;
        line-height: 1;
        padding: .25rem .45rem;
        font-variant-numeric: tabular-nums;
    }

    .pa-sidebar__spacer {
        margin-top: auto;
    }

    .pa-promo {
        border-radius: var(--pa-radius-lg);
        background: linear-gradient(150deg, var(--pa-promo-from), var(--pa-promo-to));
        color: #fff;
        padding: .9rem;
    }

    .pa-promo__icon {
        opacity: .9;
    }

    .pa-promo__title {
        display: block;
        margin-top: .4rem;
        font-size: .8125rem;
        font-weight: 700;
    }

    .pa-promo__text {
        display: block;
        margin-top: .25rem;
        font-size: .75rem;
        line-height: 1.4;
        opacity: .85;
    }

    /* ============================================================
       04 · Page header and user chip
       ============================================================ */
    .pa-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .pa-header__title {
        margin: 0;
        font-size: 1.6rem;
        font-weight: 700;
        letter-spacing: -.02em;
    }

    .pa-header__subtitle {
        display: block;
        margin-top: .15rem;
        font-size: .9rem;
        color: var(--pa-text-muted);
    }

    .pa-header__actions {
        display: flex;
        align-items: center;
        gap: .6rem;
        flex-wrap: wrap;
    }

    .pa-user-chip {
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .35rem .9rem .35rem .35rem;
        background: var(--pa-surface);
        border: 1px solid var(--pa-border);
        border-radius: var(--pa-radius-pill);
        box-shadow: var(--pa-shadow-sm);
    }

    .pa-user-chip__avatar {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        background: var(--pa-info-tint);
        color: var(--pa-info-strong);
        font-size: .8125rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .pa-user-chip__name {
        display: block;
        font-size: .8125rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .pa-user-chip__role {
        display: block;
        font-size: .6875rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--pa-text-muted);
    }

    /* Same visual language as .pa-session__logout on /mi-cuenta. */
    .pa-logout {
        appearance: none;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        border: 1px solid var(--pa-border);
        background: var(--pa-surface);
        color: var(--pa-text-muted);
        border-radius: var(--pa-radius-pill);
        padding: .45rem 1rem;
        font-size: .8125rem;
        line-height: 1.4;
        box-shadow: var(--pa-shadow-sm);
        transition: color .15s ease, border-color .15s ease, background-color .15s ease;
    }

    .pa-logout:hover {
        color: var(--pa-text);
        border-color: #c9d1dc;
        background: #fbfcfd;
    }

    /* ============================================================
       05 · Stat cards
       Each modifier only remaps the local accent vars — the same
       pattern as .pa-bar--on_time on /mi-cuenta, so adding a status
       later is one CSS block and no new PHP→class mapping.
       ============================================================ */
    .pa-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .pa-stat {
        appearance: none;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        width: 100%;
        text-align: left;
        padding: 1rem 1rem 0;
        background: var(--pa-surface);
        border: 1px solid var(--pa-border);
        border-radius: var(--pa-radius-lg);
        box-shadow: var(--pa-shadow-card);
        overflow: hidden;
        cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;

        --pa-accent-strong: var(--pa-neutral-strong);
        --pa-accent-tint: var(--pa-neutral-tint);
        --pa-accent-border: var(--pa-neutral-border);
    }

    .pa-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 1px 3px rgba(16, 24, 40, .05), 0 16px 30px -14px rgba(16, 24, 40, .28);
    }

    /* The active filter is signalled by a ring as well as by hue. */
    .pa-stat.is-active {
        border-color: var(--pa-accent-border);
        box-shadow: var(--pa-shadow-card), inset 0 0 0 2px var(--pa-accent-strong);
    }

    .pa-stat--on_time {
        --pa-accent-strong: var(--pa-on-time-strong);
        --pa-accent-tint: var(--pa-on-time-tint);
        --pa-accent-border: var(--pa-on-time-border);
    }

    .pa-stat--due_soon {
        --pa-accent-strong: var(--pa-due-soon-strong);
        --pa-accent-tint: var(--pa-due-soon-tint);
        --pa-accent-border: var(--pa-due-soon-border);
    }

    .pa-stat--overdue {
        --pa-accent-strong: var(--pa-overdue-strong);
        --pa-accent-tint: var(--pa-overdue-tint);
        --pa-accent-border: var(--pa-overdue-border);
    }

    .pa-stat--info {
        --pa-accent-strong: var(--pa-info-strong);
        --pa-accent-tint: var(--pa-info-tint);
        --pa-accent-border: var(--pa-info-border);
    }

    .pa-stat__icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.5rem;
        height: 2.5rem;
        margin-bottom: .7rem;
        border-radius: var(--pa-radius-tile);
        background: var(--pa-accent-tint);
        color: var(--pa-accent-strong);
    }

    /* Uppercased here, never in PHP — PaymentStatus::label() must keep
       returning "Pago al día" for the feature tests. */
    .pa-stat__label {
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--pa-text-muted);
    }

    .pa-stat__value {
        margin-top: .2rem;
        font-size: 1.85rem;
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -.02em;
        color: var(--pa-accent-strong);
        font-variant-numeric: tabular-nums;
    }

    .pa-stat__meta {
        margin-top: .1rem;
        font-size: .75rem;
        color: var(--pa-text-muted);
    }

    .pa-stat__foot {
        display: flex;
        align-items: center;
        gap: .4rem;
        width: calc(100% + 2rem);
        margin: .9rem -1rem 0;
        padding: .55rem 1rem;
        background: var(--pa-accent-tint);
        border-top: 1px solid var(--pa-accent-border);
        font-size: .75rem;
        color: var(--pa-accent-strong);
    }

    /* ============================================================
       06 · Cards
       ============================================================ */
    .pa-card {
        background: var(--pa-surface);
        border: 1px solid var(--pa-border);
        border-radius: var(--pa-radius-lg);
        box-shadow: var(--pa-shadow-card);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }

    .pa-card__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: .75rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--pa-border);
    }

    .pa-card__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
    }

    .pa-card__tools {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .4rem;
    }

    .pa-card__body {
        padding: 1.25rem;
    }

    .pa-card__body--flush {
        padding: 0;
    }

    .pa-card__foot {
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--pa-border);
    }

    /* ============================================================
       07 · Filter pills and text links
       ============================================================ */
    .pa-pill {
        appearance: none;
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .3rem .8rem;
        border: 1px solid transparent;
        border-radius: var(--pa-radius-pill);
        background: var(--pa-accent-tint, var(--pa-neutral-tint));
        color: var(--pa-accent-strong, var(--pa-neutral-strong));
        font-size: .75rem;
        font-weight: 600;
        line-height: 1.5;
        transition: box-shadow .15s ease, filter .15s ease;

        --pa-accent-strong: var(--pa-neutral-strong);
        --pa-accent-tint: var(--pa-neutral-tint);
        --pa-accent-border: var(--pa-neutral-border);
    }

    .pa-pill:hover {
        filter: brightness(.97);
    }

    .pa-pill.is-active {
        box-shadow: inset 0 0 0 1px var(--pa-accent-strong);
    }

    .pa-pill__count {
        opacity: .7;
        font-variant-numeric: tabular-nums;
    }

    .pa-pill--on_time {
        --pa-accent-strong: var(--pa-on-time-strong);
        --pa-accent-tint: var(--pa-on-time-tint);
        --pa-accent-border: var(--pa-on-time-border);
    }

    .pa-pill--due_soon {
        --pa-accent-strong: var(--pa-due-soon-strong);
        --pa-accent-tint: var(--pa-due-soon-tint);
        --pa-accent-border: var(--pa-due-soon-border);
    }

    .pa-pill--overdue {
        --pa-accent-strong: var(--pa-overdue-strong);
        --pa-accent-tint: var(--pa-overdue-tint);
        --pa-accent-border: var(--pa-overdue-border);
    }

    .pa-link {
        appearance: none;
        border: 0;
        background: transparent;
        padding: .3rem .4rem;
        color: var(--pa-info-strong);
        font-size: .8125rem;
        font-weight: 600;
    }

    .pa-link:hover {
        text-decoration: underline;
    }

    /* ============================================================
       08 · Buttons (overrides of Bootstrap, no markup change)
       ============================================================ */
    .pa-main .btn {
        border-radius: var(--pa-radius-sm);
        font-size: .8125rem;
        font-weight: 600;
        padding: .45rem 1rem;
        box-shadow: none;
        transition: filter .15s ease, background-color .15s ease,
                    border-color .15s ease, color .15s ease;
    }

    .pa-main .btn-sm {
        font-size: .75rem;
        padding: .3rem .7rem;
    }

    .pa-main .btn:active {
        transform: translateY(1px);
    }

    .pa-main .btn:disabled,
    .pa-main .btn.disabled {
        opacity: .6;
    }

    .pa-main .btn-primary {
        background-color: var(--pa-info-strong);
        border-color: var(--pa-info-strong);
        color: #fff;
    }

    .pa-main .btn-primary:hover {
        background-color: #163bb0;
        border-color: #163bb0;
    }

    .pa-main .btn-primary:focus {
        box-shadow: 0 0 0 3px var(--pa-info-tint);
    }

    .pa-main .btn-success {
        background-color: var(--pa-on-time-strong);
        border-color: var(--pa-on-time-strong);
        color: #fff;
    }

    .pa-main .btn-success:hover {
        filter: brightness(.92);
    }

    .pa-main .btn-success:focus {
        box-shadow: 0 0 0 3px var(--pa-on-time-tint);
    }

    .pa-main .btn-danger {
        background-color: var(--pa-overdue-strong);
        border-color: var(--pa-overdue-strong);
        color: #fff;
    }

    .pa-main .btn-danger:hover {
        filter: brightness(.92);
    }

    .pa-main .btn-danger:focus {
        box-shadow: 0 0 0 3px var(--pa-overdue-tint);
    }

    .pa-main .btn-outline-primary,
    .pa-main .btn-outline-secondary,
    .pa-main .btn-outline-danger,
    .pa-main .btn-outline-success,
    .pa-main .btn-outline-dark {
        background-color: var(--pa-surface);
        border-color: var(--pa-border);
        color: var(--pa-text-muted);
    }

    .pa-main .btn-outline-primary:hover {
        background-color: var(--pa-info-tint);
        border-color: var(--pa-info-border);
        color: var(--pa-info-strong);
    }

    .pa-main .btn-outline-secondary:hover,
    .pa-main .btn-outline-dark:hover {
        background-color: var(--pa-neutral-tint);
        border-color: var(--pa-neutral-border);
        color: var(--pa-text);
    }

    .pa-main .btn-outline-danger:hover {
        background-color: var(--pa-overdue-tint);
        border-color: var(--pa-overdue-border);
        color: var(--pa-overdue-strong);
    }

    .pa-main .btn-outline-success:hover {
        background-color: var(--pa-on-time-tint);
        border-color: var(--pa-on-time-border);
        color: var(--pa-on-time-strong);
    }

    .pa-main .btn-link {
        color: var(--pa-info-strong);
        font-weight: 600;
    }

    /* ============================================================
       09 · Badges as soft pills
       The class names come from App\Enums\PaymentStatus::badgeClass();
       .badge-orange has no Bootstrap 4 equivalent, which is why it is
       defined here rather than being a stock variant.
       ============================================================ */
    .pa-main .badge {
        border: 1px solid transparent;
        border-radius: var(--pa-radius-pill);
        padding: .25rem .6rem;
        font-size: .6875rem;
        font-weight: 600;
        line-height: 1.5;
    }

    .pa-main .badge-success {
        color: var(--pa-on-time-strong);
        background-color: var(--pa-on-time-tint);
        border-color: var(--pa-on-time-border);
    }

    .pa-main .badge-orange {
        color: var(--pa-due-soon-strong);
        background-color: var(--pa-due-soon-tint);
        border-color: var(--pa-due-soon-border);
    }

    .pa-main .badge-danger {
        color: var(--pa-overdue-strong);
        background-color: var(--pa-overdue-tint);
        border-color: var(--pa-overdue-border);
    }

    .pa-main .badge-primary,
    .pa-main .badge-info {
        color: var(--pa-info-strong);
        background-color: var(--pa-info-tint);
        border-color: var(--pa-info-border);
    }

    .pa-main .badge-secondary,
    .pa-main .badge-light {
        color: var(--pa-neutral-strong);
        background-color: var(--pa-neutral-tint);
        border-color: var(--pa-neutral-border);
    }

    /* Darker than the status badges on purpose: "Suspendido" sits next
       to one and has to out-rank it. */
    .pa-main .badge-dark {
        color: var(--pa-dark-strong);
        background-color: var(--pa-dark-tint);
        border-color: var(--pa-dark-border);
    }

    /* ============================================================
       10 · Alerts as soft panels
       .alert-orange comes from PaymentStatus::alertClass(); see above.
       ============================================================ */
    .pa-main .alert {
        border-radius: var(--pa-radius);
        border: 1px solid transparent;
        padding: .9rem 1.1rem;
        font-size: .875rem;
    }

    .pa-main .alert-heading {
        font-size: 1rem;
        font-weight: 700;
        color: inherit;
    }

    .pa-main .alert-success {
        color: var(--pa-on-time-strong);
        background-color: var(--pa-on-time-tint);
        border-color: var(--pa-on-time-border);
    }

    .pa-main .alert-orange,
    .pa-main .alert-warning {
        color: var(--pa-due-soon-strong);
        background-color: var(--pa-due-soon-tint);
        border-color: var(--pa-due-soon-border);
    }

    .pa-main .alert-danger {
        color: var(--pa-overdue-strong);
        background-color: var(--pa-overdue-tint);
        border-color: var(--pa-overdue-border);
    }

    .pa-main .alert-info {
        color: var(--pa-info-strong);
        background-color: var(--pa-info-tint);
        border-color: var(--pa-info-border);
    }

    .pa-main .alert-secondary {
        color: var(--pa-neutral-strong);
        background-color: var(--pa-neutral-tint);
        border-color: var(--pa-neutral-border);
    }

    /* ============================================================
       11 · Tables
       ============================================================ */
    .pa-table.table {
        margin-bottom: 0;
        color: var(--pa-text);
    }

    .pa-table.table thead th {
        border-top: 0;
        border-bottom: 1px solid var(--pa-border);
        padding: .75rem 1.25rem;
        background: transparent;
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: var(--pa-text-muted);
    }

    .pa-table.table tbody td {
        border-top: 0;
        border-bottom: 1px solid var(--pa-border);
        padding: .9rem 1.25rem;
        vertical-align: middle;
        font-size: .875rem;
    }

    .pa-table.table tbody tr:last-child > td {
        border-bottom: 0;
    }

    .pa-table.table-hover tbody tr:hover {
        background-color: #fafbfd;
    }

    /* Bootstrap 4 sets .table-active / .table-warning backgrounds with
       !important, so matching them needs !important too. */
    .pa-table.table tbody tr.table-active > td,
    .pa-table.table-hover tbody tr.table-active:hover > td {
        background-color: var(--pa-info-tint) !important;
    }

    .pa-table.table tbody tr.table-active > td:first-child {
        box-shadow: inset 3px 0 0 var(--pa-info-strong);
    }

    .pa-table.table tbody tr.table-warning > td {
        background-color: var(--pa-due-soon-tint) !important;
    }

    /* The inline confirm / assign expansion rows. */
    .pa-table.table tbody tr.bg-light > td {
        background-color: #f8fafc !important;
    }

    .pa-due {
        font-weight: 600;
    }

    .pa-due--on_time {
        color: var(--pa-on-time-strong);
    }

    .pa-due--due_soon {
        color: var(--pa-due-soon-strong);
    }

    .pa-due--overdue {
        color: var(--pa-overdue-strong);
    }

    /* ============================================================
       12 · Forms
       The bank <select> and the cartola file input stay native: no JS
       dropdown exists, and wire:model="cartola" needs a real file input.
       ============================================================ */
    .pa-main label {
        font-size: .8125rem;
        font-weight: 600;
    }

    .pa-main .form-control {
        border-radius: var(--pa-radius-sm);
        border-color: var(--pa-border);
        padding: .5rem .75rem;
        height: auto;
        font-size: .875rem;
        color: var(--pa-text);
        box-shadow: none;
    }

    .pa-main .form-control:focus {
        border-color: var(--pa-info-strong);
        box-shadow: 0 0 0 3px var(--pa-info-tint);
    }

    .pa-main .form-control::placeholder {
        color: #9aa5b4;
    }

    .pa-main .form-control.is-invalid {
        border-color: var(--pa-overdue-border);
        background-color: var(--pa-overdue-tint);
    }

    .pa-main .form-control.is-invalid:focus {
        border-color: var(--pa-overdue-strong);
        box-shadow: 0 0 0 3px var(--pa-overdue-tint);
    }

    .pa-main .invalid-feedback {
        font-size: .8125rem;
        color: var(--pa-overdue-strong);
    }

    .pa-main .input-group > .form-control {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }

    .pa-main .input-group-append .btn {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }

    /* ============================================================
       13 · Tabs (Livewire-driven links, no Bootstrap JS)
       ============================================================ */
    .pa-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        list-style: none;
        margin: 0 0 1.25rem;
        padding: 0;
    }

    .pa-tabs__link {
        display: inline-flex;
        align-items: center;
        padding: .45rem 1rem;
        border: 1px solid transparent;
        border-radius: var(--pa-radius-pill);
        color: var(--pa-text-muted);
        font-size: .8125rem;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .15s ease, border-color .15s ease, color .15s ease;
    }

    .pa-tabs__link:hover {
        background: var(--pa-surface);
        border-color: var(--pa-border);
        color: var(--pa-text);
        text-decoration: none;
    }

    .pa-tabs__link.is-active {
        background: var(--pa-surface);
        border-color: var(--pa-border);
        color: var(--pa-text);
        box-shadow: var(--pa-shadow-sm);
    }

    .pa-tabs__link .badge {
        margin-left: .35rem;
    }

    /* ============================================================
       14 · Pagination (vendor markup — CSS only)
       ============================================================ */
    .pa-card .pagination {
        margin: 0;
        flex-wrap: wrap;
        gap: .25rem;
    }

    .pa-card .page-link {
        margin: 0;
        border: 1px solid var(--pa-border);
        border-radius: var(--pa-radius-sm);
        background: var(--pa-surface);
        color: var(--pa-text-muted);
        font-size: .8125rem;
        padding: .35rem .7rem;
    }

    .pa-card .page-link:hover {
        background: var(--pa-neutral-tint);
        border-color: var(--pa-neutral-border);
        color: var(--pa-text);
    }

    .pa-card .page-link:focus {
        box-shadow: 0 0 0 3px var(--pa-info-tint);
    }

    .pa-card .page-item.active .page-link {
        background: var(--pa-info-strong);
        border-color: var(--pa-info-strong);
        color: #fff;
    }

    .pa-card .page-item.disabled .page-link {
        opacity: .5;
        background: var(--pa-surface);
    }

    /* Laravel's "Mostrando X a Y de Z resultados" line and its mobile block. */
    .pa-card .pagination + p,
    .pa-card nav p {
        margin: 0;
        font-size: .8125rem;
        color: var(--pa-text-muted);
    }

    /* ============================================================
       15 · Footer
       ============================================================ */
    .pa-foot {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        padding: 2rem 0 .5rem;
        font-size: .75rem;
        color: var(--pa-text-muted);
    }

    /* ============================================================
       16 · Responsive
       The sidebar reflows into a horizontal strip with a media query
       alone — there is no Bootstrap JS in this app, so no toggle.
       ============================================================ */
    @media (max-width: 1199px) {
        .pa-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 991px) {
        .pa-sidebar {
            position: static;
            width: auto;
            flex-direction: row;
            align-items: center;
            gap: .5rem;
            padding: .6rem .9rem;
            overflow-x: auto;
        }

        .pa-sidebar__brand {
            padding: 0 .75rem 0 .25rem;
            border-bottom: 0;
            border-right: 1px solid var(--pa-nav-border);
            flex: 0 0 auto;
        }

        .pa-sidebar__nav {
            flex-direction: row;
            gap: .4rem;
        }

        .pa-nav-item {
            white-space: nowrap;
        }

        .pa-sidebar__spacer,
        .pa-promo {
            display: none;
        }

        .pa-main {
            margin-left: 0;
            padding: 1.25rem 1rem 2.5rem;
        }
    }

    @media (max-width: 575px) {
        .pa-stats {
            grid-template-columns: 1fr;
        }

        .pa-header,
        .pa-card__header {
            flex-direction: column;
            align-items: flex-start;
        }

        .pa-card__body {
            padding: 1rem;
        }

        .pa-table.table thead th,
        .pa-table.table tbody td {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .pa-shell * {
            transition: none !important;
        }

        .pa-stat:hover,
        .pa-main .btn:active {
            transform: none;
        }
    }
</style>
