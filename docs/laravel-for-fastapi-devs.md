# Laravel + Livewire, explained through this project

A guide for developers who know **FastAPI**, **Spring Boot**, and some **React**, but no PHP. Every concept is illustrated with real code from this repo's *Payment Alert* module — a small feature that looks up a customer by Chilean RUT (tax ID) or numeric ID and shows a colored alert with their payment status.

---

## 1. The 30-second mental model

**Laravel is PHP's Spring Boot, not its FastAPI.** It's a batteries-included MVC framework: dependency-injection container, ORM (Eloquent), template engine (Blade), config system, validation, migrations, CLI tooling — all in the box. FastAPI gives you routing + validation and lets you pick the rest; Laravel picks everything for you, with strong conventions.

One difference from *both* frameworks you know: **classic PHP has no long-running application process.** Spring Boot and FastAPI boot once and serve thousands of requests from memory. PHP (under Apache/nginx + PHP-FPM) boots the entire framework fresh for *every* HTTP request, handles it, and throws everything away. This explains a lot of Laravel's design:

- Nothing survives between requests in memory — state lives in the database, the session, or (as you'll see with Livewire) serialized into the page itself.
- Global-looking helpers like `config()` and `view()` are cheap and idiomatic because there's no shared mutable state to worry about.
- "Facades" (`Route::get(...)`) look like static calls but are really proxies into the per-request DI container — think of them as a static gateway to a Spring bean.

| | Spring Boot | FastAPI | Laravel |
|---|---|---|---|
| Language | Java | Python | PHP |
| Process model | Long-running JVM | Long-running ASGI server | Fresh boot per request |
| Philosophy | Batteries-included, DI-centric | Micro, bring-your-own | Batteries-included, convention-centric |

---

## 2. Project layout tour

Laravel's directory structure is fixed by convention (like Maven's `src/main/java`, unlike FastAPI's free-form layout). The files that matter in this module:

```
routes/web.php                                ← URL → handler mapping (all in one file)
app/Http/Livewire/PaymentAlert.php            ← the UI component (controller + state)
app/Models/Customer.php                       ← ORM model (entity + repository in one)
app/Enums/PaymentStatus.php                   ← plain domain logic
app/Support/Rut.php                           ← plain helper class
config/payment_alert.php                      ← typed config, reads .env
resources/views/payment-alert-page.blade.php  ← full-page template
resources/views/livewire/payment-alert.blade.php ← the component's template
tests/Unit/PaymentStatusTest.php              ← PHPUnit test
vendor/                                       ← dependencies (≈ node_modules / .m2)
```

Rosetta stone:

| Laravel | Spring Boot | FastAPI |
|---|---|---|
| `routes/web.php` | `@RequestMapping` annotations | `@app.get(...)` decorators |
| `app/Models/` (Eloquent) | JPA `@Entity` + `Repository` | SQLAlchemy models |
| `app/Http/` | `@Controller` classes | path-operation functions |
| `config/*.php` + `.env` | `application.yml` + `@ConfigurationProperties` | pydantic `Settings` + `.env` |
| `resources/views/` (Blade) | Thymeleaf templates | Jinja2 templates |
| `composer.json` / `vendor/` | `pom.xml` / `.m2` | `requirements.txt` / site-packages |
| `php artisan ...` | `./mvnw ...` | `uvicorn` / `alembic` / ad-hoc |
| PHPUnit | JUnit | pytest |

### Routing

All web routes live in one file, `routes/web.php`:

```php
Route::get('/', function () {
    return view('welcome');
});

Route::view('/payment-alert', 'payment-alert-page');
```

`Route::get` takes a URL and a handler — here a closure, but usually a controller method (like `@GetMapping` pointing at a handler). `Route::view` is a shortcut for "this URL just renders this template, no logic." The string `'payment-alert-page'` resolves by convention to `resources/views/payment-alert-page.blade.php`.

---

## 3. Eloquent: the ORM (Active Record, not Data Mapper)

This is the biggest conceptual shift from JPA/SQLAlchemy. Eloquent is an **Active Record** ORM: the model class is the entity *and* the repository *and* the query builder, all at once. There is no separate `CustomerRepository`.

`app/Models/Customer.php`:

```php
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'empresas';

    protected $casts = [
        'proximoPago' => 'date',
    ];
    // ...
}
```

Things to notice, in order of surprise:

**No field declarations.** A JPA entity or pydantic model declares every column. An Eloquent model declares *none* — at runtime, Laravel reads whatever columns the row has and exposes them as properties (`$customer->id`, `$customer->rut`, `$customer->proximoPago`). The class only declares *exceptions* to convention:

- `$table = 'empresas'` — by convention `Customer` would map to a `customers` table; this overrides it because the real production table is `empresas`.
- `$casts` — tells Eloquent to convert the `proximoPago` column to a `Carbon` date object (Carbon ≈ Java's `LocalDate`/`Instant` with a fluent API) instead of a raw string.
- `use SoftDeletes;` — a **trait** (PHP's mixins, ≈ interface default methods). It makes deletes set a `deleted_at` timestamp instead of removing rows, and silently adds `WHERE deleted_at IS NULL` to every query.

**Accessors: computed properties via naming magic.** Define `getDaysPastDueAttribute()` and Eloquent exposes it as the snake_case property `$customer->days_past_due` — like a Python `@property` or a Kotlin computed getter, wired up purely by method-name convention:

```php
public function getDaysPastDueAttribute(): ?int
{
    if ($this->payment_date === null) {
        return null;
    }

    return (int) $this->payment_date->startOfDay()->diffInDays(Carbon::today(), false);
}
```

(`?int` is a nullable return type, ≈ `Optional[int]`.) This model chains accessors: `payment_date` is itself an accessor aliasing the Spanish column `proximoPago`, and `payment_status` builds on `days_past_due`. The Blade template later reads `$customer->payment_status` as if it were a column.

**Query scopes: reusable query fragments.** Define `scopeByRutOrId(...)` and you can call `Customer::byRutOrId($term)` — comparable to a Spring Data derived query method or a reusable SQLAlchemy filter function:

```php
public function scopeByRutOrId(Builder $query, string $term): Builder
{
    if (ctype_digit($term) && strlen($term) <= 6) {
        return $query->where('id', (int) $term);
    }

    return $query->whereRaw(
        "UPPER(REPLACE(REPLACE(REPLACE(rut, '.', ''), '-', ''), ' ', '')) = ?",
        [Rut::normalize($term)]
    );
}
```

The `Builder` it returns is a lazy query object (≈ JPA `CriteriaQuery`, SQLAlchemy `Query`): nothing hits the database until a terminal call like `->first()` (one row or `null`) or `->get()` (all rows). Note the parameterized `?` placeholder in `whereRaw` — same SQL-injection protection you'd expect anywhere.

> One project-specific convention: "numeric and ≤ 6 digits means ID, otherwise RUT" is duplicated in this scope and in the Livewire component's `lookup()`. If you ever change it, change both.

---

## 4. Livewire: the part with no direct analog

Livewire is the piece that will feel strangest, so here is the mental model up front:

> **Livewire is React components where the state and the event handlers live on the server, in PHP.**

You write a component class (state + methods) and a template. Livewire renders it server-side into the page. When the user interacts, JavaScript (shipped by `@livewireScripts`) sends an AJAX request carrying the component's serialized state, the server re-instantiates the component, runs your method, re-renders the template, and sends back HTML that gets **diffed into the DOM** — like React's virtual-DOM reconciliation, except the "re-render" happened on the server. You write zero JavaScript.

This is the same idea as Phoenix LiveView or Blazor Server (though Livewire 2 uses request/response AJAX, not a WebSocket). Remember the PHP process model from section 1: the server keeps *nothing* in memory between interactions, so the component's state makes a full round-trip inside every request.

### The component class

`app/Http/Livewire/PaymentAlert.php`:

```php
class PaymentAlert extends Component
{
    public $search = '';
    public $customer = null;
    public $notFound = false;

    protected $rules = [
        'search' => 'required|string|max:20',
    ];

    protected $messages = [
        'search.required' => 'Ingrese un RUT o ID de cliente.',
    ];

    public function lookup(): void
    {
        $this->validate();

        $term = trim($this->search);

        if (!(ctype_digit($term) && strlen($term) <= 6) && !Rut::isValid($term)) {
            $this->customer = null;
            $this->notFound = false;
            $this->addError('search', 'El RUT ingresado no es válido.');

            return;
        }

        $this->customer = Customer::byRutOrId($term)->first();
        $this->notFound = $this->customer === null;
    }

    public function updatedSearch(): void
    {
        $this->customer = null;
        $this->notFound = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.payment-alert');
    }
}
```

Mapping to what you know:

| Livewire | React equivalent |
|---|---|
| `public $search = ''` | `const [search, setSearch] = useState('')` — **public properties are the reactive state** |
| `lookup()` | an event handler — but it runs on the server |
| `updatedSearch()` | a watcher on `search` (≈ `useEffect(..., [search])`) — Livewire calls `updatedX()` by naming convention whenever property `$x` changes from the browser |
| `render()` | the render function; returns which template to draw, with all public properties automatically in scope |

**Validation** is declarative: `$rules` uses Laravel's compact rule strings (`'required|string|max:20'` ≈ pydantic `Field(..., max_length=20)` or Bean Validation `@NotBlank @Size(max=20)`), `$messages` overrides the error text (user-facing strings in this project are Spanish). `$this->validate()` throws on failure, and the error lands in an "error bag" that the template reads with `@error`. `addError()` puts a custom error in the same bag — used here to reject a RUT with a bad check digit (`Rut::isValid()` does modulo-11 validation in plain PHP, see `app/Support/Rut.php`) *before* touching the database.

### The component template

`resources/views/livewire/payment-alert.blade.php` (abridged):

```blade
<form wire:submit.prevent="lookup">
    <input
        type="text"
        class="form-control @error('search') is-invalid @enderror"
        wire:model.defer="search"
    >
    <button type="submit" class="btn btn-primary">
        <span wire:loading.remove wire:target="lookup">Buscar</span>
        <span wire:loading wire:target="lookup">Buscando…</span>
    </button>
    @error('search')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</form>
```

The `wire:` attributes are Livewire's directives:

- `wire:model.defer="search"` — two-way binding to the `$search` property, like a controlled input (`value={search} onChange={...}`). The `.defer` modifier means "don't sync on every keystroke; batch the value with the next action" — without it, every keystroke would be an AJAX request.
- `wire:submit.prevent="lookup"` — on submit, `preventDefault()` and call the **server-side** `lookup()` method.
- `wire:loading` / `wire:loading.remove` with `wire:target="lookup"` — automatic pending-state UI: while the `lookup` request is in flight, show "Buscando…" and hide "Buscar". This is React's `isPending`/disabled-button pattern, for free.

After `lookup()` runs, the template re-renders with the updated `$customer`/`$notFound` and the alert appears:

```blade
@if ($customer)
    <div class="alert {{ \App\Enums\PaymentStatus::alertClass($status) }} mb-0" role="alert">
        <h5 class="alert-heading mb-2">{{ \App\Enums\PaymentStatus::label($status) }}</h5>
        ...
    </div>
@endif
```

### Mounting the component on a page

`resources/views/payment-alert-page.blade.php` is a plain HTML shell that hosts the component:

```blade
<head>
    <link rel="stylesheet" href="...bootstrap.min.css">
    @livewireStyles
</head>
<body class="bg-light">
    <livewire:payment-alert />
    @livewireScripts
</body>
```

`<livewire:payment-alert />` looks like JSX but is resolved server-side: the tag name kebab-cases to the `PaymentAlert` class. `@livewireStyles` / `@livewireScripts` inject Livewire's CSS and its JavaScript runtime — the runtime is what watches the `wire:` attributes and does the AJAX + DOM diffing.

> **Version note:** this project uses **Livewire 2** (paired with Laravel 9). Livewire 3 renamed things — e.g. `wire:model.defer` became the default behavior of plain `wire:model`. If you read the current Livewire docs online, check the version switcher.

---

## 5. Blade in two minutes

Blade is Laravel's template engine — closest to Jinja2/Thymeleaf, philosophically opposite to JSX (logic lives in directives inside HTML, not HTML inside code).

| Syntax | Meaning |
|---|---|
| `{{ $customer->name }}` | Echo, **HTML-escaped by default** (like JSX `{}` , unlike raw string concat) |
| `@if (...) ... @elseif ... @else ... @endif` | Conditionals |
| `@error('search') ... {{ $message }} ... @enderror` | Renders only if the validation error bag has an entry for `search` |
| `@php ... @endphp` | Escape hatch for inline PHP (used in the component view to avoid repeating `$customer->payment_status`) |
| `{{-- comment --}}` | Template comment (never sent to the browser) |

Templates are compiled to plain PHP and cached, so there's no per-request parsing cost.

---

## 6. Configuration and environment

Config in Laravel is layered exactly like Spring's `application.yml` + environment overrides:

`config/payment_alert.php`:

```php
return [
    'due_soon_days' => env('PAYMENT_ALERT_DUE_SOON_DAYS', 3),
];
```

- Each file in `config/` returns a plain PHP array. `env('KEY', default)` reads the `.env` file (same convention as FastAPI projects).
- Anywhere in the app, `config('payment_alert.due_soon_days', 3)` reads it — the key is `<filename>.<array key>` (≈ `@Value("${payment-alert.due-soon-days}")`).
- Rule of thumb: **`env()` only inside `config/` files**; application code calls `config()`. (Laravel can cache all config into one file for speed, after which `env()` returns nothing elsewhere.)

`app/Enums/PaymentStatus.php` shows a testability trick worth noticing:

```php
public static function fromDaysPastDue(int $days, ?int $dueSoonDays = null): string
{
    $dueSoonDays = $dueSoonDays ?? (int) config('payment_alert.due_soon_days', 3);
    // on_time (≤ -(due_soon_days + 1)) → due_soon (-due_soon_days .. 0) → overdue (> 0, no cutoff)
}
```

Thresholds default to config, but callers can pass them explicitly. That's how `tests/Unit/PaymentStatusTest.php` tests the logic as a pure function without booting the Laravel container — the same motivation as constructor injection in Spring ("don't reach into the environment; accept dependencies as parameters").

Why is `PaymentStatus` a class full of `const` strings instead of an enum? PHP only got native enums in 8.1, and this module must run on PHP 8.0 (Laravel 9's minimum). So it's the pre-8.1 idiom: constants plus static lookup methods (`label()`, `alertClass()`) that map status → Spanish label / Bootstrap CSS class using an array literal as a map.

---

## 7. PHP syntax survival kit

The symbols you'll trip on, decoded for a Java/Python reader:

| PHP | Meaning | You know it as |
|---|---|---|
| `$search` | Every variable starts with `$` | — |
| `$this->search` | Instance member access | `this.search` / `self.search` |
| `Customer::byRutOrId(...)` | `::` = static access | `Customer.byRutOrId(...)` |
| `$customer->name` | `->` = instance access | `customer.name` |
| `namespace App\Models;` + `use App\Support\Rut;` | Packages + imports (autoloaded from file path by Composer) | `package`/`import` |
| `?int`, `?Carbon` | Nullable type | `Optional[int]`, `Integer` |
| `$a ?? $b` | Null coalescing | `a if a is not None else b` / `Optional.orElse` |
| `['a' => 1]['a']` | Arrays are also ordered maps | `dict` / `LinkedHashMap` |
| `use SoftDeletes;` *inside a class* | Trait = mixin | interface default methods / Python mixins |
| `fn`-less `function lookup(): void` | `void`/typed returns | same idea as Java/typed Python |
| `'required|string|max:20'` | Laravel's compact validation DSL | `@NotBlank @Size(max=20)` |

One PHP-specific gotcha visible in `PaymentAlert::lookup()`: string/number juggling. `ctype_digit($term)` checks "is this string all digits" and `(int) $term` casts — PHP strings don't auto-become numbers in comparisons the way you might hope, so explicit checks/casts like these are idiomatic.

---

## 8. End-to-end: one request, traced

What actually happens when a user visits the page, types `12.345.678-5`, and clicks **Buscar**:

1. **GET `/payment-alert`** → `routes/web.php` matches `Route::view(...)` → Blade renders `payment-alert-page.blade.php`. The `<livewire:payment-alert />` tag instantiates `PaymentAlert` (initial state: `$search = ''`), renders `livewire/payment-alert.blade.php`, and embeds the HTML plus a serialized snapshot of the component state into the page. Response sent; the PHP process forgets everything.
2. **User types.** Nothing happens over the network — `wire:model.defer` holds the value client-side.
3. **User clicks Buscar.** `wire:submit.prevent="lookup"` fires. Livewire's JS POSTs an AJAX request containing the state snapshot + "call `lookup`". The button swaps to "Buscando…" via `wire:loading`.
4. **Server re-boots the framework** (new request, remember), rehydrates a `PaymentAlert` with `$search = '12.345.678-5'`, and runs `lookup()`:
   - `$this->validate()` — passes (`required`, ≤ 20 chars).
   - Not a short numeric ID, so `Rut::isValid()` normalizes to `123456785` and verifies the modulo-11 check digit. Invalid → `addError()` and return without any DB query.
   - Valid → `Customer::byRutOrId($term)->first()` runs one SQL query against `empresas`, comparing normalized RUTs.
5. **Re-render.** `render()` draws the template with the new state. Blade reads the accessor chain: `$customer->payment_status` → `days_past_due` (Carbon date math on `proximoPago`) → `PaymentStatus::fromDaysPastDue()` (thresholds from config) → `alertClass()`/`label()` pick the Bootstrap color and Spanish text.
6. **Response** carries the fresh HTML + updated state snapshot. Livewire's JS diffs the HTML into the DOM — the green/orange/red alert appears without a page reload.

Every later interaction repeats steps 3–6.

---

## 9. Quick-reference table

| Concept | Laravel / Livewire | Spring Boot | FastAPI / React |
|---|---|---|---|
| ORM | Eloquent (Active Record) | JPA/Hibernate (Data Mapper) | SQLAlchemy |
| Entity + queries | one `Model` class | `@Entity` + `Repository` | model + session queries |
| Computed field | `getXAttribute()` accessor | getter on entity | `@property` / pydantic `computed_field` |
| Reusable query | scope (`scopeByRutOrId`) | derived query method | filter helper function |
| Templates | Blade | Thymeleaf | Jinja2 / JSX |
| Interactive UI | Livewire component | (no analog; ≈ Blazor Server) | React component, but server-side |
| Component state | public properties | — | `useState` |
| Validation | `$rules` DSL + error bag | Bean Validation | pydantic |
| Config | `config/*.php` + `.env` | `application.yml` | pydantic `Settings` |
| DI container | service container + facades | ApplicationContext | `Depends()` |
| CLI | `php artisan` | `./mvnw` | `uvicorn` / `typer` |
| Deps | Composer / `vendor/` | Maven / Gradle | pip / venv |
| Tests | PHPUnit (`php artisan test`) | JUnit | pytest |
| Soft delete | `SoftDeletes` trait | `@SQLDelete` + `@Where` | manual filter |
| Date library | Carbon | `java.time` | `datetime` + dateutil |

---

## Where to go next

- Official docs (mind the versions — this project is Laravel **9** and Livewire **2**): [laravel.com/docs/9.x](https://laravel.com/docs/9.x) and [laravel-livewire.com/docs/2.x](https://laravel-livewire.com/docs/2.x).
- To poke at the logic without a browser, `tests/Unit/PaymentStatusTest.php` runs as pure PHP: `php artisan test --filter=PaymentStatusTest`.
