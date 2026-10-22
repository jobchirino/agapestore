# AGENTS.md

Inventory and POS (sales register) app in Spanish-speaking domain, built on the Laravel Livewire starter kit (Fortify auth, Flux UI). Composer pins **Laravel 13** (`^13.17`), not 12. PHP `^8.3`. Database is SQLite.

## Commands

- Verification order (what CI runs via `composer ci:check`): `composer lint:check` -> `composer types:check` -> `composer test` (`composer test` runs `config:clear`, then lint, types, then `php artisan test`).
- Format: `composer lint` (Pint, `preset: laravel`). Pint check fails on import/FQCN style, so run it before finishing.
- Types: `composer types:check` runs PHPStan level 7 on `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`. The script already passes `--memory-limit=1G` (the 128M default crashes). Current status: 0 errors.
- Tests: `php artisan test`. Single file: `php artisan test tests/Feature/DashboardTest.php`. Single test: `php artisan test --filter=test_name`. Test DB is in-memory SQLite (`phpunit.xml`).
- Frontend: `npm run build` / `npm run dev`. Scripts call `vp` (vite-plus), not `vite`. `.npmrc` sets `ignore-scripts=true`.
- Setup: `composer setup` (copies `.env`, `key:generate`, `migrate --force`, `npm install`, `npm run build`).
- Seed: `php artisan migrate --seed`. Creates `admin@negocio.com` / `password` (see `database/seeders/DatabaseSeeder.php`).

## Environment gotchas

- `APP_KEY` must be set in `.env`. Without it, every test fails with "No application encryption key". Generate it with `php artisan key:generate` (or `composer setup`).
- Tests that render layouts need built assets. Without `npm run build`, they fail with "Vite manifest not found at public/build/manifest.json".
- `database/database.sqlite` must exist before `migrate`. `composer setup` does not create it.
- Shell is bash on Windows: use `export VAR=...`, not `set VAR=...`.
- Windows frontend install: plain `npm install` crashes on npm 10.9 (arborist peer-set bug). Use `npm install --legacy-peer-deps`, then `npm install --no-save --legacy-peer-deps @rolldown/binding-win32-x64-msvc@1.2.14`. The `--no-save` binding is removed by the next `npm install`, so repeat that step after reinstalling.
- `UploadedFile::fake()->image()` needs the PHP `gd` extension (enabled in `C:\php83\php.ini`).
- Upload limits: PHP must allow more than the business limit or the request is dropped before validation (`upload_max_filesize=16M`, `post_max_size=20M`). Livewire's `temporary_file_upload.rules` is deliberately `max:12288` (12 MB) so the **component's** 5 MB rule owns the message. If you lower the Livewire rule, oversized files are rejected with a generic message before the component validates. The POS also pre-checks size/type in the browser with `$wire.upload()`.
- Locale is `es` (`APP_LOCALE`), with `lang/es/validation.php` covering the framework rules. `APP_FALLBACK_LOCALE` stays `en` for anything missing.
- Auth is closed: public registration, password reset and **two-factor authentication** are disabled in `config/fortify.php` (do not re-add `Features::registration()`, `Features::resetPasswords()` or `Features::twoFactorAuthentication()`); their routes/views/tests are removed. Credentials are managed with `php artisan agape:user {email} {password} {--name=...}`, which hashes with `Hash::make`, marks the user verified and creates or updates it. The login uses the Ágape layout (`layouts/auth/simple.blade.php`); `package-lock.json` is tracked in git.
- **Passkeys** use `laravel/passkeys` v0.2.1 (already installed, with Fortify). Do not add another WebAuthn package. Fortify **overwrites the whole `passkeys.*` config at boot**: `redirect` comes from Fortify's home (`/dashboard`) and `management_middleware` from `fortify-options.passkeys.confirmPassword`, disabled in `config/fortify-options.php`, so editing `config/passkeys.php` has no effect. Passkeys are managed from `pages::passkeys.manager`, mounted in Settings → Seguridad and opened by the `open-passkeys` event (there is no sidebar entry on purpose); the login uses `<x-passkey-verify />` with passwordless discoverable credentials. The RP ID derives from `APP_URL`: passkeys created on `localhost` will **not** work on the production domain and must be re-registered there over HTTPS. Full ceremonies can only be tested in a browser; PHPUnit covers the endpoints, config and management component.
- Login is fully in Spanish (labels, passkey button and messages): `lang/es/auth.php` provides `failed` / `throttle` / `password`. Session lasts **120 minutes of inactivity** (`SESSION_LIFETIME=120`, database driver, not expire-on-close); "Recordarme" issues Laravel's 400-day recaller cookie, so it keeps the user signed in after the session expires.
- Settings (`/settings`) are reduced to three screens, all in Spanish: **Perfil** (name only), **Seguridad** (password change + passkey management) and **Apariencia** (theme). Email editing, email-verification UI and account deletion were removed on purpose; do not reintroduce them.
- `php artisan storage:link` must exist for product images and payment proofs to be served from `public/storage`. Already created in this checkout.
- SQLite stores whole-number prices as INTEGER, so `(a - b) / b` truncates. In raw SQL, multiply by `100.0` first (see the markup average in `pages/inventory.blade.php`).
- `lockForUpdate()` compiles to nothing on SQLite (`SQLiteGrammar::compileLock` returns `''`). On SQLite, the stock guard is the conditional `UPDATE ... WHERE total_stock >= quantity` inside `SaleService`. Keep that guard even when you add locks.

## Structure

- Routes: `routes/web.php` (dashboard requires `auth` + `verified`), `routes/settings.php`.
- Pages are Livewire 4 single-file components under `resources/views/pages/` (`auth/`, `settings/`, `dashboard.blade.php`, `inventory.blade.php`, `pos.blade.php` + child `pos/client-form.blade.php`, `sales/history.blade.php` + child `sales/detail.blade.php`, `clients.blade.php` + children `clients/form.blade.php` and `clients/detail.blade.php`). The component PHP sits on top, Blade below. Pages render inside `resources/views/layouts/app.blade.php` automatically (`livewire.component_layout`). Flux components live in `resources/views/flux`.
- Inventory: route `inventario` (name `inventory`), component `pages::inventory`, tests in `tests/Feature/InventoryPageTest.php`. It also manages categories (create, rename, delete) from the "Categorías" modal.
- POS: route `registrar-compra` (name `pos`), component `pages::pos`, tests in `tests/Feature/PosPageTest.php`.
  - The cart stores only `[product_id => quantity]`. Prices and stock are re-read once per render from the DB (`#[Computed] lines`), so the displayed total always matches what `SaleService` charges. Do not copy product data into the cart.
  - `pages::pos.client-form` is an isolated child component for quick client creation. It dispatches `client-created`; the POS listens with `#[On('client-created')]`. Keep it separate: its state must not re-render the POS (which has `wire:model.live` searches).
  - The cart error (`cartError`) is a component property, not an error-bag key, because the service reports it under `items`, which has no matching field.
  - The draft survives leaving the page via `#[Session]` on `cart`, `client_id`, `payment_method`, `payment_type`, `initial_payment` and `cartSavedAt` (cleared automatically after 8 hours in `mount()`). The payment proof cannot persist: it is a temporary upload. There is an Alpine guard on `livewire:navigating` that warns before leaving when a proof is loaded. Note: `#[Session]` stores under an internal key (`lw` + `crc32(componentName.propertyName)`), so seeding the session directly in tests does not work; drive it through the component.
  - "Cantidad a agregar" (`quickQuantity`) is applied by clicking a search result (`addProduct`), then resets to 1. The cart's `+` button uses `increment()` and always adds exactly one.
- Sales history: route `historial-ventas` (name `sales.history`), component `pages::sales.history`, tests in `tests/Feature/SalesHistoryTest.php`; payment service tests in `tests/Feature/SalePaymentTest.php`.
  - Filters live in the URL via `#[Url]` and reset pagination through `updated()`. KPIs and the table share `filteredQuery()`, so they always agree.
  - The table must eager-load `with(['client', 'user', 'saleProducts.product'])` and `withSum('payments', 'amount')`; otherwise `totalProfit()` / `balanceDue()` trigger an N+1. A regression test counts queries with 3 vs 15 sales.
  - Detail and payments live in the isolated child `pages::sales.detail`. The parent opens it by dispatching `open-sale` with the id; do not rely on child props updating from parent state. The child must **never** call `Flux::modal(...)->show()` from `mount()`: the parent re-renders pass `sale-id` again and the mount would reopen the modal by itself.
  - `saleId` is deliberately **not** URL-bound. The POS hands the sale off through the session (`open_sale` => `['id' => ..., 'at' => timestamp]`); the history consumes it once in `mount()` with `session()->pull()` and ignores it after 10 minutes. Do not pass the sale id in the URL: Livewire keeps query params that were present at mount, and client-side URL cleanup proved unreliable, which made the modal reopen on reload.
  - The detail modal uses Flux's `wire:close="closeDetail"`, which dispatches `sale-closed`; the history listens and sets `saleId = null`.
  - For the same reason, `clearFilters()` redirects to the clean route (`navigate: true`) instead of resetting properties in place. The "Limpiar filtros" button only renders when `hasActiveFilters` is true.
  - Search accepts `#12` and plain `12` as the sale ID, plus client name/phone. The ID is the first column of the table; the placeholder says so.
- `Sale::PAYMENT_METHODS` is the single source for payment method keys and labels (POS, filters, payments). Use it instead of inline arrays.
- Dashboard: `/dashboard` (name `dashboard`) is now `pages::dashboard`, the executive home and Fortify's `home`. Quick actions: POS link, "Nuevo Producto" (puts `open_create_product_modal` in the session with a 5-minute expiry and redirects to inventory; `pages::inventory::mount()` pulls it and opens the form), inventory and history links. Panels: critical stock (`<= 3`) and last 5 sales (opens one by putting `open_sale` in the session).
- Clients: route `clientes` (name `clients`), component `pages::clients`, tests in `tests/Feature/ClientsPageTest.php`. The table uses `Client::withMetrics()` (`withCount`, `withSum`, `withMax` plus an `addSelect` for pending) so aggregates come in the page query. `purchasesCount()`, `totalSpent()`, `pendingBalance()` and `lastPurchaseAt()` read the loaded aggregates and must keep using `array_key_exists` (withSum/withMax return NULL with no rows). Deleting a client is blocked when it has any sale (`ClientService::deleteClient`), to preserve the accounting trail. The form and profile live in isolated children opened by `open-client-form` / `open-client` events.
- Sidebar (`resources/views/layouts/app/sidebar.blade.php`): Inicio, Inventario, Registrar Compra, Historial de Ventas and Clientes, all routed.
- The sidebar is collapsible on desktop (`<flux:sidebar collapsible>`), and Flux persists the collapsed state in `localStorage` (`flux-sidebar-collapsed-desktop`). Do not manage that state yourself. Flux controls the width (`w-64` <-> `w-14`), so do not hardcode a width on the sidebar. Custom markup inside the sidebar must adapt with the `in-data-flux-sidebar-collapsed-desktop:` variant (see the "Pronto" rows and the theme selector).
- Theme: `@fluxAppearance` (in `partials/head`) owns light/dark persistence in `localStorage`. The sidebar selector uses `x-model="$flux.appearance"`. Do not hardcode a `dark` class on `<html>`.
- KPI figures use the `.kpi-figure` class (`app.css`): fluid `clamp()` size, bold, tabular figures, `whitespace-nowrap`. Never hardcode a pixel size for a money/count figure; long amounts overflow their card. Large figures inside narrow panels also need `whitespace-nowrap`.
- Design tokens (`resources/css/app.css` `@theme`): one family for the whole app — `font-sans` and `font-display` both map to **Manrope**. Headings differ by weight/size/tracking, not by typeface. Numbers use Manrope bold with `.tnum` (tabular figures). Fonts load from Google Fonts via CSS `@import`, not the Vite font plugin. `.kpi-card` (container) + `.kpi-figure` (container-relative `clamp`) keep long amounts inside their card. Colors: `agape` (#BE185D), `agape-dark` (#FF2E93), `agape-soft`, `profit-*` (emerald), `warn-*` (amber), `canvas`, `ink`, `panel`, `panel-raise`. Flux's accent is fuchsia, so `variant="primary"` buttons follow the brand.
- Logo file is `public/images/logo-agapestore.png` (the brief mentioned `logo-agape.jpeg`, which does not exist). It is a transparent PNG with black + fuchsia strokes, so dark mode keeps a white plate behind it (sidebar chip and auth layout) to keep the black elements visible. Favicons are brand assets generated from a cropped "AS" mark on white (`favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`) plus the full logo as `apple-touch-icon.png`; `partials/head` and `welcome.blade.php` declare them.
- Pagination views are published in `resources/views/vendor/livewire/`. Active page is fuchsia. Re-publishing overwrites this.
- Fortify actions live in `app/Actions/Fortify/`. Config in `config/fortify.php`.
- Domain models in `app/Models/`: `Category`, `Product`, `Client`, `Sale`, `SaleProduct`, `User`.
  - `SaleProduct` maps to table `sales_products` (set via `$table`).
  - `Category` keeps a normalized `slug` (`Str::slug`, set by a `saving` hook) with a unique index. Uniqueness is checked on the slug, so "Bisutería", "bisuteria" and "BISUTERIA" collide. `name` stays as typed by the owner.
  - `Product` uses soft deletes. Deleting a product must not break sales history.
  - `Sale.date` is the business date, separate from `created_at`. Use it for filters and reports.
  - `SaleProduct.unit_price` (sale price, always `detal_price`) and `SaleProduct.unit_cost` (cost, `major_price`) are frozen at sale time. Never recompute them from the current product.
  - Money columns are `decimal(10,2)` and cast as `decimal:2`, so they are strings in PHP. Use `App\Support\Money` (`toCents` / `fromCents`) for arithmetic.
  - Profit helpers: `Product::profitMarginAmount()` (`detal_price - major_price`), `Product::profitMarginPercentage()` (margin over `detal_price`, in %), `SaleProduct::profit()`, `Sale::totalProfit()`.
  - Query filters are Local Query Scopes on the models: `Product::search/byCategory/inStock`, `Client::search`, `Sale::filterByPeriod`.
- Business logic services live in `app/Services/`: `ProductService` (create, update, `adjustStock`), `SaleService::createSale`, `ClientService` (create, update), and `CategoryService` (create, update, delete).
- Factories in `database/factories/`: `Category`, `Product`, `Client`, `Sale`, `SaleProduct`, `User`.

## Business rules

- **Stock deduction:** every Sale creation must run inside `DB::transaction()`. Before subtracting stock, lock or validate that `product->total_stock >= quantity` for each line, and fail the whole sale if any line is short.
- **Date filters:** all day, week, month, and date-range filters for sales history must use the `sales.date` column, never `sales.created_at`.
- **Pricing:** `detal_price` is the public sale price. `major_price` is the wholesale COST the owner pays the supplier. It is required and `>= 0`. Clients never buy at `major_price`.
- **Profit:** each sale line freezes `unit_price` (= `detal_price`) and `unit_cost` (= `major_price`). Profit is `(unit_price - unit_cost) * quantity`.
- **Frontend (Livewire):** the cart has no wholesale/retail selector. Inventory shows the margin per product (`profitMarginAmount`, `profitMarginPercentage`). Sales history shows net profit per sale (`totalProfit`).
- **Margin definition:** `profitMarginPercentage` = (`detal_price` - `major_price`) / `major_price` * 100 (markup over cost, not margin over price). Returns 0.0 when cost is 0.

## Working style: Livewire mentor role

- The developer's first Livewire project. They come from React/Next.js and REST APIs. Explain Livewire step by step.
- Version: `composer.json` pins `livewire/livewire ^4.1`, not v3. Explain with the v4 API and say when a v3 example would differ.
- When creating or changing a Livewire component, first explain how the data flows: public properties (state), lifecycle hooks (`mount`, `updated`), Blade directives (`wire:model`, `wire:model.live`, `wire:click`, `wire:loading`), attributes (`#[Computed]`, `#[Validate]`), pagination (`WithPagination`), and file uploads (`WithFileUploads`).
- Use short React/Next.js analogies where they help. Example: public properties are like `useState`, and `wire:click` methods are like Server Actions.
- Keep code comments brief and didactic in Livewire components.
- Do not generate a full screen at once. Explain the flow of that component first.

## Architecture: services for writes, scopes for reads

- Complex, critical writes (registering sales, adjusting inventory) go in service classes in `app/Services/`. Components and controllers call services, not raw write queries.
- Search and date filters are Local Query Scopes on `Sale`, `Product`, and `Client`. Chain them with native pagination (`->paginate()`) in Livewire.
- `SaleService::createSale` runs inside `DB::transaction()`. For each product it locks the row with `lockForUpdate()`, checks `total_stock >= quantity`, and throws `ValidationException` naming the product and the remaining stock. A failure rolls back the whole sale, so no stock is deducted for any item.
- Sale prices come from the locked product, never from client input: `unit_price = detal_price`, `unit_cost = major_price`. The total is computed in integer cents.
- All sales history filters (day, week, month, date range) query `sales.date`, never `created_at`.
- Payment proofs are stored on the `public` disk under `payment-proofs/`, before the transaction. If the sale fails, `createSale` deletes the file, so no orphans remain.
- Dates are displayed with `dateLabel()` on `Sale` and `SalePayment` (`d/m/Y g:i A`, 12-hour clock). `datetime-local` inputs follow the browser's format and cannot be forced to AM/PM; next to them we render a `datePreview()` line ("Se registrará: ...").
- Cash sales are **paid in full at creation**: `paidAmount()` returns the total and `balanceDue()` returns 0. The detail hides Inicial/Abonos/Pendiente and "Movimientos de pago" for `contado`; the payment proof still shows. UI copy says "Pendiente" (not "Saldo"); code keeps `balanceDue`.
- Client phones accept only two normalized formats: national `0` + 10 digits (`04124737116`) or international `+` + 8–15 digits (`+5841247377116`). `ClientService` strips spaces, dashes, dots and parentheses before validating and stores only digits plus the leading `+`.
- Credit sales: `sales.initial_payment` (decimal, default 0) holds the down payment. Subsequent payments go to `sale_payments` (`SalePayment`), never to `initial_payment`. The pending balance is **derived** via `Sale::balanceDue()` (`total - initial - sum(payments)`), never stored. `SaleService` rejects a negative initial, an initial greater than the total, and any initial on a `contado` sale. The POS shows the field only for `credito`; it is part of the session draft.
- `SalePaymentService::registerPayment` stores the proof before the transaction, locks the sale row with `lockForUpdate()`, re-checks the balance, and deletes the proof if it fails. `deletePayment` removes the row and its proof; only manual corrections use it.
- Payment dates are compared **by whole day**: the abono cannot be dated before the sale's day and cannot be in the future; the same day is allowed at any hour. Enforced in the service (authoritative) and mirrored in the detail component, with the date input `min` set to the start of the sale day.
- The detail modal header must keep its top-right corner free (`pe-10`, pills on their own row): Flux renders its close X absolutely at `top-0 end-0` and it collides otherwise.
- `withSum` returns **NULL** (not missing) when a sale has no payments. `paidAmount()` must use `array_key_exists('payments_sum_amount', getAttributes())`; checking for null alone reintroduces the N+1.
- `Sale::paidAmountSql()` (annotated `@return literal-string`) defines "credit pending/paid" in SQL. Use it in `filterByStatus` and in the balance KPI so the table and the card can never disagree.
- Services report validation errors by database column name (`name`, `quantity`). Component properties may differ (`categoryName`, `adjustQuantity`), so map the keys when catching `ValidationException`. Unmapped keys fail silently: the server has the error but the UI shows nothing.
- Flux renders the error itself for any field with a `label` (its name comes from `wire:model`). Do not add a second `<flux:error name="...">` for those or the message shows twice. Add an explicit `<flux:error>` only when the Flux field has no `label` prop (as with the custom-layout file input).
- `@disabled($x)` breaks inside a `<flux:button>`: Blade treats the directive as an attribute and emits invalid PHP (parse error). Use the prop instead: `:disabled="$x"`. Plain HTML elements are fine with `@disabled`.
- Success feedback is always a Flux toast `variant="success"`. Validation failures use `<flux:error>` plus a `variant="danger"` toast. See DESIGN.md "Feedback & Notifications".
- Destructive actions never use `wire:confirm`. Split them into `openDelete(id)` (shows a Flux modal) and `confirmDelete()` (performs the delete). Product deletes are soft deletes, so sales history survives.
- Do not bind inputs with `wire:model.live` in the same form as deferred `wire:model` fields. A live round-trip re-renders the form and can clobber an unsynced field (dropped digits). Compute live previews client-side with Alpine (see the profit preview in `pages/inventory.blade.php`).
- Categories with products (including soft-deleted ones) cannot be deleted. `CategoryService::deleteCategory` checks this first.

## Starter-kit scaffolding (do not treat as app code)

- The installer scripts `chisel.php`, `chisel-paths.php`, and `rename_livewire_files.php` were deleted, along with their `composer.json` hooks. Do not recreate them.
- `InstallFeaturesCommand` was removed, along with its `composer.json` hooks (`post-update-cmd`, `extra.laravel.installer`). Do not recreate it.
- `@chisel-*` / `@end-chisel-*` comment markers in `config/fortify.php`, `vite.config.js`, and Blade views drive feature removal. Keep them intact when editing those files.
- Auth features (registration, 2FA, passkeys, email verification, password confirmation) are toggled in `config/fortify.php`. `tests/TestCase.php::skipUnlessFortifyHas()` skips tests for disabled features.
