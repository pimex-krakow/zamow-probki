/* PIMEX Samples 5. No background requests, timers for polling, or product scans. */
(() => {
  'use strict';
  function mount(host) {
    if (host.shadowRoot) return;
    const template = host.querySelector('template');
    if (!template) return;
    const root = host.attachShadow({mode: 'open'});
    root.append(template.content.cloneNode(true)); template.remove();
    const $ = s => root.querySelector(s), $$ = s => [...root.querySelectorAll(s)];
    let config;
    try { config = JSON.parse($('.config').textContent); } catch { return; }
    const items = Array.isArray(config.items) ? config.items.slice(0, 1500) : [];
    const selected = new Map(), filters = new Map(), priceFilters = new Map();
    const max = Math.min(50, Math.max(1, Number(config.max) || 12));
    const price = Math.round(Number(config.price) * 100), shipping = Math.round(Number(config.shipping) * 100);
    const money = cents => new Intl.NumberFormat('pl-PL', {style: 'currency', currency: 'PLN'}).format(cents / 100);
    const normalize = s => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ł/g, 'l').toLowerCase();
    let group = '', query = '', page = 1, columns = 2, busy = false;
    function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
    function safeUrl(raw) { try { const u = new URL(raw, location.href); return ['http:', 'https:'].includes(u.protocol) ? u.href : ''; } catch { return ''; } }
    function values(item, field) {
      const map = {brand: 'brand', shade: 'shades', material: 'materials', construction: 'construction', coating: 'coating', surface: 'surfaces', width: 'widths', length: 'lengths', thickness: 'thicknesses'};
      const value = map[field] ? item[map[field]] : item.attrs?.[field];
      return (Array.isArray(value) ? value : [value]).filter(v => v !== undefined && v !== '').map(String);
    }
    const labels = {solid: 'Pełna', hollow: 'Komorowa', coated: 'Z powłoką', uncoated: 'Bez powłoki', grey: 'Szarości i antracyty', brown: 'Brązy i drewno', beige: 'Beże i kremy', red: 'Rudości i czerwienie', black: 'Czernie'};
    for (const [field, title] of Object.entries(config.fields || {})) {
      const details = el('details', 'filter'); details.append(el('summary', '', title));
      if (field.startsWith('price_')) {
        const unit = field === 'price_mb' ? 'mb' : 'm2';
        const prices = items.flatMap(i => (i.unit_options || []).map(p => p[unit])).filter(p => p !== null && Number.isFinite(p));
        if (!prices.length) continue;
        const low = Math.floor(prices.reduce((a,b) => Math.min(a,b), Infinity)), high = Math.ceil(prices.reduce((a,b) => Math.max(a,b), -Infinity));
        const boxes = el('div', 'price-fields');
        const state = {low, high, min: low, max: high, active: false, inputs: []};
        for (const which of ['min', 'max']) {
          const label = el('label', '', which === 'min' ? 'Od (zł)' : 'Do (zł)');
          const input = el('input'); input.type = 'number'; input.step = '.01'; input.min = String(low); input.max = String(high); input.value = String(state[which]);
          label.append(input); boxes.append(label);
          const slider = el('input', 'range'); slider.type = 'range'; slider.min = String(low); slider.max = String(high); slider.step = '.01'; slider.value = input.value; slider.setAttribute('aria-label', `${title}: ${which === 'min' ? 'od' : 'do'}`);
          state.inputs.push({which, input, slider});
          function update(from, to) {
            if (from.value === '' || !Number.isFinite(Number(from.value))) return;
            state[which] = Math.min(high, Math.max(low, Number(from.value)));
            to.value = String(state[which]);
            state.active = state.min !== low || state.max !== high; page = 1; render();
          }
          input.addEventListener('change', () => update(input, slider)); slider.addEventListener('input', () => update(slider, input));
          details.append(slider);
        }
        details.append(boxes, el('p', 'price-note', 'Cena pełnej deski brutto według przygotowanego katalogu. Brak wymiarów oznacza brak przeliczenia.'));
        priceFilters.set(unit, state);
      } else {
        const options = el('div', 'options');
        const optionsValues = [...new Set(items.flatMap(i => values(i, field)))].sort((a,b) => a.localeCompare(b, 'pl', {numeric: true}));
        if (!optionsValues.length) continue;
        for (const value of optionsValues) {
          const label = el('label'), input = el('input'); input.type = 'checkbox'; input.value = value; input.dataset.field = field;
          label.append(input, el('span', '', labels[value] || value)); options.append(label);
          input.addEventListener('change', () => {
            const set = filters.get(field) || new Set(); if (input.checked) set.add(value); else set.delete(value);
            filters.set(field, set); page = 1; render();
          });
        }
        details.append(options);
      }
      $('.filter-list').append(details);
    }
    function matches(item) {
      if (group && item.group !== group) return false;
      if (query && !normalize([item.title, item.label, item.brand, item.color, item.sku].join(' ')).includes(query)) return false;
      for (const [field, set] of filters) {
        if (set.size && !(field === 'length' && item.custom_length) && !values(item, field).some(v => set.has(v))) return false;
      }
      const active = [...priceFilters].filter(([, f]) => f.active);
      // Both price bounds must be satisfied by the SAME length, not two unrelated variants.
      if (active.length && !(item.unit_options || []).some(p => active.every(([u, f]) => p[u] !== null && Number.isFinite(p[u]) && p[u] >= f.min && p[u] <= f.max))) return false;
      return true;
    }
    function choose(item) {
      if (selected.has(item.key)) { selected.delete(item.key); $('.feedback').textContent = ''; }
      else if (!config.enabled || item.available === false) { return; }
      else if (selected.size >= max) { $('.feedback').textContent = `Możesz wybrać maksymalnie ${max} różnych próbek.`; render(); return; }
      else { selected.set(item.key, item); $('.feedback').textContent = ''; }
      summary(); render();
    }
    function image(item) {
      const img = el('img'); img.src = safeUrl(item.image_url); img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.width = 200; img.height = 160; return img;
    }
    function card(item) {
      const chosen = selected.has(item.key), available = item.available !== false;
      const article = el('article', `card${chosen ? ' chosen' : ''}${available ? '' : ' unavailable'}`);
      const label = el('label', 'choose'), input = el('input'); input.type = 'checkbox'; input.checked = chosen; input.dataset.sampleKey = item.key; input.disabled = !config.enabled || !available;
      input.setAttribute('aria-label', `Wybierz próbkę: ${item.label}`); input.addEventListener('change', () => choose(item));
      label.append(input, el('span', '', chosen ? 'Wybrana próbka' : 'Wybierz próbkę'));
      article.append(label, image(item));
      const body = el('div', 'card-body');
      body.append(el('span', 'brand', item.brand), el('div', 'title', item.title), el('span', 'color', [item.color, item.profile].filter(Boolean).join(' / ')), el('span', 'price', `${money(price)} / próbka`));
      if (!available) body.append(el('span', 'unavailable-text', 'Chwilowo niedostępna'));
      const a = el('a', 'product-link', 'Zobacz produkt'); a.href = safeUrl(item.url); body.append(a); article.append(body); return article;
    }
    function summary() {
      $('.count').textContent = String(selected.size); $('.selected').replaceChildren();
      for (const item of selected.values()) {
        const li = el('li'); const remove = el('button', '', '×'); remove.type = 'button'; remove.setAttribute('aria-label', `Usuń próbkę: ${item.label}`); remove.addEventListener('click', () => choose(item));
        li.append(image(item), el('span', '', item.label), remove); $('.selected').append(li);
      }
      $('.selected-empty').hidden = selected.size > 0;
      $('.subtotal').textContent = money(selected.size * price); $('.shipping').textContent = selected.size ? money(shipping) : '—';
      $('.grand').textContent = money(selected.size ? selected.size * price + shipping : 0);
      $('.ids').value = JSON.stringify([...selected.values()].map(i => Number(i.id)));
      $('.submit').disabled = busy || !config.enabled || !selected.size;
      try { localStorage.setItem(config.storage, $('.ids').value); } catch { /* Selection works without storage. */ }
    }
    function render() {
      const focusedKey = root.activeElement?.dataset.sampleKey;
      const filtered = items.filter(matches), size = columns * 4, pages = Math.max(1, Math.ceil(filtered.length / size));
      page = Math.min(page, pages); $('.cards').style.gridTemplateColumns = `repeat(${columns}, minmax(0, 1fr))`;
      $('.cards').replaceChildren(...filtered.slice((page-1)*size, page*size).map(card)); $('.empty').hidden = filtered.length > 0;
      $('.results').textContent = `${filtered.length} wzorów · wybrano ${selected.size}/${max}`;
      $('.pages').replaceChildren();
      if (pages > 1) {
        for (const [text, next] of [['Poprzednia', page-1], ['Następna', page+1]]) {
          const b = el('button', '', text); b.type = 'button'; b.disabled = next < 1 || next > pages;
          b.addEventListener('click', () => { page = next; render(); $('.catalog').scrollIntoView({block: 'start'}); $('.pages button:not(:disabled)')?.focus({preventScroll:true}); });
          if (next > page) $('.pages').append(el('span', '', `${page} / ${pages}`)); $('.pages').append(b);
        }
      }
      if (focusedKey) $$('.choose input').find(i => i.dataset.sampleKey === focusedKey)?.focus({preventScroll:true});
      $$('[data-group]').forEach(b => b.setAttribute('aria-pressed', String(group === b.dataset.group)));
    }
    function reset() {
      filters.clear(); group = ''; query = ''; page = 1; $('.search').value = '';
      $$('input[data-field]').forEach(i => { i.checked = false; });
      for (const f of priceFilters.values()) { f.min = f.low; f.max = f.high; f.active = false; for (const {which,input,slider} of f.inputs) { input.value = slider.value = String(f[which]); } }
      render();
    }
    $$('[data-group]').forEach(b => b.addEventListener('click', () => { group = group === b.dataset.group ? '' : b.dataset.group; page = 1; render(); }));
    $$('.reset').forEach(b => b.addEventListener('click', reset));
    $('.search').addEventListener('input', e => { query = normalize(e.target.value.trim()); page = 1; render(); });
    $('.clear').addEventListener('click', () => { selected.clear(); $('.feedback').textContent = ''; summary(); render(); });
    $('.order').addEventListener('submit', e => { if (busy || !config.enabled || !selected.size) { e.preventDefault(); return; } busy = true; $('.submit').disabled = true; $('.submit').textContent = 'Dodawanie zestawu…'; });
    let saved = [];
    try { const raw = localStorage.getItem(config.storage); saved = JSON.parse(raw || '[]'); } catch { /* Private browser mode. */ }
    const aliasIndex = new Map(); const parentGroups = new Map();
    items.forEach(i => { for (const id of (i.aliases || [i.id])) aliasIndex.set(Number(id), i); const parent=Number(i.parent || 0); if(parent) { const list=parentGroups.get(parent)||[]; list.push(i); parentGroups.set(parent,list); } });
    parentGroups.forEach((list, parent) => { if(list.length===1) aliasIndex.set(parent,list[0]); });
    for (const id of [...(Array.isArray(saved) ? saved.slice(0,50) : []), ...(config.picks || [])]) {
      const item = aliasIndex.get(Number(id)); if (item && item.available !== false && selected.size < max) selected.set(item.key, item);
    }
    const resize = () => { const w = $('.cards').clientWidth; const next = w >= 820 ? 5 : w >= 650 ? 4 : w >= 480 ? 3 : 2; if (next !== columns) { columns=next; page=1; render(); } };
    if ('ResizeObserver' in window) new ResizeObserver(resize).observe($('.cards')); else window.addEventListener('resize', resize);
    window.addEventListener('pageshow', () => { busy=false; $('.submit').textContent='Dodaj zestaw do koszyka'; summary(); reset(); });
    summary(); render(); resize();
    if (matchMedia('(max-width:700px)').matches) $('.filters').open = false;
  }
  function boot() { document.querySelectorAll('pimex-samples-v5').forEach(mount); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true}); else boot();
})();
