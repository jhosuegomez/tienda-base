-- Tienda base, slice 1: default settings only. No users, no products.
SET NAMES utf8mb4;

INSERT INTO settings (`key`, `value`) VALUES
  ('store.name', 'Mi Tienda'),
  ('store.logo', ''),
  ('theme.primary', '#1a73e8'),
  ('theme.accent', '#f9ab00'),
  ('theme.bg', '#ffffff'),
  ('theme.text', '#202124'),
  ('theme.font', 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'),
  ('theme.preset', 'moderno'),
  ('home.hero_title', 'Bienvenido a nuestra tienda'),
  ('home.hero_subtitle', 'Productos seleccionados con entrega en toda Guatemala.'),
  ('cod.enabled', '0'),
  ('cod.surcharge_pct', '0'),
  ('cod.conditions', ''),
  ('shipping.delivery_enabled', '1'),
  ('shipping.flat_amount', '25'),
  ('shipping.free_threshold', '300'),
  ('shipping.pickup_enabled', '1'),
  ('shipping.pickup_label', 'Recoger en tienda'),
  ('shipping.pickup_address', ''),
  ('card.enabled', '0'),
  ('card.provider', 'otro'),
  ('card.status', 'sin configurar'),
  ('card.sandbox', '1'),
  ('card.api_url', ''),
  ('cron.token', ''),
  ('mail.driver', 'php'),
  ('mail.from', ''),
  ('mail.host', ''),
  ('mail.port', '587'),
  ('mail.user', ''),
  ('mail.pass', '')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO help_pages (slug, title, body) VALUES
  ('como-comprar', 'Cómo comprar',
   'Comprar es muy fácil:\n\n1. Explorá el catálogo y agregá productos al carrito.\n2. Finalizá la compra con tus datos y elegí el método de pago.\n3. Si pagás por transferencia, subí el comprobante desde tu pedido.\n4. Te avisamos por correo y en tus notificaciones cuando tu pedido avance.'),
  ('envios', 'Envíos y entregas',
   'Hacemos envíos a toda Guatemala.\n\nEl costo se muestra antes de confirmar la compra. También podés recibir envío gratis al alcanzar el mínimo publicado o recoger tu pedido cuando la tienda tenga esa opción activa. Cuando el pedido sale de nuestra bodega, cambia a Enviado y recibís un aviso.'),
  ('garantias', 'Garantías y reclamos',
   'Todos los productos tienen garantía contra defectos de fábrica.\n\nSi tu producto llegó dañado o no funciona, escribinos dentro de los 8 días de recibido con tu número de pedido y fotos del problema. Evaluamos cada caso y te ofrecemos reposición o devolución.'),
  ('ubicaciones', 'Ubicaciones y horarios',
   'Atendemos en línea todos los días.\n\nNuestro horario de atención y despacho es de lunes a sábado, de 8:00 a 18:00. Los pedidos confirmados después de las 15:00 se procesan al siguiente día hábil.'),
  ('faq', 'Preguntas frecuentes',
   '¿Cómo pago mi pedido?\nAceptamos transferencia bancaria (subiendo el comprobante) y pago contra entrega donde esté disponible.\n\n¿Cómo sé el estado de mi pedido?\nEntrá a Mi cuenta > Mis pedidos: ahí ves el estado actualizado y las notas de la tienda.\n\n¿Puedo cambiar mi pedido?\nEscribinos lo antes posible con tu número de pedido; si aún no se despachó, lo ajustamos.')
ON DUPLICATE KEY UPDATE `title` = `title`;
