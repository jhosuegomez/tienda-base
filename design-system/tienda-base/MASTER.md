# Sistema visual maestro: Tienda Base

Este documento reemplaza la propuesta automática de glassmorphism azul/naranja. Esa salida era válida técnicamente, pero demasiado genérica para el encargo y contradecía la dirección Bagisto elegida por el usuario.

## Dirección

**Comercio editorial contemporáneo.** La interfaz debe sentirse como una tienda real, clara y confiable. El producto y la fotografía llevan el peso visual. La administración conserva la misma familia visual, con mayor densidad y menos decoración.

La firma del sistema es un slideshow dividido: contenido sereno a la izquierda e imagen dominante a la derecha. El resto de la interfaz permanece silencioso para no competir con él.

## Tokens

- Color primario: configurable por el administrador; se reserva para estados activos y acciones comerciales.
- Acento: configurable; se utiliza como detalle puntual, nunca como segundo color dominante.
- Fondo: neutral cálido derivado de `theme.bg`.
- Superficie: blanco o el valor derivado de `theme.bg`.
- Texto: derivado de `theme.text`; cuerpo con contraste mínimo AA.
- Tipografía de títulos: Outfit, pesos 500–800.
- Tipografía de interfaz: Work Sans con la fuente configurada como respaldo.
- Espaciado: escala de 4, 8, 12, 16, 24, 32, 48 y 64 px.
- Radios: 6–9 px en controles, 14–20 px en contenido y radio mayor únicamente en el slideshow.
- Sombras: solo para elementos flotantes. La agrupación normal usa espacio, fondo o borde fino.

## Reglas de composición

- Alineación izquierda por defecto; centrar únicamente estados vacíos y confirmaciones breves.
- Contenedor máximo entre 1200 y 1280 px.
- Las imágenes constituyen la superficie de las tarjetas de producto; la información queda libre debajo.
- No encerrar cada bloque en una tarjeta. Los bordes deben comunicar agrupación funcional.
- En móvil, mantener 16 px de texto base, objetivos táctiles de 44 px y cero desplazamiento horizontal.
- Un solo CTA principal por pantalla.

## Componentes

- Header: dos niveles, fondo casi opaco, logo sin contorno ni cápsula, búsqueda dominante.
- Producto: imagen con radio medio; nombre, precio y estado debajo sin sombra exterior.
- Formularios: altura mínima 44 px, etiquetas visibles y foco de cuatro píxeles con el color primario atenuado.
- Cuenta y administración: navegación compacta con abreviaturas consistentes, estado activo oscuro.
- Footer: carbón neutro, rótulos en sentence case y jerarquía secundaria.

## Evitar

- Glassmorphism decorativo, gradientes morado/azul y sombras repetidas.
- Emojis como iconos estructurales.
- Cápsulas para todos los estados y radios idénticos en todos los niveles.
- Eyebrows en mayúsculas con tracking exagerado.
- Animaciones de entrada en cada sección o cambios de layout al pasar el cursor.

## Validación

- Revisar 375, 768, 1024 y 1440 px.
- Verificar teclado, foco visible, contraste, `prefers-reduced-motion` y textos largos.
- Ejecutar lint PHP y las pruebas MUST, ayuda y slideshow tras cada cambio visual.
