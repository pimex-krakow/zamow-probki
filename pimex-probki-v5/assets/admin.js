(() => {
  'use strict';
  const form = document.querySelector('.pimex-v5-builder');
  if (form) {
    let running = false, stop = false;
    const status = form.querySelector('[role="status"]');
    async function run(operation) {
      if (running) return;
      running = true; stop = false;
      form.querySelectorAll('[data-operation]:not([data-operation="stop"])').forEach(b => { b.disabled = true; });
      try {
        do {
          const data = new FormData(form); data.set('operation', operation);
          status.textContent = 'Przygotowanie partii…';
          const response = await fetch(form.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin'});
          const result = await response.json();
          if (!response.ok || !result.success) throw Error(result.data?.message || 'Nie udało się przygotować katalogu.');
          const info = result.data;
          if (info.cancelled) { status.textContent = 'Anulowano budowę. Opublikowany katalog pozostał bez zmian.'; break; }
          status.textContent = `Sprawdzono ${info.scanned} produktów i wariantów. Wzorów: ${info.samples}. ${info.done ? 'Katalog gotowy. Odśwież panel, żeby zobaczyć wynik.' : ''}`;
          if (info.done || stop) break;
          // One deliberate admin run, one request at a time. No retry on errors and no frontend polling.
          operation = 'resume';
          await new Promise(resolve => setTimeout(resolve, 600));
        } while (!stop);
        if (stop) status.textContent += ' Zatrzymano. Możesz wznowić.';
      } catch (error) { status.textContent = `Zatrzymano: ${error.message} Dotychczasowy katalog pozostał bez zmian.`; }
      finally { running = false; form.querySelectorAll('[data-operation]').forEach(b => { b.disabled = false; }); }
    }
    form.querySelectorAll('[data-operation]').forEach(button => button.addEventListener('click', () => {
      if (button.dataset.operation === 'stop') { stop = true; status.textContent += ' Zatrzymuję po bieżącej partii…'; return; }
      run(button.dataset.operation);
    }));
    form.addEventListener('submit', e => e.preventDefault());
  }
  const filters = document.querySelector('.pimex-v5-filter-form');
  if (filters) {
    filters.querySelectorAll('[data-move]').forEach(button => button.addEventListener('click', () => {
      const li = button.closest('li');
      if (button.dataset.move === 'up' && li.previousElementSibling) li.before(li.previousElementSibling);
      else if (button.dataset.move === 'down' && li.nextElementSibling) li.nextElementSibling.after(li);
      button.focus();
    }));
    filters.addEventListener('submit', () => { filters.querySelector('[name="view_filter_order"]').value = JSON.stringify([...filters.querySelectorAll('[data-field]')].map(li => li.dataset.field)); });
  }
})();
