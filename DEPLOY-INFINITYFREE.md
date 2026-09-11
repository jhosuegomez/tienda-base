# Despliegue en InfinityFree (tienda-base V1)

Guía paso a paso para un operador de agencia (no requiere saber programar).
Vale para InfinityFree gratis y premium; donde difieren, se indica.

## 1. Requisitos previos

1. Una cuenta en InfinityFree y un hosting creado (anote el nombre del dominio).
2. En el panel (Vista general → PHP): versión **PHP 8.1 o superior** (use 8.3 si aparece).
3. En el panel (Bases de datos MySQL): cree **una base de datos**, un **usuario** y anote:
   - Host de MySQL (algo como `sql123.infinityfree.com` — **NO es `localhost`**),
   - Nombre de la base, usuario y contraseña.
4. Tenga a mano el archivo `store-base-v1.zip` (el paquete clonable).

## 2. Subida de archivos

1. Abra el **Administrador de archivos** del panel (o su cliente FTP) y entre a la carpeta **`htdocs`**.
2. Suba **todo el contenido** del zip dentro de `htdocs` (los archivos `index.php`, `.htaccess`, y las carpetas `admin`, `core`, `pages`, `install`, etc. deben quedar directamente en `htdocs`, sin una subcarpeta intermedia).
3. **NO suba nunca**: `config.env.php` ajeno (cada tienda crea el suyo), `install.lock` de otra instalación, ni archivos sueltos de `cache/` o fotos de `uploads/` de otra tienda. El zip oficial ya viene limpio de todo eso.
4. Verifique extensiones abriendo `su-dominio/install/` : la página muestra la lista (PDO MySQL, mbstring, intl, gd, curl). Todo debe decir **OK**. Si algo dice FALTA, cambie la versión de PHP (paso 1.2) o pida ayuda al soporte.

## 3. Instalador web

1. Abra `su-dominio/install/` y complete: host/usuario/clave de MySQL (paso 1.3), nombre de la tienda, correo y contraseña del administrador.
2. Al terminar verá "Instalación completada" **más la URL del cron con su token**: cópiela y guárdela (el token solo se muestra ahí).
3. El instalador se **bloquea solo** (crea `install.lock`).
4. **Borre la carpeta `install/` del servidor**: selecciónela en el Administrador de archivos → Eliminar. Es obligatorio por seguridad.

## 4. Post-instalación (Ajustes, como administrador)

1. **Cuenta bancaria**: Ajustes → Cuentas bancarias → Agregar (banco, titular, número). Sin al menos una cuenta activa, el pago por transferencia no aparece.
2. **Contra entrega** (si aplica): actívela y fije el recargo %.
3. **Logo y colores**: Tienda (logo JPG/PNG/WebP, máx. 500 KB) y Diseño (colores, textos del inicio).
4. **Correo**:
   - Premium: puede usar `PHP mail()` o un SMTP externo.
   - Gratis: el plan gratis **no permite `PHP mail()`** → configure **SMTP externo** (p. ej. Brevo, Gmail con clave de aplicación) en Ajustes → Correo, con remitente válido.
5. **Cron (tareas programadas)**:
   - Premium: Panel → Tareas Cron → la URL guardada en el paso 3.2, cada 5 minutos.
   - Gratis: no hay cron → use un monitor gratuito (p. ej. UptimeRobot) que visite la misma URL cada 5 minutos. Sin esto, **los correos de pedido no se envían**.
6. **Tarjetas**: Ajustes → Tarjetas muestra la URL de webhook para pegarla en el panel de su proveedor (Cubo u otro) cuando contrate la pasarela.
7. **Pedido de prueba**: como visitante, agregue un producto, pague por transferencia, suba un comprobante y acéptelo como administrador. Revise que el correo de confirmación llegue (o que el trabajo aparezca como fallido en Pedidos, con Reintentar).

## 5. Clonado para el cliente N (<30 min)

1. Crear hosting + dominio del cliente en InfinityFree. (2 min)
2. Crear su base MySQL + usuario; anotar host (no localhost). (3 min)
3. Subir el **mismo** `store-base-v1.zip` a su `htdocs`. (5 min)
4. Abrir `/install/` y verificar extensiones OK. (1 min)
5. Ejecutar el instalador con los datos del cliente. (3 min)
6. Guardar la URL del cron + token. (1 min)
7. **Borrar `/install/`**. (1 min)
8. Ajustes: nombre, logo, colores, textos. (5 min)
9. Cuentas bancarias + COD + correo SMTP. (5 min)
10. Configurar cron (premium) o monitor (gratis) + pedido de prueba. (4 min)

## 6. Actualizar una tienda existente

Si la tienda se instaló antes del panel de cuenta o de los MUST de catálogo (marcas, cupones, descuentos, envío y FEL):

1. **Respaldo primero**: como administrador, vaya a Respaldo y descargue el `.sql`.
2. Suba los archivos nuevos/cambiados (o todo el zip menos `config.env.php` e `install.lock`): en particular `install/migrate.php`, `schema.sql`, `seed.sql`, `core/`, `pages/`, `views/`, `admin/`, `assets/`, `index.php`.
3. Como administrador, abra `su-dominio/install/migrate.php`: verá qué falta (tablas, columnas y páginas de ayuda) y el botón **Aplicar migración**.
4. Verifique: entre a Mi cuenta, guarde un favorito, busque un producto y abra Ajustes → Envíos y recogida.
5. Opcional: ahora sí puede **borrar `/install/`** otra vez (la migración ya no hará falta).

## 7. Límites del plan gratis y cuándo migrar

- **Límites aprox. del gratis**: tope diario de visitas (hits), ejecución por petición de ~10–20 s, **sin tareas cron**, **sin `PHP mail()`**, suspensión por inactividad/recursos.
- **Migra a premium/VPS cuando**: la tienda vende a diario, necesita correos inmediatos y confiables, webhooks de tarjeta en tiempo real, copias de respaldo automáticas o más de un catálogo grande.
- El plan premium de InfinityFree (iFastNet) habilita cron real y correo; un VPS, control total.

## 8. Troubleshooting (6 típicos)

| # | Síntoma | Causa probable | Fix |
|---|---------|----------------|-----|
| 1 | Error 500 en todo el sitio | Extensión PHP faltante o `config.env.php` con host `localhost` | Revise `/install/` (paso 2.4); corrija el host al de MySQL del panel |
| 2 | El instalador no se bloquea / se puede reabrir | Se subió un `install.lock` viejo o se borró | Si la tienda ya funciona, borre `install/`; si no, elimine `install.lock` y reinstale |
| 3 | Los correos no llegan | Plan gratis sin SMTP, o remitente vacío, o cron sin correr | Configure SMTP externo + remitente; verifique cron/monitor; reintente desde Pedidos |
| 4 | Las imágenes no suben | Archivo > límite (logo 500 KB, comprobante 2 MB), formato no permitido, o carpeta `uploads/` sin permiso de escritura | Comprima la imagen; use JPG/PNG/WebP (PDF solo en comprobantes); fije permisos 755 a `uploads/` |
| 5 | El cron no corre | URL/token mal copiados, o plan gratis sin monitor | Regenere el token en Ajustes y actualice la tarea; en gratis use UptimeRobot |
| 6 | El sitio se ve sin estilos | No se subió `assets/tailwind.min.css` o el navegador cacheó el CSS viejo | Suba `assets/` completo; recargue con Ctrl+F5; verifique que existe `assets/tailwind.min.css` |
