# Integración del módulo de Pagos en el sistema de db2026

**Documento único y autoritativo.** Reemplaza a `docs/integration-plan.md`, que quedó
superado (ver el aviso en ese archivo).

**Nota previa sobre la documentación del repo:** `CLAUDE.md` manda. `AGENTS.md` es una
copia vieja y está equivocado en tres cosas: habla de "cuatro cambios DDL" (son seis
sentencias), omite la tabla `webpay_transactions`, y afirma que "importar nunca mueve
dinero", lo que dejó de ser cierto cuando se agregó la auto-confirmación. No te guíes
por `AGENTS.md`.

Público de este documento: el/la programador(a) que va a recibir este módulo y
fusionarlo en el sistema grande que es dueño de la base de datos MySQL `db2026`.

---

## Índice

1. [Qué se construyó](#1-qué-se-construyó)
2. [El cambio de esquema en db2026 — seis sentencias](#2-el-cambio-de-esquema-en-db2026--seis-sentencias)
3. [Lo que NUNCA se aplica a db2026](#3-lo-que-nunca-se-aplica-a-db2026)
4. [Fusión del código en el sistema anfitrión](#4-fusión-del-código-en-el-sistema-anfitrión)
5. [Cambios de código pendientes antes de salir a producción](#5-cambios-de-código-pendientes-antes-de-salir-a-producción)
6. [Orden de puesta en marcha](#6-orden-de-puesta-en-marcha)
7. [Verificación](#7-verificación)
8. [Preguntas abiertas para el dueño de db2026](#8-preguntas-abiertas-para-el-dueño-de-db2026)
9. [Anexo: captura del estado "antes"](#9-anexo-captura-del-estado-antes)

---

## 1. Qué se construyó

Una app Laravel 9 + Livewire 2 + Bootstrap 4 con tres funcionalidades acopladas entre sí.
Lee y escribe tablas que **ya existen** en `db2026` (`empresas`, `datos_plan`, `users`,
`suscriptor_payments`) y agrega tres tablas propias.

### 1.1 Las tres funcionalidades

| Ruta | Quién entra | Qué hace |
|---|---|---|
| `/payment-alert` | staff (`auth` + `admin`) | Listado paginado de **todos** los clientes con semáforo de estado de pago, contadores por estado, filtro y buscador por RUT o ID. Al hacer clic en una fila se abre el detalle, donde viven pagar / suspender / reactivar. |
| `/mi-cuenta` | cliente (`auth`) | El usuario ve **solo su propia empresa** (`users.empresa_id`, nunca input del usuario) y puede pagar. Sin buscador, sin suspender. |
| `/conciliacion-bancaria` | staff (`auth` + `admin`) | Se sube la cartola exportada del portal del banco y la app calza cada depósito contra un cliente, para que un pago por **transferencia electrónica** actualice el mismo estado que actualiza el flujo Webpay. |

El pago en línea es **Transbank Webpay Plus**. La conciliación existe porque en Chile
ningún banco publica una API de cuentas: Banco de Chile, Santander, BCI, BancoEstado e
Itaú obligan a descargar la cartola desde el portal, así que la entrada es un CSV y el
módulo tiene que ser tolerante con él.

### 1.2 Las siete cosas que hay que entender antes de tocar nada

Esto no se deduce leyendo el código. Si se rompe alguna, se pierde plata o se cobra dos
veces.

**a) `app/Services/RecordPayment.php` es el único camino de escritura de dinero.**
Los dos canales (Webpay y transferencia conciliada) pasan por ahí. Escribe en
`suscriptor_payments`, extiende `empresas.proximoPago` y `datos_plan.fecha_vencimiento`,
y pone `estado = '1'`, todo dentro de un solo `DB::transaction()`. Dos reglas viven solo
ahí: el período nuevo corre desde `max(vencimiento anterior, fecha de pago)` — pagar
antes no te quita los días que te quedaban, y una transferencia que estuvo una semana en
la cola de revisión no le cuesta una semana al cliente — y un `periodo_days` fuera del
rango 1–1095 se trata como dato malo y se reemplaza por 30 (producción tiene `0` y
`99999999`). **Nunca escribas `suscriptor_payments` directamente.**
Del lado de la transferencia, además, ambos llamadores pasan por
`app/Services/ConfirmMovement.php`, para que la guardia de idempotencia tampoco se
duplique.

**b) El retorno de Webpay es un POST *cross-site*, y eso condiciona todo su diseño.**
`app/Http/Controllers/WebpayReturnController.php` es donde Transbank devuelve el
navegador del cliente después del checkout. Ese request:

- **No trae token CSRF.** Por eso `payment-alert/webpay/return` está en `$except` de
  `app/Http/Middleware/VerifyCsrfToken.php`. Sin esa línea, *todo* pago real termina en
  un 419 — después de que la tarjeta fue cobrada.
- **No trae la cookie de sesión.** `config/session.php` usa `same_site => 'lax'`, y una
  cookie Lax no viaja en un POST cross-site. Por eso el pago pendiente vive en la tabla
  `webpay_transactions`, indexado por `buy_order`, y **no** en la sesión.

**No muevas ese estado de vuelta a la sesión y no lo "arregles" poniendo
`same_site => 'none'`** — eso debilita todas las cookies de la app para salvar una ruta.

Consecuencia práctica: **este flujo no se puede verificar sobre `http://localhost`.**
Probarlo de punta a punta exige un `APP_URL` público con HTTPS (ngrok o similar),
incluso contra el ambiente de integración de Transbank.

**c) El retorno tiene cuatro formas y solo una es un pago.**

| Lo que llega | Qué significa | Qué hace el controlador |
|---|---|---|
| `token_ws` solo | checkout terminado | commitea |
| `TBK_TOKEN` presente | el cliente abandonó, posiblemente **después** de autorizar | **nunca commitea** |
| `TBK_ID_SESION` + `TBK_ORDEN_COMPRA` sin token | el formulario expiró | no commitea |
| cualquier otra cosa | — | loguea y se va |

Si commiteas el caso `TBK_TOKEN`, registras un pago que el cliente echó para atrás.

**d) La idempotencia es el UPDATE condicional.** `where('status', 'pending')` dentro de
la transacción — la misma guardia que usa `ConfirmMovement`. Un POST reenviado, una
pestaña refrescada y dos requests concurrentes tienen que producir **un** pago. La
unicidad de `suscriptor_payments.external_reference` es la última red.

Además: un commit que no pudimos completar (`error`) **no es** un rechazo (`declined`).
Después de una excepción en el commit el cobro puede existir, así que ni el texto en
pantalla ni el log pueden sugerir "reintente". Y **nunca loguees la respuesta completa
del commit** — trae `card_detail`. Loguea orden de compra, código de respuesta, estado y
monto.

**e) La auto-confirmación de la conciliación se decide por señales, no por puntaje.**
`ImportCartola::qualifiesForAutoConfirm()` registra el pago solo cuando el mejor
candidato disparó **ambas** señales `rut_in_glosa` y `amount_exact`, **y** no hay empate
(`isAmbiguous` en false). Todo lo demás queda `suggested` esperando un clic humano.
Cuatro detalles son estructurales:

- 80 puntos de señales débiles no son la misma evidencia que un RUT de pagador validado
  más el precio exacto del plan. **No lo reexpreses como un umbral numérico.** El "100%"
  que muestra la UI es `min(100, score)` sobre un máximo de 105 puntos, no una
  probabilidad.
- `amount_exact` es lo que deja afuera una transferencia parcial: `RecordPayment` compra
  un período completo sea cual sea el monto, así que un pago corto tiene que seguir
  siendo una decisión visible.
- Una sola auto-confirmación por empresa por cartola. Un segundo depósito del mismo
  pagador por el mismo monto es tan probablemente un duplicado como un segundo mes.
- Corre **después** de que la transacción de importación commiteó, un movimiento a la
  vez. Si falla, se loguea en `error` y el movimiento queda `suggested`: un depósito que
  ni pagó a un cliente ni pidió revisión es plata fuera de los libros.

La auto-confirmación **es irreversible desde la UI**, igual que cualquier pago
registrado. Por eso la regla es estrecha y por eso existe el interruptor
`BANK_RECON_AUTO_CONFIRM`.

**f) La suspensión es siempre manual y confirmada por staff**, en dos pasos
(clic, luego confirmar). Nunca automática, ni siquiera pasado `overdue_grace_days`.
**No agregues un job agendado que suspenda solo** sin una decisión explícita: puede
cortarle el servicio a clientes que sí pagan.

**g) Deshacer se detiene donde empieza el dinero.** `returnToQueue()` revierte un
movimiento `ignored` o la etiqueta automática de Transbank (ninguno escribió nada), y se
niega cuando `suscriptor_payment_id` está seteado. Revertir un pago registrado es una
decisión financiera y deliberadamente **no tiene UI**.

### 1.3 Mapa de archivos

```
app/Enums/PaymentStatus.php          estado de pago según días de atraso + etiquetas/clases CSS
app/Support/Rut.php                  normalizar / formatear / validar módulo 11
app/Support/Webpay.php               arma las Options del SDK; LANZA si producción con credenciales sandbox
app/Support/Text.php                 normalización sin acentos ni mayúsculas (headers y glosas)
app/Support/Cartola/                 lectura del CSV: CartolaReader, ColumnMap, Money, Dates, Glosa, Movement
app/Support/Reconciliation/          MatchEngine y sus candidatos puntuados
app/Models/Customer.php              Eloquent sobre `empresas`
app/Models/{DatosPlan,SuscriptorPayment,BankStatement,BankMovement,WebpayTransaction}.php
app/Services/RecordPayment.php       ÚNICO camino de escritura de dinero
app/Services/ConfirmMovement.php     ÚNICO lugar donde un movimiento bancario se vuelve pago
app/Services/ImportCartola.php       importa la cartola y decide la auto-confirmación
app/Services/SettlementReport.php    compara depósitos Transbank vs. pagos Webpay del día (solo lectura)
app/Http/Livewire/PaymentAlert.php   pantalla staff
app/Http/Livewire/MyPaymentStatus.php pantalla cliente
app/Http/Livewire/BankReconciliation.php cola de revisión
app/Http/Controllers/WebpayReturnController.php  la pata de retorno
app/Http/Middleware/EnsureUserIsAdmin.php
config/{payment_alert,webpay,bank_reconciliation}.php
```

### 1.4 Estado de las pruebas

```
php artisan test --filter=PaymentStatusTest      lógica pura del semáforo, sin BD
php artisan test --filter=MatchEngineTest        puntuación del calce, sin BD
php artisan test --filter=CartolaParsingTest     lectura del CSV, sin BD
php artisan test --filter=WebpayReturnTest       formas del retorno, idempotencia ante replay, excepción CSRF
php artisan test --filter=WebpayOptionsTest      que Webpay::options() lance con credenciales sandbox en producción
php artisan test --filter=AutoConfirmCartolaTest qué se auto-confirma y (sobre todo) qué no
php artisan test --filter=BankReconciliationTest mismos fixtures con auto_confirm apagado
php artisan test --filter=RecordPaymentTest      regla de fechas y saneamiento de periodo_days
php artisan test --filter=AdminPaymentAlertTest  listado, filtros, contadores, gate de admin
php artisan test --filter=CustomerSuspensionTest suspender/reactivar en dos pasos
php artisan test --filter=MyAccountTest          la vista del cliente y su copy
```

Corren sobre SQLite en memoria (`phpunit.xml`), así que nunca tocan la BD de desarrollo.

**Lo que NO está validado contra la realidad** (bloquea el go-live, y no es un cambio de
código):

- Solo los mapas de columnas `chile` y `santander` tienen fixtures, y esos fixtures se
  escribieron a mano, no se exportaron de un banco. `bci`, `estado` y `generic` son
  **suposiciones informadas**. Ver [C7](#c7-restringir-el-dropdown-de-bancos-bloqueante).
- `transbank_commission_pct` está en `0` y `settlement_lag_days` cuenta días **corridos**,
  mientras Transbank liquida en días **hábiles** y neto de comisión. Ver
  [C8](#c8-honestidad-de-la-pestaña-de-liquidación-decisión).

---

## 2. El cambio de esquema en db2026 — seis sentencias

Son seis sentencias SQL: dos columnas nuevas, un ensanche de columna y tres tablas
nuevas. **Todo se escribe a mano** — ver [sección 3](#3-lo-que-nunca-se-aplica-a-db2026)
sobre por qué `php artisan migrate` no es una opción.

Cada bloque trae su rollback inmediatamente debajo. Está pensado para copiar y pegar en
una ventana de mantención a las 2 de la mañana.

> **Sobre `ENGINE` y `CHARSET`:** los `CREATE TABLE` los declaran explícitamente porque
> `config/database.php` deja `'engine' => null`, con lo que las tablas heredarían el
> motor por defecto del servidor. Si el resto de `db2026` es InnoDB y el default del
> servidor no lo es, las tres tablas nuevas quedarían divergentes. Antes de aplicar,
> confirma con `SHOW CREATE TABLE` de una tabla vecina que el charset y la collation
> coinciden con los de abajo, y ajústalos si no.

> **Sobre claves foráneas:** ninguna migración del módulo declara `foreign()`, y es a
> propósito. `imported_by`, `reconciled_by` y `user_id` apuntan a `users`, que tiene
> borrado lógico, y una FK bloquearía el borrado de usuarios. **Que ningún DBA las
> "arregle" agregándolas.**

### Sentencia 1 — `users.role`

La bandera que abre `/payment-alert`. `NULL` (el default) es un usuario-empresa normal,
que solo ve `/mi-cuenta`; `'admin'` abre el listado completo y las acciones de
suspender/reactivar.

```sql
ALTER TABLE users ADD COLUMN role VARCHAR(20) NULL AFTER status;
```

```sql
-- rollback
ALTER TABLE users DROP COLUMN role;
```

Reversible sin pérdida: el backfill se puede volver a derivar de `isUvo`/`nivelUvo`. Pero
solo revierte **después** de haber revertido el código, o `User::isAdmin()` va a fallar
contra una columna que no existe.

Ver [C4](#c4-role-vs-isuvonivelUvo-decisión) por el backfill que acompaña a esta
sentencia y por la alternativa de no aplicarla en absoluto.

### Sentencia 2 — `suscriptor_payments.external_reference`

La orden de compra de Webpay, y la referencia bancaria de una transferencia conciliada.
Sin esto no hay vínculo estable entre una fila de pago y la pasarela.

Es **`UNIQUE`, no un índice común**: es la última defensa contra registrar dos veces la
misma transacción de la pasarela. Los llamadores reclaman su fila primero, pero esa
verificación es una lectura — ante dos retornos concurrentes, lo único que sobrevive es
la restricción. MySQL permite múltiples `NULL` en un índice único, y la columna nace toda
en `NULL`, así que la restricción no puede fallar al crearse.

```sql
ALTER TABLE suscriptor_payments
  ADD COLUMN external_reference VARCHAR(100) NULL AFTER notes,
  ADD UNIQUE KEY suscriptor_payments_external_reference_unique (external_reference);
```

```sql
-- rollback
ALTER TABLE suscriptor_payments
  DROP INDEX suscriptor_payments_external_reference_unique,
  DROP COLUMN external_reference;
```

⚠️ **Este rollback destruye el único vínculo entre un pago y la pasarela.** Es seguro
solo **antes del primer pago real**. Después es pérdida de datos.

El nombre del índice es el que habría generado Laravel, a propósito, para que la
contabilidad de la tabla `migrations` (paso D6.6) quede honesta.

### Sentencia 3 — ensanche de `suscriptor_payments.amount`

Producción es `decimal(8,2)`, que topa en 999.999,99. Hay **28 planes activos que cobran
más** que eso, el mayor 6.910.380 CLP. Esos pagos revientan al insertar.

```sql
ALTER TABLE suscriptor_payments MODIFY COLUMN amount DECIMAL(12,2) NOT NULL;
```

```sql
-- rollback (SOLO si ninguna fila supera el rango antiguo)
--   SELECT COUNT(*) FROM suscriptor_payments WHERE amount > 999999.99;   -- tiene que dar 0
ALTER TABLE suscriptor_payments MODIFY COLUMN amount DECIMAL(8,2) NOT NULL;
```

⚠️ **Esta es la sentencia que más fácil se hace mal.** `MODIFY` reescribe la definición
**completa** de la columna: si omites el `NOT NULL`, la columna queda nullable en
silencio. Saca la definición actual del `SHOW CREATE TABLE` del [anexo](#9-anexo-captura-del-estado-antes)
y reprodúcela exacta, incluido cualquier `DEFAULT`.

⚠️ **Es también la sentencia lenta.** Los `ADD COLUMN` nullable son `INSTANT` en MySQL 8;
este `MODIFY` es una reconstrucción de tabla. Dimensiona la ventana según el número de
filas de `suscriptor_payments` (medido en el paso D3, sobre la copia restaurada).

Estrechar de vuelta es pérdida de datos para cualquier monto grande ya registrado: después
del go-live, trátalo como un cambio de una sola dirección.

### Sentencia 4 — `bank_statements`

Una cartola subida. Tabla **nueva, propiedad de este módulo**, no espejo de nada.

```sql
CREATE TABLE bank_statements (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bank              VARCHAR(50)  NOT NULL,
  account_number    VARCHAR(50)  NULL,
  period_start      DATE         NULL,
  period_end        DATE         NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,
  file_hash         VARCHAR(64)  NOT NULL,
  imported_by       BIGINT UNSIGNED NULL,
  movement_count    INT          NOT NULL DEFAULT 0,
  created_at        TIMESTAMP    NULL,
  updated_at        TIMESTAMP    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY bank_statements_file_hash_unique (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- rollback
DROP TABLE bank_statements;
```

`file_hash` es el SHA-256 de los bytes subidos: volver a subir el mismo export es el
error de staff más probable, y si no, duplicaría todos los movimientos que contiene.

### Sentencia 5 — `bank_movements`

Una línea de la cartola, más la decisión de conciliación que se tomó sobre ella.

La columna `auto_confirmed` viene de una migración posterior
(`2026_07_28_000001_add_auto_confirmed_to_bank_movements_table.php`) y aquí va **plegada
dentro del CREATE**: para db2026 la tabla se crea una sola vez y ya.

```sql
CREATE TABLE bank_movements (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bank_statement_id     BIGINT UNSIGNED NOT NULL,
  posted_at             DATE         NOT NULL,
  description           VARCHAR(500) NOT NULL,
  reference             VARCHAR(100) NULL,
  amount                DECIMAL(12,2) NOT NULL,
  direction             VARCHAR(10)  NOT NULL,          -- 'credit' | 'debit'
  counterparty_rut      VARCHAR(20)  NULL,
  row_hash              VARCHAR(64)  NOT NULL,
  status                VARCHAR(20)  NOT NULL DEFAULT 'unmatched',
  empresa_id            BIGINT UNSIGNED NULL,
  suscriptor_payment_id BIGINT UNSIGNED NULL,
  match_confidence      TINYINT      NULL,
  match_reason          VARCHAR(255) NULL,
  reconciled_by         BIGINT UNSIGNED NULL,
  reconciled_at         DATETIME     NULL,
  auto_confirmed        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at            TIMESTAMP    NULL,
  updated_at            TIMESTAMP    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY bank_movements_row_hash_unique (row_hash),
  KEY bank_movements_bank_statement_id_index (bank_statement_id),
  KEY bank_movements_posted_at_index (posted_at),
  KEY bank_movements_counterparty_rut_index (counterparty_rut),
  KEY bank_movements_status_index (status),
  KEY bank_movements_empresa_id_index (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- rollback
DROP TABLE bank_movements;
```

`row_hash` es la huella de la línea parseada (fecha + monto + glosa + referencia). Los
exports de cartola se solapan rutinariamente en fechas, así que el mismo depósito llega
en dos archivos distintos; esto es lo que impide conciliarlo dos veces. Es `UNIQUE` y no
un índice común por la misma razón que `external_reference`: la verificación del
importador es una lectura, y dos importaciones simultáneas la pasarían las dos.

`status` recorre `unmatched → suggested → matched`, o bien `→ ignored`. Solo `matched`
tiene efecto financiero.

### Sentencia 6 — `webpay_transactions`

Una fila por checkout iniciado, escrita **antes** de que el navegador se vaya a Transbank.
Es lo que la pata de retorno usa para encontrar el pago, porque —como se explicó en
[1.2b](#12-las-siete-cosas-que-hay-que-entender-antes-de-tocar-nada)— ahí no hay sesión.

```sql
CREATE TABLE webpay_transactions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  buy_order             VARCHAR(26)  NOT NULL,
  session_id            VARCHAR(61)  NOT NULL,
  empresa_id            BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  amount                DECIMAL(12,2) NOT NULL,
  search                VARCHAR(100) NOT NULL DEFAULT '',
  return_to             VARCHAR(20)  NOT NULL DEFAULT 'payment-alert',
  status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
  token                 VARCHAR(100) NULL,
  suscriptor_payment_id BIGINT UNSIGNED NULL,
  response_code         SMALLINT     NULL,
  committed_at          DATETIME     NULL,
  created_at            TIMESTAMP    NULL,
  updated_at            TIMESTAMP    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY webpay_transactions_buy_order_unique (buy_order),
  KEY webpay_transactions_empresa_id_index (empresa_id),
  KEY webpay_transactions_status_index (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- rollback
DROP TABLE webpay_transactions;
```

`buy_order` es único, y por eso mismo es la guardia natural de idempotencia: un retorno
reenviado encuentra la fila ya fuera de `pending`.

`user_id` vive acá porque `suscriptor_payments.user_id` es NOT NULL en producción y el
retorno de Transbank llega sin autenticar — hay que saber quién inició el pago sin poder
preguntárselo a la sesión.

`status` recorre `pending → authorized | declined | aborted | failed`. Solo `authorized`
tiene efecto financiero, y **no hay UI para revertir uno**, por la misma razón que la
conciliación no tiene deshacer una vez que hay pago.

⚠️ Botar esta tabla después del go-live significa que cualquier checkout en vuelo vuelve
a una orden de compra desconocida: se loguea en `error`, el cliente quedó cobrado y no se
registró nada. Revierte solo con los pagos deshabilitados.

### Orden de rollback completo

Si hay que revertir todo el cambio, en este orden:

1. `DROP TABLE webpay_transactions;`
2. `DROP TABLE bank_movements;`
3. `DROP TABLE bank_statements;`
4. Revertir `amount` a `DECIMAL(8,2)` (verificando primero que ninguna fila supere el rango).
5. Sacar `external_reference` y su índice único.
6. `ALTER TABLE users DROP COLUMN role;` — **al final**, porque es lo que el código
   desplegado más necesita: se saca solo después de que el código ya está revertido.

---

## 3. Lo que NUNCA se aplica a db2026

### 3.1 Nunca `php artisan migrate` contra db2026

`db2026` tiene **su propia tabla `migrations`** con 242 filas, sobre 257 tablas.
Correr las migraciones de este repo ahí intentaría `CREATE TABLE users` y
`CREATE TABLE empresas` — o falla, o peor, queda registrado como aplicado.

Todo el DDL de la [sección 2](#2-el-cambio-de-esquema-en-db2026--seis-sentencias) va
escrito a mano. Y si el script de despliegue del anfitrión corre `php artisan migrate`
incondicionalmente, el paso [D6.6](#d6--ddl-en-vivo-ventana-de-mantención) **no es
opcional**.

### 3.2 Migraciones que son espejo local — no se aplican

Las migraciones de este repo bajo `database/migrations/` son un **espejo solo para
desarrollo local** de las columnas que esta funcionalidad toca, no paridad completa con
producción. Estas describen tablas que **ya existen** en `db2026`:

```
2014_10_12_000000_create_users_table.php                    (Laravel de fábrica)
2014_10_12_100000_create_password_resets_table.php          (Laravel de fábrica)
2019_08_19_000000_create_failed_jobs_table.php              (Laravel de fábrica)
2019_12_14_000001_create_personal_access_tokens_table.php   (Laravel de fábrica)
2026_07_20_000001_add_empresa_id_and_status_to_users_table.php
2026_07_20_000002_create_empresas_table.php
2026_07_20_000003_create_datos_plan_table.php
2026_07_20_000004_create_suscriptor_payments_table.php      (la parte CREATE)
```

Ojo con la última: el `CREATE TABLE suscriptor_payments` es espejo, pero **dos líneas
adentro sí son DDL real de producción** — el `decimal(12,2)` y el `external_reference`
único. Son las sentencias 2 y 3 de la sección anterior.

### 3.3 El seeder no se copia al repo anfitrión

`database/seeders/PaymentAlertDemoSeeder.php` inserta con `DB::table()` crudo, **sin
ninguna guarda de entorno**:

- 6 `empresas` de demo y sus `datos_plan`
- 7 `users`, incluida una cuenta **`admin@example.test` con contraseña `password` y
  `role = 'admin'`**
- una fila en `suscriptor_payments`

Crear una cuenta de administrador viva con contraseña `password` en `db2026` es el peor
resultado disponible en todo este trabajo. **El seeder no viaja al repo anfitrión.** Se
queda acá, para demos locales.

Si el equipo anfitrión igual quiere un camino de demo, la copia tiene que empezar con:

```php
abort_if(app()->environment('production'), 500, 'Seeder de demo en producción');
abort_if(DB::connection()->getDatabaseName() === 'db2026', 500, 'Seeder de demo contra db2026');
```

Nota: la generación de `storage/app/demo/cartola-demo.csv` vive dentro de ese mismo
seeder, así que descartarlo también descarta la cartola de demo.

---

## 4. Fusión del código en el sistema anfitrión

### 4.1 Requisitos previos a verificar (antes de mover un solo archivo)

Anota las respuestas; varias cambian el plan.

1. **Laravel y PHP.** `php artisan --version`, `php -v`, `composer show laravel/framework`.
   Se necesita Laravel ^9 (Livewire 2 soporta 7–10). Si el anfitrión está en Laravel 11+,
   Livewire 2 no corre y esto pasa a ser un trabajo mucho más grande. PHP ≥ 8.0; este
   módulo se mantiene compatible con 8.0 a propósito (por eso `PaymentStatus` es una clase
   de constantes y no un enum nativo de 8.1).
2. **Resolución de dependencias.** En un clon de prueba:
   `composer require livewire/livewire:^2.0 transbank/transbank-sdk:^3.0 --dry-run`.
   Bloqueadores a buscar: que el anfitrión ya esté en **Livewire 3** (entonces los tres
   componentes necesitan portarse: `$persistentMiddleware`, semántica de `wire:model` y
   sintaxis de etiquetas cambian todas); que fije `guzzlehttp/guzzle` bajo `^7.2`.
   `transbank/transbank-sdk` está fijado en `^3.0` porque es la última mayor que soporta
   PHP < 8.2.
3. **Autenticación existente.** ¿El anfitrión tiene su propio login? (lo esperable: sí).
   Si es así, se descarta el login del módulo — ver 4.3 — y hay que repuntar los
   `route('logout')` de `resources/views/partials/admin-sidebar.blade.php` y
   `resources/views/my-account-page.blade.php`, y cambiar los tests que entran por
   `/login` a `actingAs()`.
4. **Layout y Bootstrap.** ¿El anfitrión tiene `resources/views/layouts/*`? ¿Carga
   Bootstrap, en qué versión? ¿Compila assets (`public/mix-manifest.json`,
   `vite.config.js`)? Ver la recomendación en 4.4.
5. **`storage/app/cartolas`** tiene que existir y ser escribible por el usuario del
   servidor web, y quedar fuera de cualquier sincronización o backup que aterrice en el
   web root.
6. **Colas y scheduler:** el módulo no tiene jobs ni comandos agendados. Confirma que
   nadie espera que los tenga.
7. **¿El anfitrión escribe en las mismas tablas?** Haz grep en el repo anfitrión por
   escrituras a `empresas.proximoPago`, `empresas.estado`, `datos_plan.fecha_vencimiento`
   y `suscriptor_payments`, y por **lecturas** de `empresas.fechaVencimiento`,
   `empresas.pagos` y `empresas.plan`. Esto responde [C6](#c6-columnas-legacy-de-empresas-pregunta-abierta),
   y solo se puede responder desde el repo anfitrión.
8. **¿El anfitrión ya se apropió de algo nuestro?** Un alias de middleware `'admin'`, o
   las URIs `/payment-alert`, `/mi-cuenta`, `/conciliacion-bancaria`, o los nombres de
   ruta `payment-alert`, `webpay.return`, `bank-reconciliation`, `mi-cuenta`.

### 4.2 Copiar tal cual (no hay equivalente en el anfitrión)

```
app/Enums/PaymentStatus.php
app/Http/Controllers/WebpayReturnController.php
app/Http/Livewire/PaymentAlert.php
app/Http/Livewire/MyPaymentStatus.php
app/Http/Livewire/BankReconciliation.php
app/Http/Middleware/EnsureUserIsAdmin.php
app/Models/Customer.php
app/Models/DatosPlan.php
app/Models/SuscriptorPayment.php
app/Models/BankStatement.php
app/Models/BankMovement.php
app/Models/WebpayTransaction.php
app/Rules/ValidRut.php
app/Services/RecordPayment.php
app/Services/ConfirmMovement.php
app/Services/ImportCartola.php
app/Services/SettlementReport.php
app/Support/Rut.php
app/Support/Text.php
app/Support/Webpay.php
app/Support/Cartola/{CartolaException,CartolaReader,ColumnMap,Dates,Glosa,Money,Movement}.php
app/Support/Reconciliation/{CandidateCompany,MatchEngine,MatchResult,ScoredCandidate}.php
config/payment_alert.php
config/webpay.php
config/bank_reconciliation.php
resources/views/payment-alert-page.blade.php
resources/views/bank-reconciliation-page.blade.php
resources/views/my-account-page.blade.php
resources/views/partials/admin-styles.blade.php
resources/views/partials/admin-sidebar.blade.php
resources/views/livewire/payment-alert.blade.php
resources/views/livewire/bank-reconciliation.blade.php
resources/views/livewire/my-payment-status.blade.php
tests/Unit/{PaymentStatusTest,MatchEngineTest,CartolaParsingTest}.php
tests/Feature/{AdminPaymentAlertTest,CustomerSuspensionTest,MyAccountTest,RecordPaymentTest}.php
tests/Feature/{BankReconciliationTest,AutoConfirmCartolaTest,WebpayOptionsTest,WebpayReturnTest}.php
tests/fixtures/cartolas/*.csv
docs/bank-reconciliation.md      (manual del operador)
docs/laravel-for-fastapi-devs.md (opcional: onboarding)
este documento
```

Advertencias sobre esta lista:

- **Las tres shells de página y `partials/admin-styles.blade.php` son un paquete.** Las
  clases `alert-orange` / `badge-orange` (Bootstrap 4 no tiene variante naranja) salen
  solo de ese partial, y tiene que incluirse **después** del link a Bootstrap: casi todo
  es override de igual especificidad. Una página que renderice esos nombres de clase sin
  el partial muestra un badge sin estilo.
- Los tests de `tests/Feature/` asumen el `TestCase`/`CreatesApplication` del anfitrión y
  un `phpunit.xml` con SQLite en memoria. Si el `phpunit.xml` del anfitrión apunta a una
  BD real, necesitan su propia suite.
- **Todo el CSS custom está inline en los blades**, porque esta app no tiene layout
  compartido ni assets compilados: `resources/css/app.css` está vacío, no hay nada en
  `public/css`, y `mix()` lanza sin `public/mix-manifest.json` — lo que rompería todos los
  tests de feature. Cada clase custom lleva prefijo `pa-` para no colisionar con Bootstrap.
- **No se carga Bootstrap JS en ninguna parte** — las shells enlazan solo
  `bootstrap.min.css` por CDN, sin jQuery ni Popper. Nada puede depender de collapse,
  dropdowns ni tooltips de BS4. En particular, el desplegable de cuenta de `/mi-cuenta` es
  un `<details>`/`<summary>` nativo justamente por eso: no necesita script y **no debe
  "mejorarse"** a un `.dropdown` de BS4, que dejaría de abrirse en silencio.

### 4.3 No copiar

```
app/Http/Livewire/Auth/Login.php                → el anfitrión tiene su propio login
app/Http/Controllers/Auth/LogoutController.php
resources/views/auth-page.blade.php
resources/views/livewire/auth/login.blade.php
resources/views/welcome.blade.php
database/seeders/*                              → ver 3.3
database/factories/UserFactory.php              → salvo que el anfitrión no tenga uno; si se copia, se fusiona
database/migrations/  (las espejo)              → ver 3.2
app/Console/Kernel.php, app/Exceptions/Handler.php, app/Http/Controllers/Controller.php
app/Http/Middleware/{Authenticate,EncryptCookies,PreventRequestsDuringMaintenance,
                     RedirectIfAuthenticated,TrimStrings,TrustHosts,TrustProxies}.php
app/Providers/{AuthServiceProvider,BroadcastServiceProvider,EventServiceProvider,
                RouteServiceProvider}.php
config/{app,auth,broadcasting,cache,cors,hashing,logging,mail,queue,sanctum,services,view}.php
routes/{api,channels,console}.php
resources/js/*, resources/css/app.css, webpack.mix.js, package.json
artisan, phpunit.xml, README.md, AGENTS.md, .env, composer.lock, public/*, storage/*
tests/{Feature,Unit}/ExampleTest.php
```

### 4.4 Colisiones — se fusionan a mano, NUNCA se sobrescriben

Estos archivos ya existen en el anfitrión. Un commit por cada uno, con el diff revisado:
es exactamente donde se esconde una sobrescritura silenciosa.

| Archivo | Qué se inyecta |
|---|---|
| `app/Models/User.php` | Solo los métodos `isAdmin()` (devuelve `$this->role === 'admin'`) y `customer()` (`belongsTo(Customer::class, 'empresa_id')`). Agregar `'role'` a `$fillable` **solo si** el anfitrión usa asignación masiva en usuarios. **No reemplaces** el `$fillable`/`$hidden`/`$casts` del anfitrión — su `User` tiene muchas más columnas (`isUvo`, `nivelUvo`, …). Ver también [C1](#c1-usuarios-con-borrado-lógico-pueden-autenticarse-bloqueante). |
| `app/Http/Kernel.php` | **Una línea** en `$routeMiddleware`: `'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class`. Verifica antes que el anfitrión no tenga ya un alias `'admin'`; si lo tiene, renombra el nuestro (p. ej. `'pa.admin'`) y ajusta las dos rutas y los tests que lo asertan. Nada más difiere del Kernel de fábrica. |
| `app/Http/Middleware/VerifyCsrfToken.php` | Agregar `'payment-alert/webpay/return'` a `$except`, **con su bloque de comentario intacto**. Si esto se pierde en la fusión, todo pago real termina en 419 después del cobro. |
| `config/filesystems.php` | Solo el disco `cartolas`: `driver => 'local'`, `root => storage_path('app/cartolas')`, `visibility => 'private'`, **sin clave `url`**, y **fuera** del arreglo `links` (eso lo enlazaría al web root). Una cartola lista todos los movimientos de la cuenta bancaria de la empresa. Si el disco por defecto del anfitrión es S3, `cartolas` igual se queda en `local` salvo decisión explícita de usar un bucket privado. |
| `config/session.php` | Gana el archivo del anfitrión, **excepto**: verifica `'same_site' => 'lax'` y que exista `'secure' => env('SESSION_SECURE_COOKIE')`. Si el anfitrión tiene `'strict'`, rompe el retorno de Webpay igual que la falta de la excepción CSRF. Si tiene `'none'`, es una regresión de seguridad que el módulo tolera pero no quiere. Anota el valor encontrado. |
| `config/database.php` | Solo el bloque `mysql_replica` (opcional, si se quiere el camino de lectura por tinker). **No toques** la conexión `mysql` del anfitrión. Recuerda que `'engine' => null` es la razón de los `ENGINE=` explícitos de la sección 2. |
| `routes/web.php` | Las cuatro rutas del módulo, descartando `/`, `/login` y `/logout`. Conserva los **nombres** `payment-alert`, `webpay.return`, `bank-reconciliation`, `mi-cuenta` — están referenciados en los componentes, en `admin-sidebar.blade.php` y en los tests. La URI `payment-alert/webpay/return` tiene que quedar **textualmente igual**, o se rompen a la vez la cadena de la excepción CSRF y la URL de retorno registrada en Transbank. |
| `app/Providers/AppServiceProvider.php` | Los **tres** bindings de `register()`, con sus comentarios: `CartolaReader::class → fromConfig()`, `MatchEngine::class → fromConfig()` y `Transaction::class → new Transaction(Webpay::options())`. Los dos primeros son obligatorios en runtime (esas clases no tienen constructor sin argumentos); el tercero es lo único que hace testeable el camino de commit. Está ligado de forma perezosa a propósito, porque `Webpay::options()` lanza ante una configuración de producción mala y eso tiene que aflorar cuando parte un pago, no cuando arranca el contenedor. |
| `app/Providers/RouteServiceProvider.php` | **No se copia.** `EnsureUserIsAdmin` redirige a `RouteServiceProvider::HOME`, que acá vale `/mi-cuenta` pero en el anfitrión será su propia landing. Recomendación: cambiar `EnsureUserIsAdmin` para que use `redirect()->route('mi-cuenta')` explícito y desacoplarse de la constante. |
| `composer.json` | Agregar `livewire/livewire: ^2.0` y `transbank/transbank-sdk: ^3.0` a `require`. |
| `.gitignore` | Agregar `.env` si falta. Ver [C9](#c9-higiene-de-secretos-bloqueante). |

### 4.5 Qué hacer con las migraciones en el repo anfitrión

El DDL de producción va a mano (sección 2), pero el repo anfitrión igual necesita que el
esquema exista para que `php artisan test` corra. **Opción recomendada:** copiar las cinco
migraciones propias del módulo (`2026_07_22_000001`, `2026_07_26_000001`,
`2026_07_26_000002`, `2026_07_27_000001`, `2026_07_28_000001`) a
`database/migrations/` del anfitrión, e insertar a mano las filas correspondientes en la
tabla `migrations` de db2026 en el paso D6.6, para que un `php artisan migrate` futuro
sea un no-op.

Las migraciones **espejo** (`empresas`, `datos_plan`, `users.empresa_id/status`,
`suscriptor_payments`) tienen que ir a una ruta **solo para tests**
(p. ej. `tests/database/migrations/` cargada con `loadMigrationsFrom()` en el `TestCase`),
porque esas tablas ya existen en db2026.

### 4.6 Sobre re-skinear al layout del anfitrión

Las cuatro shells son documentos `<!DOCTYPE html>` autónomos, con Bootstrap 4.6.2 por CDN,
`@livewireStyles`/`@livewireScripts`, y **sin `@extends`**.

**Recomendación: mantener las shells autónomas para la v1.** Son autocontenidas, no
pueden colisionar con el CSS del anfitrión, y cada `assertSee` de los tests de feature
sigue pasando. El costo es que el módulo se ve como otra app y no tiene la navegación del
anfitrión.

Si de todas formas se re-skinea, el orden seguro es:
(a) reemplazar solo el envoltorio `<html>/<head>/<body>` por `@extends` + `@section`;
(b) mantener el `@include('partials.admin-styles')` **dentro** de la sección y siempre
después del link a Bootstrap; (c) **no tocar** los blades internos (`livewire/*.blade.php`)
— los tests asertan subcadenas contiguas de ahí y los atributos `wire:` son estructurales.
`@livewireStyles`/`@livewireScripts` tienen que quedar exactamente una vez por página.
Si el anfitrión no es Bootstrap 4, los overrides de igual especificidad de `admin-styles`
fallan y el re-skin pasa a ser un trabajo de diseño de verdad: déjalo para después del
go-live.

---

## 5. Cambios de código pendientes antes de salir a producción

Cada uno con archivo y edición concreta. **C1, C2, C3, C7 y C9 son bloqueantes.**

### C1 — Usuarios con borrado lógico pueden autenticarse (bloqueante)

`app/Models/User.php` no usa `SoftDeletes`, pero la tabla `users` de producción tiene
`deleted_at` con **567 filas borradas lógicamente**. Hoy un empleado dado de baja podría
iniciar sesión.

Edición: agregar `use Illuminate\Database\Eloquent\SoftDeletes;` y el trait a la lista.
Efecto secundario deseable: `User::where('empresa_id', …)->update(…)` de C3 pasa a excluir
automáticamente las filas borradas.

Verifica primero si el `User` del anfitrión ya lo tiene. Si deliberadamente **no** lo
tiene (porque algún flujo del anfitrión necesita ver esas filas), entonces en vez de tocar
el modelo agrega la condición `deleted_at IS NULL` al login. Decídelo en el pre-vuelo, no
durante la fusión.

Test de regresión a agregar: un usuario con borrado lógico no puede iniciar sesión.

### C2 — El login ignora `users.status` (bloqueante)

`Auth::attempt(['email', 'password'])` no filtra por `status`. La asimetría correcta, que
hay que dejar escrita porque no es obvia:

- Un **usuario-empresa** suspendido **sí debe** poder entrar. Es exactamente lo que le
  permite pagar y reactivarse. (Por eso el `can_pay` de `MyPaymentStatus` omite a
  propósito la verificación de `is_active` que sí hace la vista de staff.)
- Un usuario **staff** suspendido **no** debe entrar.

Edición: después de un `Auth::attempt` exitoso, si
`Auth::user()->isAdmin() && (int) Auth::user()->status !== 1` → `Auth::logout()` y error.
Los usuarios con `role` nulo conservan el comportamiento actual.

### C3 — `update` a ciegas sobre `users.status` (bloqueante)

`app/Http/Livewire/PaymentAlert.php`, en `suspend()` (~línea 196) y `reactivate()`
(~línea 214):

```php
User::where('empresa_id', $this->customer->id)->update(['status' => 0]);  // y 1
```

En producción una empresa puede tener hasta **57 usuarios**. Dos problemas: deshabilita a
staff que casualmente tenga `empresa_id`, y reactivar re-habilita a gente que fue
deshabilitada por motivos ajenos a esto.

Ediciones:
- Excluir staff: `->whereNull('role')` (después del DDL) y/o `->where('isUvo', 0)`.
- Hacer que reactivar no sea destructivo: idealmente registrar qué ids se afectaron al
  suspender. La versión mínima es `->where('status', 1)` al suspender y
  `->where('status', 0)` al reactivar, que al menos lo vuelve idempotente aunque no
  distingue causas.

**Alternativa que vale la pena plantearle al dueño del anfitrión:** dejar de tocar
`users.status` por completo y gatear el servicio solo con `empresas.estado`. Elimina toda
esta clase de error. Requiere saber si el anfitrión lee `users.status` para algo
(pre-vuelo 4.1.7).

### C4 — `role` vs. `isUvo`/`nivelUvo` (decisión)

Producción ya tiene dos banderas de staff: `isUvo` (29 filas en 1) y `nivelUvo`
(`admin`, `ejecutivo`, `soporte`, `supervisor_*`). Después del DDL habría tres.

La decisión tomada (y que reemplazó a una propuesta anterior de gatear por `isUvo`) es:
`role` es la bandera propia del módulo, se **rellena una sola vez** desde las existentes,
y después la mantiene la administración de usuarios del anfitrión.

```sql
-- primero MIRA a quién le va a tocar:
SELECT id, name, email, nivelUvo FROM users
 WHERE isUvo = 1 AND nivelUvo = 'admin' AND deleted_at IS NULL;

-- recién después:
UPDATE users SET role = 'admin'
 WHERE isUvo = 1 AND nivelUvo = 'admin' AND deleted_at IS NULL;
```

Acotado a `nivelUvo = 'admin'` y **no** a todo `isUvo = 1`: `/payment-alert` puede
suspender clientes, así que `soporte` no debería recibirlo por defecto. Valida los nombres
con el dueño de db2026 antes de correr el `UPDATE`.

**Alternativa:** hacer que `User::isAdmin()` lea `isUvo`/`nivelUvo` y **eliminar la
sentencia 1 del DDL completa**. Es más barato y deja una sola fuente de verdad; se
descartó, pero si se revive hay que decirlo explícitamente y sacar también la migración
`2026_07_22_000001` del repo anfitrión.

### C5 — Convención de `suscriptor_payments.user_id` (decisión)

En producción esa columna significa "el funcionario que registró el pago" — tiene solo
**5 valores distintos**. El módulo escribe `Auth::id()`, que en `/mi-cuenta` es el propio
cliente. Es decir, el significado de la columna cambia en silencio.

Opciones:

1. Dejar `Auth::id()` y desambiguar por `external_reference` (`PA…` = Webpay, referencia
   bancaria = transferencia). Requiere que los reportes de pago del anfitrión toleren
   valores nuevos en `user_id`.
2. **(Recomendada)** Escribir el id de una **cuenta de servicio** dedicada para todos los
   pagos originados por el módulo (una fila nueva en `users`, `role` nulo, `status` 0 para
   que no pueda iniciar sesión), y dejar al actor real en `responsable` o `notes`. Así la
   columna sigue significando lo que siempre significó, y además resuelve el hueco del
   camino auto-confirmado, donde no hay nadie que haya decidido.

La edición cae en `app/Services/RecordPayment.php` (~línea 66) y sus llamadores.

### C6 — Columnas legacy de `empresas` (pregunta abierta)

`empresas` tiene columnas legacy `fechaVencimiento`, `pagos` y `plan` que `RecordPayment`
**no** actualiza. Si el anfitrión las lee, un pago exitoso las deja obsoletas.

Solo se puede responder con grep en el repo anfitrión (pre-vuelo 4.1.7). Si las lee,
agrega las escrituras dentro del mismo `DB::transaction()` de `RecordPayment`. Si no,
déjalo anotado como muertas. **No adivines.**

### C7 — Restringir el dropdown de bancos (bloqueante)

En `config/bank_reconciliation.php`, comenta o saca las entradas `bci`, `estado` y
`generic`: sus mapas de columnas son suposiciones informadas, sin un export real que las
respalde. Solo `chile` y `santander` tienen fixtures — y esos fixtures se escribieron
aquí, no se exportaron de un banco.

Es edición **de config, no de código**: `BankReconciliation::banks()` (línea ~385) y
`CartolaReader::fromConfig()` leen el mismo arreglo, así que sacar una entrada la saca del
dropdown, de la validación del upload (`'bank' => 'required|string|in:…'`, línea ~96) *y*
del lector, a la vez. Deja un comentario apuntando a `tests/fixtures/cartolas/` que
explique que un banco vuelve el día que un export real se convierta en fixture. Revisa
antes `tests/Unit/CartolaParsingTest.php` y `docs/bank-reconciliation.md` por referencias
a las claves que saques.

Soportar un banco más es una entrada de config, no una clase nueva.

### C8 — Honestidad de la pestaña de liquidación (decisión)

`transbank_commission_pct` está en `0` y `settlement_lag_days` cuenta días **corridos**,
mientras Transbank liquida en días **hábiles** y neto de comisión. Hasta calibrar ambos
con depósitos reales, la pestaña de liquidación va a marcar como descuadre días
perfectamente sanos.

Opciones: (a) fijar ambos desde el primer mes de depósitos reales antes de mostrar la
pestaña, o (b) esconder la pestaña detrás de un flag para el go-live. No es un error de
corrección: es un error de "el staff va a dejar de creerle a la pantalla".

### C9 — Higiene de secretos (bloqueante)

- **`.env` no está en `.gitignore`** en este repo, y contiene en texto plano la contraseña
  del usuario `consultor` de la réplica de producción. Agrégalo al `.gitignore` de **ambos**
  repos antes del primer commit en el anfitrión.
- **Rota esa credencial.** Está en el árbol de trabajo en texto plano.
- Verifica con `git log --all -p -- .env` que nunca se haya commiteado. Si se commiteó, la
  rotación deja de ser recomendable y pasa a ser obligatoria.
- Variables a agregar al `.env` del anfitrión: `WEBPAY_ENVIRONMENT`,
  `WEBPAY_COMMERCE_CODE`, `WEBPAY_API_KEY`, `PAYMENT_ALERT_DUE_SOON_DAYS`,
  `PAYMENT_ALERT_OVERDUE_GRACE_DAYS`, `BANK_RECON_AUTO_CONFIRM`,
  `BANK_RECON_SETTLEMENT_LAG_DAYS`, `BANK_RECON_TBK_COMMISSION_PCT`,
  `SESSION_SECURE_COOKIE=true`, y un `APP_URL` real con https.

### C10 — Aviso de despliegue sobre las credenciales Webpay

`app/Support/Webpay.php` lanza excepción cuando `WEBPAY_ENVIRONMENT=production` y alguna
credencial sigue siendo la del SDK, y también cuando el ambiente es cualquier valor que no
sea `integration` o `production` (antes ambos casos caían al sandbox en silencio, así que
un despliegue a producción que olvidara las variables no cobraba plata de verdad).

Pero eso lanza **dentro del binding perezoso**, es decir **en el momento en que un cliente
hace clic en pagar**, no al arrancar. Por lo tanto: **un despliegue en verde no prueba
nada.** El checklist de despliegue tiene que incluir iniciar un checkout de verdad
(paso F2.4).

Cómo conseguir credenciales reales: ver `docs/bank-credentials.md`.

### C11 — Verificación de que el gate de admin sobrevive

Livewire 2 vuelve a correr solo `$persistentMiddleware` en sus propios requests, y
`EnsureUserIsAdmin` no está ahí: **el gate de la ruta protege la carga de la página, no las
acciones**. Por eso `BankReconciliation::assertAdmin()` se llama al inicio de `confirmMatch`,
`ignoreMovement` e `import`, y hay tests que asertan 403 para un no-admin.

Después de la fusión, confirma que lo mismo sigue valiendo para
`PaymentAlert::suspend()/reactivate()/payWithWebpay()`, y que la config de Livewire del
anfitrión no cambie `Livewire::$persistentMiddleware`.

---

## 6. Orden de puesta en marcha

### D1 — Pre-vuelo, solo lectura, contra `mysql_replica`

Sin escrituras, sin locks. Además de las consultas de [F3](#f3--consultas-de-solo-lectura-tinker):

```sql
SHOW CREATE TABLE users\G
SHOW CREATE TABLE suscriptor_payments\G
```

Guarda ambas salidas en el [anexo](#9-anexo-captura-del-estado-antes) de este documento:
el estado "antes" tiene que quedar en el documento, no en el historial de la terminal de
alguien. De ahí sale la definición exacta que la sentencia 3 debe reproducir.

```sql
-- la columna role no puede existir todavía
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema='db2026' AND table_name='users' AND column_name='role';   -- 0

-- las tres tablas nuevas tampoco
SELECT COUNT(*) FROM information_schema.tables
 WHERE table_schema='db2026'
   AND table_name IN ('bank_statements','bank_movements','webpay_transactions');  -- 0

-- para dimensionar el MODIFY lento
SELECT COUNT(*), MAX(amount) FROM suscriptor_payments;
```

Confirma la versión de MySQL (debería ser 8.0.46): en 8.0 los `ADD COLUMN` nullable son
`INSTANT`, pero el `MODIFY amount` es una reconstrucción.

### D2 — Restaurar una copia de db2026

Desde el backup más reciente, en un servidor que no sea producción. Llámala
`db2026_ddl_test`.

### D3 — Aplicar y revertir el DDL sobre la copia

Aplica las seis sentencias cronometrando cada una. Verifica con `SHOW CREATE TABLE`.
**Después corre los rollbacks sobre la misma copia** y verifica que vuelve al estado
capturado en D1. Este es el único lugar donde los rollbacks se prueban.

### D4 — Fusión del código, en una rama

En este orden: (1) `composer require` de los dos paquetes; (2) copiar los archivos de 4.2;
(3) aplicar las diez fusiones de 4.4, **un commit por archivo, con el diff revisado**;
(4) aplicar C1–C9; (5) `.env` y config; (6) `php artisan test` en verde; (7) revisión de
código enfocada en los commits de fusión — ahí es donde se esconde una sobrescritura
silenciosa.

### D5 — Smoke test en staging

Apuntando a la copia `db2026_ddl_test`, con URL pública HTTPS (el retorno de Webpay no se
puede ejercitar sobre `http://localhost`). Checklist completo de [F2](#f2--manual-de-punta-a-punta).

### D6 — DDL en vivo, ventana de mantención

`mysqldump` de `users` y `suscriptor_payments` **inmediatamente antes** del paso 1.

1. Sentencia 1 (`users.role`) — rápida, `INSTANT`.
2. El backfill de [C4](#c4-role-vs-isuvonivelUvo-decisión): primero el `SELECT`, revisar
   los nombres con el dueño, después el `UPDATE`.
3. Sentencia 2 (`external_reference` + índice único).
4. Sentencia 3 (`MODIFY amount`) — **la lenta**, con las escrituras pausadas.
5. Sentencias 4, 5 y 6 (las tres tablas nuevas).
6. Insertar en la tabla `migrations` de db2026 las filas correspondientes a las cinco
   migraciones propias del módulo (ver 4.5), usando `MAX(batch)+1`, para que un
   `php artisan migrate` futuro sea un no-op.

### D7 — Desplegar el código

`php artisan config:clear && config:cache && route:cache && view:clear`.
**Nunca `php artisan migrate`** como parte de este despliegue. Si el script del anfitrión
lo corre incondicionalmente, verifica D6.6 antes de desplegar.

### D8 — Verificación post-despliegue

[F2](#f2--manual-de-punta-a-punta) contra producción: primero un checkout completo en el
ambiente de integración de Transbank, después **un** cobro real de monto bajo sobre una
empresa que controles, conciliado a mano.

### D9 — Ventana de observación

48 horas mirando `laravel.log` filtrado por `error` de `WebpayReturnController` e
`ImportCartola`, más un conteo diario de:

```sql
SELECT COUNT(*) FROM webpay_transactions
 WHERE status='pending' AND created_at < NOW() - INTERVAL 1 HOUR;
```

Una fila `pending` estancada significa que un cliente se fue a mitad del checkout, o que
la pata de retorno está rota. Hay que saber cuál de las dos.

Recuerda que **todo camino que pierde plata se loguea en `error`**: orden de compra
desconocida, cliente inexistente, descuadre de monto. Antes esos casos retornaban en
silencio, que es cómo un cliente cobrado podía no dejar rastro.

---

## 7. Verificación

### F1 — Automática (en el repo anfitrión, después de D4)

```
php artisan test --filter=PaymentStatusTest
php artisan test --filter=MatchEngineTest
php artisan test --filter=CartolaParsingTest
php artisan test --filter=WebpayReturnTest
php artisan test --filter=WebpayOptionsTest
php artisan test --filter=AutoConfirmCartolaTest
php artisan test --filter=BankReconciliationTest
php artisan test --filter=RecordPaymentTest
php artisan test --filter=AdminPaymentAlertTest
php artisan test --filter=CustomerSuspensionTest
php artisan test --filter=MyAccountTest
php artisan test                                  # la suite del anfitrión también
```

**Los dos canarios de la fusión:**

- `WebpayReturnTest` cae si se perdió el binding de `Transaction` en `AppServiceProvider`
  o la excepción CSRF.
- `AdminPaymentAlertTest` / `CustomerSuspensionTest` caen si se perdió el alias `'admin'`
  o el cableado de `RouteServiceProvider::HOME`.

Tests nuevos a agregar junto con C1–C3: usuario con borrado lógico no puede iniciar
sesión; admin suspendido no puede entrar pero usuario-empresa suspendido sí; `suspend()`
no toca a un usuario staff que comparta el `empresa_id`.

### F2 — Manual de punta a punta

Requiere `APP_URL` público con HTTPS y `SESSION_SECURE_COOKIE=true`.

1. Entrar como usuario-empresa no admin → aterriza en `/mi-cuenta`; `/payment-alert` y
   `/conciliacion-bancaria` redirigen.
2. Entrar como usuario con `role='admin'` → `/payment-alert` carga, el listado pagina, los
   tres contadores de estado cuadran con los resultados del filtro, y los badges de plan
   renderizan valores largos sin romper el layout (`empresas.tipoPlan` es texto libre:
   producción tiene 13 valores distintos, incluido el typo `Menusal`, y valores tan largos
   como `Servicios Integrales y Logisticos…`).
3. Buscar por RUT (`77353398-9`) y por ID numérico; revisar `formatted_rut` y el semáforo.
4. **Iniciar un checkout Webpay** — este es el canario de [C10](#c10--aviso-de-despliegue-sobre-las-credenciales-webpay).
   Completarlo en el ambiente de integración y verificar: vuelve a
   `/payment-alert?search=…` sin 419; `webpay_transactions.status='authorized'`;
   **exactamente una** fila en `suscriptor_payments` con `external_reference` `PA…`;
   `empresas.proximoPago` y `datos_plan.fecha_vencimiento` extendidos desde
   `max(vencimiento anterior, fecha de pago)`; `empresas.estado='1'`.
5. **Reenviar el POST de retorno** (reenviar el formulario / refrescar) → sigue habiendo
   **un solo** pago; el segundo intento loguea y no hace nada.
6. Abandonar un checkout en Transbank (camino `TBK_TOKEN`) → no se registra pago y sale el
   texto correcto en español.
7. Suspender una empresa vencida y suspendible (dos pasos) → `empresas.estado='0'`, y
   revisar **cuáles** filas de `users` cambiaron: ninguna de staff ([C3](#c3--update-a-ciegas-sobre-usersstatus-bloqueante)).
8. Reactivar → confirmar que no re-habilitó a alguien deshabilitado por otro motivo.
9. `/conciliacion-bancaria`: subir un export **real** de Banco de Chile. Verificar que
   encuentra la fila de encabezado esté donde esté, que los montos parsean (`1.234` son
   1234 pesos, nunca 1,23 — un punto solo siempre es separador de miles), que la cola de
   revisión muestra sugerencias, que una liquidación Transbank queda etiquetada fuera de la
   cola de clientes, y que volver a subir el mismo archivo se rechaza por `file_hash`.
10. Confirmar un movimiento → un pago, por el camino de `RecordPayment`, idempotente ante
    doble clic.
11. Verificar que el archivo subido quedó en `storage/app/cartolas` y **no** es alcanzable
    por HTTP.
12. `/mi-cuenta` en ancho de teléfono: la barra colapsa y el `<details>` abre sin JS.

### F3 — Consultas de solo lectura (tinker)

Pre-vuelo, contra `mysql_replica`:

```php
DB::connection('mysql_replica')->select("SHOW CREATE TABLE suscriptor_payments");
DB::connection('mysql_replica')->select("SHOW CREATE TABLE users");

// cuántos usuarios con borrado lógico  (esperado: 567)  -> dimensiona C1
DB::connection('mysql_replica')->table('users')->whereNotNull('deleted_at')->count();

// cómo se reparte el staff -> dimensiona el backfill de C4
DB::connection('mysql_replica')->table('users')->where('isUvo', 1)
    ->selectRaw('nivelUvo, count(*) c')->groupBy('nivelUvo')->get();

// cuántas empresas tienen más de un usuario -> radio de explosión de C3
DB::connection('mysql_replica')->table('users')->select('empresa_id')
    ->groupBy('empresa_id')->havingRaw('count(*) > 1')->count();

// user_id: esperado 5 valores distintos -> confirma la premisa de C5
DB::connection('mysql_replica')->table('suscriptor_payments')
    ->selectRaw('count(*) n, max(amount) mx, count(distinct user_id) u')->first();

// planes que revientan decimal(8,2)  (esperado: 28) -> justifica la sentencia 3
DB::connection('mysql_replica')->table('datos_plan')->where('estado', 1)
    ->where('monto_plan', '>', 999999)->count();

// camino de lectura, humo
Customer::on('mysql_replica')->byRutOrId('77353398-9')->first();
```

Post-despliegue, contra la conexión viva (siguen siendo solo lectura):

```php
DB::table('users')->whereNotNull('role')->count();
DB::table('webpay_transactions')->selectRaw('status, count(*) c')->groupBy('status')->get();
DB::table('webpay_transactions')->where('status', 'pending')
    ->where('created_at', '<', now()->subHour())->count();          // tiene que quedarse en 0
DB::table('suscriptor_payments')->whereNotNull('external_reference')
    ->latest('id')->limit(5)->get();
DB::table('bank_movements')->selectRaw('status, auto_confirmed, count(*) c')
    ->groupBy('status', 'auto_confirmed')->get();
```

---

## 8. Preguntas abiertas para el dueño de db2026

Ninguna se puede responder desde este repo. Convendría cerrarlas antes de D4.

1. **[C4]** ¿`role` se rellena desde `isUvo`+`nivelUvo`, o se descarta `role` y se gatea
   directo por `isUvo`? (Cambia la sentencia 1 del DDL.)
2. **[C5]** ¿Qué convención de `user_id` para los pagos originados por el módulo?
   ¿Cuenta de servicio o `Auth::id()`?
3. **[C6]** ¿Algo lee `empresas.fechaVencimiento`, `empresas.pagos` o `empresas.plan`?
4. **[C3]** ¿El anfitrión lee `users.status` para algo, o el módulo puede dejar de
   escribirlo y gatear solo por `empresas.estado`?
5. **[4.6]** ¿Shells autónomas o re-skin al layout del anfitrión para la v1?
6. **[4.1.8]** ¿El anfitrión ya se apropió del alias `'admin'`, de los nombres de ruta, o
   de las URIs `/payment-alert`, `/mi-cuenta`, `/conciliacion-bancaria`?

Aparte, dos preguntas para el negocio, no para el DBA:

7. **[C8]** ¿Se calibra `settlement_lag_days` / `transbank_commission_pct` con depósitos
   reales antes del go-live, o se esconde la pestaña de liquidación?
8. **[C7]** ¿Quién consigue un export real de BCI, BancoEstado e Itaú para poder ofrecerlos
   en el dropdown?

---

## 9. Anexo: captura del estado "antes"

> **Pendiente.** Pegar acá la salida de D1 antes de aplicar nada. Sin esto, la sentencia 3
> se escribe de memoria, y `MODIFY` reescribe la definición completa de la columna.

```
-- SHOW CREATE TABLE users\G
-- (pegar aquí la salida de D1)
```

```
-- SHOW CREATE TABLE suscriptor_payments\G
-- (pegar aquí la salida de D1)
```

```
-- SELECT VERSION();
-- SELECT COUNT(*), MAX(amount) FROM suscriptor_payments;
-- (pegar aquí)
```

---

## Documentos relacionados

- `CLAUDE.md` — arquitectura y convenciones del módulo. **Autoritativo** (`AGENTS.md` está obsoleto).
- `docs/bank-reconciliation.md` — manual del operador de `/conciliacion-bancaria`.
- `docs/bank-credentials.md` — cómo conseguir credenciales reales de Transbank.
- `docs/laravel-for-fastapi-devs.md` — Laravel/Livewire explicado desde FastAPI/Spring Boot.
- `docs/integration-plan.md` — **superado por este documento.**
