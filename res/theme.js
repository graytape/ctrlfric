(() => {
  const storageKey = 'ctrlfric-theme';
  let theme = 'light';
  try { theme = localStorage.getItem(storageKey) || 'light'; } catch (_) {}
  const apply = () => {
    const dark = theme === 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const button = document.getElementById('theme-toggle');
    if (button) {
      button.innerHTML = '<i class="fa-regular ' + (dark ? 'fa-sun' : 'fa-moon') + '" aria-hidden="true"></i>'; 
      button.setAttribute('aria-pressed', String(dark));
    }
    if (window.Chart) {
      Chart.defaults.color = dark ? '#d9e2ef' : '#444';
      Chart.defaults.borderColor = dark ? '#405065' : '#ddd';
      Object.values(Chart.instances).forEach(chart => {
        Object.values(chart.options.scales || {}).forEach(scale => {
          scale.ticks.color = Chart.defaults.color;
          scale.grid.color = Chart.defaults.borderColor;
        });
        if (chart.options.plugins.legend) chart.options.plugins.legend.labels.color = Chart.defaults.color;
        chart.update('none');
      });
    }
  };
  apply();
  document.addEventListener('DOMContentLoaded', () => {
    apply();
    document.getElementById('theme-toggle')?.addEventListener('click', () => {
      theme = theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem(storageKey, theme); } catch (_) {}
      apply();
    });
  });
  window.addEventListener('load', apply);
})();
