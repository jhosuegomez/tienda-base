# First Live Install Checklist (tienda-base V1)

Run in order on the first real MySQL install (shared hosting). Stop at the
first red check and fix before continuing.

## 1. Environment

- [ ] `php -v` shows PHP >= 8.1.
  - Expected: `PHP 8.1.x` or higher.
- [ ] Create a `phpinfo.php` (delete it right after) and confirm: `pdo_mysql`,
      `mbstring`, `intl`, `gd`, `curl`.
  - Expected: all five present. The web installer checks the same list.

## 2. Installer run

- [ ] Create the empty MySQL database (utf8mb4) and open
      `https://YOUR-DOMAIN/install/index.php`.
  - Expected: all requirement checks green.
- [ ] Submit DB credentials + store name + admin email/password.
  - Expected: "Instalación completada" + cron URL with token shown once.
- [ ] Confirm `config.env.php` exists, `install.lock` exists, then **delete
      the `install/` directory**.
  - Expected: `install/index.php` no longer reachable.

## 3. Cron job

- [ ] Without token: `curl -s -o /dev/null -w "%{http_code}" https://YOUR-DOMAIN/cron.php`
  - Expected: `403`.
- [ ] With token: same URL + `?token=TOKEN`.
  - Expected: `200` with empty body.
- [ ] cPanel → Cron Jobs → `curl -s https://YOUR-DOMAIN/cron.php?token=TOKEN`
      every 5 minutes (or a free UptimeRobot HTTP monitor on the same URL).
  - Expected: no cron emails (silent success), `jobs` rows move to `done`.

## 4. Transferencia cycle (receipt → pagado → email)

- [ ] As a shopper: add a product, checkout with transferencia, open
      `index.php?r=shop/order/<id>`.
  - Expected: bank details + working receipt upload form.
- [ ] Upload a JPG receipt.
  - Expected: order moves to `en_verificacion`; row in `payment_receipts`
    with `source=cliente`; `email_receipt_received` job enqueued.
- [ ] After cron runs: check the store-admin inbox.
  - Expected: "Comprobante recibido" email (or a `failed` job in Pedidos if
    mail is misconfigured — configure Ajustes > Correo and retry).
- [ ] As admin (`admin/order/<id>`): accept.
  - Expected: status `pagado`; shopper receives the acceptance email.

## 4b. Catálogo, promociones, envío y FEL

- [ ] Creá una marca, asignala a un producto y definí precio actual + precio anterior.
  - Expected: la ficha y las tarjetas muestran marca, porcentaje y ahorro exactos.
- [ ] Creá un cupón de 10% con mínimo y un solo uso; aplicalo en checkout.
  - Expected: valida vigencia/mínimo, descuenta una vez y luego queda agotado.
- [ ] Buscá desde el encabezado y combiná categoría, marca y rango de precio.
  - Expected: el resultado y la paginación conservan todos los filtros.
- [ ] En Ajustes configurá Q25 de entrega, gratis desde Q300 y recogida activa.
  - Expected: checkout muestra las opciones antes de confirmar y guarda la elegida.
- [ ] Comprá con NIT y luego con `CF`.
  - Expected: nombre, NIT y dirección FEL aparecen en el pedido del cliente y admin.

## 5. COD cycle with surcharge math

- [ ] Enable COD with e.g. 5% in Ajustes. Checkout with contra entrega.
  - Expected: recargo line = `round(subtotal * 5 / 100, 2)`; total matches.
- [ ] Admin: `pendiente` → confirm → `preparacion` → ship → `enviado` →
      deliver → `entregado` with `payment_ref = COD:COBRADO`.
  - Expected: each button only appears in its valid from-state; audit log
    records every transition.

## 6. Card sandbox link (Cubo)

- [ ] Fill Ajustes > Tarjetas (provider Cubo, API base URL, keys, merchant,
      sandbox on) and press the Q1.00 probe.
  - Expected: a payment URL, no charge. **Confirm endpoint paths, auth
    scheme, status values, and the signature header against the official
    Cubo docs before going live** — see `core/CuboProvider.php` header.
- [ ] Bad-signature webhook: POST garbage to
      `index.php?r=webhooks/card&provider=cubo`.
  - Expected: `400`.

## 7. Abuse controls + audit

- [ ] Submit checkout 11 times in an hour (or lower the limit temporarily).
  - Expected: 11th shows the friendly Spanish message, no 500.
- [ ] Upload 6 receipts to one order in a day.
  - Expected: 6th blocked with a friendly message.
- [ ] Open `admin/audit` as admin; logged-out `admin/audit` URL.
  - Expected: login/register/transitions/uploads/settings saves listed;
    logged-out redirects to login.

## 8. Backup + size audit

- [ ] Ajustes-adjacent `admin/backup` → download.
  - Expected: `.sql` file starting with `-- Tienda base backup`, restorable
    via phpMyAdmin import.
- [ ] On the host: confirm no file over 800 KB and a small inode count
      (`find . -type f | wc -l` if SSH is available, else the file manager
      count).
  - Expected: ~40 files, largest ≈ 30 KB.
