'use strict';
(() => {
  const root = document.documentElement;
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  const key = 'sweepline-theme';
  const valid = value => ['adaptive', 'light', 'dark'].includes(value);
  let preference = 'adaptive';
  try {
    const saved = localStorage.getItem(key);
    if (valid(saved)) preference = saved;
  } catch (_) { /* Theme selection still works when storage is unavailable. */ }
  function apply() {
    const resolved = preference === 'adaptive' ? (system.matches ? 'dark' : 'light') : preference;
    root.dataset.theme = resolved;
    root.dataset.themePreference = preference;
    root.style.colorScheme = resolved;
    window.dispatchEvent(new CustomEvent('sweepline:themechange', {detail: {theme: resolved, preference}}));
  }
  apply();
  system.addEventListener('change', () => { if (preference === 'adaptive') apply(); });
  document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('theme');
    if (!select) return;
    select.value = preference;
    select.addEventListener('change', () => {
      preference = valid(select.value) ? select.value : 'adaptive';
      try { localStorage.setItem(key, preference); } catch (_) { /* Keep the session preference. */ }
      apply();
    });
  });
  window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    preference = valid(event.newValue) ? event.newValue : 'adaptive';
    apply();
    const select = document.getElementById('theme');
    if (select) select.value = preference;
  });
})();