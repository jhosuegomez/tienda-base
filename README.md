# Tienda Base (white-label PHP store, golden copy)

Slice-gated build: each slice adds one vertical capability. This repo is the clonable
golden copy; every client store starts as a file copy of it.

## What slice 1 covers

### Current implementation (September 2026)

The slice descriptions below are historical. Current administration starts at
`index.php?r=admin/home`: real pending-payment, receipt-review, preparation,
low-stock (active products, <= 5 units), and pending-review indicators link to
their filtered lists. The store header/footer remain shared with administration.

Settings has General, Appearance, Homepage, Payments, Delivery, Notifications,
and Advanced sections. Appearance previews custom colors and palettes locally;
only submitting a palette or Save design persists changes. Reset restores the
currently saved form values, not an earlier database revision.

`views/product_card.php` is the shared catalog card for home, categories, brands
and search. `$cardOptions['showCategory']` controls its category label.

Category images are JPG/PNG/WebP up to 2 MB. Category and image reference writes
commit together; the previous upload is retired only after successful commit.
Retired files are retained under `uploads/categories/retired/` for recovery.
Include this directory in backups; no automated purge is performed.

Run focused checks with PHP CLI and the mbstring extension enabled:
`php -d extension=mbstring tests/product_card_smoke.php`,
`php tests/category_images_smoke.php`, and
`php -d extension=mbstring tests/admin_workspace_smoke.php admin/home`.
The workspace test uses isolated templates, not a live administrator session.

- Front controller + tiny router (`index.php`): `?r=home`, `auth/login`,
  `auth/logout`, `auth/register`, `admin/settings`, 404 handling.
- Core: `core/Database.php` (PDO singleton, generic error page), `core/helpers.php`
  (`esc`, `csrf_token`, `csrf_check`, `redirect`, `money_q` in Quetzales, `setting`),
  `core/Auth.php` (secure session, ARGON2ID login, roles `store_admin`/`shopper`,
  30-min idle timeout, 5 tries/15 min rate limit via `login_attempts`),
  `core/Settings.php` (DB + `cache/settings.php` file cache regenerated on write).
- Data: `schema.sql` (full schema, including brands, coupons, customer panel,
  fulfillment and FEL snapshots), `seed.sql` (default settings + help pages).
- Web installer: `install/index.php` (env checks, DB form, admin creation,
  writes `config.env.php`, imports SQL, writes `install.lock`).
- UI: `views/layout.php` (header/footer, CSS vars from settings, cache-bust),
  `assets/theme.css`, `pages/home.php` (hero + product grid with Spanish
  empty state), `pages/login.php`, `pages/register.php`.
- Admin: `admin/settings.php` (Tienda incl. logo upload JPG/PNG/WebP max 500 KB
  with MIME+extension checks, Diseno, Cuentas bancarias CRUD, Contra entrega,
  Tarjetas generic slot with provider select and readonly webhook URL).
- Security: root `.htaccess` denies `config.env.php`, `install.lock`, `*.sql`,
  `cache/`; `uploads/.htaccess` disables PHP execution.

## Local run

```bat
cd tienda-base
php -S localhost:8000
```

Then open `http://localhost:8000/install/index.php` (requires a local MySQL
database; create the empty DB first). On POST the installer writes
`config.env.php`, imports `schema.sql` + `seed.sql`, creates the `store_admin`
and writes `install.lock`.

`config.env.php` is created only by the installer. For reference see
`config.env.example.php` (placeholders, safe to commit).

## Frontend build (Tailwind, slices 6 y 10)

Styling is free Tailwind v4 utilities only (no paid UI kits). Per-store
theming survives via CSS vars (`bg-[var(--primary)]` etc., fed by Ajustes).

- `assets/input.css` — v4 entry (`@import "tailwindcss"`, `@source` for
  views/pages/admin), tokens semánticos, superficies, estados de foco y temas.
- `assets/tailwind.min.css` — compiled output, referenced with `?v=`
  cache-bust from `views/layout.php`. All CSS now originates in `assets/input.css`.
- `assets/appearance.js` — local appearance preview and unsaved-change warning;
  ship this JavaScript file alongside the compiled CSS and other image assets.
- `assets/theme-controls.css` — empty compatibility placeholder, no longer loaded.
- Rebuild (Windows, from the repo root):
  `C:\tools\tailwindcss.exe -i assets\input.css -o assets\tailwind.min.css --minify`
  using the official standalone executable (kept outside the repo).

## Clone flow (new client store)

1. Copy this whole directory to the new project folder.
2. Do NOT copy `config.env.php` (real credentials) or `cache/settings.php`
   (regenerates itself). Do NOT copy `install.lock` if you want the installer.
3. Upload via FTP/file manager, create the MySQL DB, run the web installer.
4. Delete the `install/` directory from the server after installing.

## Slice map

- Slice 1: foundation, auth, settings, installer, home. No catalog, cart, or orders UI.
- Slice 2 (this): catalog — `admin/categories` CRUD (delete BLOCKED while
  products exist; reassign or delete products first), `admin/products` list +
  delete (removes image files), `admin/product` create/edit (slug auto-unique,
  price >= 0, stock int >= 0, status Activo/Inactivo/Borrador, plain-text
  description escaped on output) with 1 main + max 4 gallery images (JPG/PNG/
  WebP, 500 KB each, random rename under `uploads/products/`), public
  `shop/category/<slug>` (12 per page) and `shop/product/<slug>` (gallery,
  breadcrumb, disabled Agotado button when stock is 0), categories menu in the
  layout, home grid linked to real latest products. Schema adds
  `products.description` + `product_images.is_main` (fresh golden copy, no
  migration needed). No cart or checkout yet.
- Slice 3 (this): cart + checkout — `core/Cart.php` (guest session cart lazily
  merged into the user cart without touching Auth, price snapshots, stock
  clamp + Spanish notices, pure `totals()` math, transactional `placeOrder()`),
  `shop/cart` (add/update/remove, PRG + flash), `shop/checkout` (guest + logged,
  transferencia needs an active bank account, COD needs `cod.enabled=1`,
  breakdown with COD surcharge %), `shop/order/<id>` confirmation (bank details +
  receipt-upload stub for transferencia, conditions for COD), `account/orders`
  + `account/order/<id>` (owner-only, Spanish statuses). Schema adds
  `orders.contact_name/phone/address`. No card charging, emails, or cron yet.
- Slice 4 (this): receipts + admin orders + jobs/cron + emails + card backend.
  Receipts (JPG/PNG/WebP/PDF, 2 MB, `uploads/receipts/`) on confirmation +
  account detail (owner-only) and admin order detail (manual, source=admin);
  upload moves pendiente_pago/rechazado → en_verificacion, history rows kept.
  Admin `admin/orders` (filter + id/email search, 20/page, failed-jobs retry)
  and `admin/order/<id>` (transitions via `core/OrderFlow`, stock restored on
  cancel, COD cobrado marker `COD:COBRADO` on deliver). `cron.php?token=`
  (cPanel every 5 min, UptimeRobot fallback) processes max 25 email jobs/run
  (~8s slice) via `core/Mailer.php` (php mail() or dependency-free SMTP);
  producers only enqueue. Card backend: `core/PaymentProvider.php` interface +
  registry (only Cubo implemented), `core/CuboProvider.php` (server-side links,
  return verified by status re-query, HMAC webhook idempotent by payment_ref),
  `webhooks/card` route, sandbox Q1 probe button in Ajustes. Confirm Cubo
  endpoint paths/auth/statuses against official docs before going live.
- Slice 5 (this, V1): hardening — file-based rate limits (checkout 10/h,
  register 5/h, receipts 5/order/day; friendly Spanish messages, fail-open),
  `audit_log` table + `admin/audit` viewer (login/register/order/receipt/
  settings/backup events), pure-PHP chunked DB export at `admin/backup`,
  card checkout radio behind full Cubo credentials (server-side link, order
  stays `pendiente_pago` until verified, no raw card fields), stale-running
   job recovery in cron. See FIRST-LIVE-INSTALL-CHECKLIST.md before going live.
- Slice 8 (this): Kemik-style customer account panel — `account/` hub (greeting
  + tiles), profile (name/phone/email/password), `user_addresses` CRUD +
  checkout picker/prefill/save-on-order, `favorites` w/ heart toggles on
  cards/detail, `reviews` (delivered|paid-only, one per product/user, editable,
  `admin/reviews` moderation; averages on cards/detail), `notifications`
  center + header bell (fed by order/receipt event hooks), `help_pages`
  (seeded ES content, public `help/<slug>`, `admin/help` editor), shared
   account sidebar. V2 (explicitly NOT built, no UI stubs): points/referrals,
   saved cards.
- Migración slice 8: `install/migrate.php` (sesión store_admin + CSRF POST)
  aplica todos los deltas en BDs viejas de forma idempotente (lee
  `schema.sql`/`seed.sql`, incluyendo panel de cliente y los MUST Kemik);
  ver DEPLOY-INFINITYFREE.md §6.
- Slice 9: seis MUST inspirados en el flujo de compra guatemalteco: precio
  anterior/%/ahorro, cupones con vigencia y cupo, marcas, búsqueda global con
  filtros de categoría/marca/precio/orden, entrega configurable (tarifa,
  mínimo gratis y recogida) y datos de factura FEL (nombre, NIT/CF y dirección)
  guardados como snapshot en cada pedido. Prueba CLI: `php tests/musts_smoke.php`.
- Slice 10: storefront reconstruido con un sistema visual ecommerce uniforme:
  cabecera comercial, buscador central, tarjetas, catálogo, ficha de producto,
  carrito y checkout responsive, foco visible, movimiento reducido y soporte
  del tema nocturno.
- Slice 11: hero comercial Bagisto convertido en slideshow accesible. El
  administrador puede agregar, editar, reemplazar,
  ordenar y eliminar hasta cinco diapositivas desde Ajustes; imágenes JPG, PNG
  o WebP de hasta 3 MB y mínimo 800 × 450 px.
- Slice 12: sistema visual completo reconstruido con el lenguaje Bagisto:
  navegación, portada, catálogo, producto, carrito, checkout, cuenta, panel
  administrativo, formularios, tablas y footer comparten una sola hoja de
  estilos y los mismos tokens configurables.
- Slice 3: cart (session + DB cart, add/update/remove).
- Slice 4: checkout + orders + bank-transfer receipts + COD surcharge.
- Slice 5: card provider integration behind the generic slot.
- Slice 6: jobs/worker, email/WhatsApp notifications, hardening pass.
