(() => {
  const form = document.getElementById('appearance-form');
  const preview = document.querySelector('.appearance-preview');
  if (!form || !preview) return;
  const fields = ['primary', 'accent', 'bg', 'text'];
  const read = () => Object.fromEntries(fields.map(key => [key, form.elements['theme_' + key].value]));
  const original = JSON.stringify([...fields.map(key => form.elements['theme_' + key].value), form.elements.theme_font.value]);
  const label = document.getElementById('appearance-preview-label');
  const luminance = hex => {
    const c = hex.slice(1).match(/../g).map(x => parseInt(x, 16) / 255).map(x => x <= .04045 ? x / 12.92 : ((x + .055) / 1.055) ** 2.4);
    return c[0] * .2126 + c[1] * .7152 + c[2] * .0722;
  };
  const show = (colors, name) => {
    fields.forEach(key => preview.style.setProperty('--preview-' + key, colors[key]));
    const l = luminance(colors.primary);
    preview.style.setProperty('--preview-on-primary', 1.05 / (l + .05) >= (l + .05) / .05 ? '#fff' : '#000');
    preview.style.setProperty('--preview-surface', luminance(colors.bg) < .22 ? 'color-mix(in srgb, var(--preview-bg) 90%, white)' : 'color-mix(in srgb, var(--preview-bg) 12%, white)');
    label.textContent = 'Vista previa · ' + name;
  };
  form.addEventListener('input', () => show(read(), 'Personalizada sin guardar'));
  form.addEventListener('reset', () => queueMicrotask(() => show(read(), 'Colores guardados')));
  document.querySelectorAll('[data-palette]').forEach(button => {
    const state = button.querySelector('.palette-state');
    if (button.getAttribute('aria-pressed') === 'true' && state) state.textContent = '✓ Aplicada';
    const inspect = () => show(JSON.parse(button.dataset.palette), button.dataset.paletteName);
    button.addEventListener('mouseenter', inspect);
    button.addEventListener('focus', inspect);
  });
  let submitting = false;
  form.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('beforeunload', event => {
    const current = JSON.stringify([...fields.map(key => form.elements['theme_' + key].value), form.elements.theme_font.value]);
    if (!submitting && current !== original) { event.preventDefault(); event.returnValue = ''; }
  });
  show(read(), 'Colores guardados');
})();
