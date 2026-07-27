function debounce(fn, ms) {
  let t;
  return (...args) => {
    window.clearTimeout(t);
    t = window.setTimeout(() => fn(...args), ms);
  };
}

function initRangeSliders(root = document) {
  root.querySelectorAll('input[type="range"][data-range-value-target]').forEach((el) => {
    const targetId = el.getAttribute('data-range-value-target');
    const target = document.getElementById(targetId);
    if (!target) return;

    const render = () => { target.textContent = el.value; };
    el.addEventListener('input', render);
    render();
  });
}

function initSearchInputs(root = document) {
  root.querySelectorAll('input[type="search"][data-search-debounce]').forEach((el) => {
    const ms = parseInt(el.getAttribute('data-search-debounce') || '250', 10);
    const turboFrame = el.getAttribute('data-search-turbo-frame');

    const param = el.getAttribute('data-search-param') || 'q';
    const resetParams = (el.getAttribute('data-search-reset-params') || '')
      .split(',')
      .map((p) => p.trim())
      .filter(Boolean);

    const handler = debounce(() => {
      if (!turboFrame) return;

      const frame = document.getElementById(turboFrame);
      if (!frame) return;

      // Build on the frame's current src so the query parameters it already
      // carries (filters, page, sort) survive the search request. Fall back to the
      // input's own URL for a frame that has not been navigated yet and has no src.
      const base = frame.getAttribute('src') || el.getAttribute('data-search-url') || window.location.href;

      const url = new URL(base, window.location.origin);
      if (el.value === '') {
        url.searchParams.delete(param);
      } else {
        url.searchParams.set(param, el.value);
      }
      // A new term must land on page 1: keeping the old page number would show an empty
      // table whenever the narrowed result set has fewer pages than the current one.
      resetParams.forEach((p) => url.searchParams.delete(p));

      // Assigning src makes Turbo fetch and swap only this frame.
      frame.src = url.toString();
    }, ms);

    el.addEventListener('input', handler);
  });
}

// turbo:load also fires on Turbo Drive navigations, so restored/replaced pages re-init.
document.addEventListener('turbo:load', () => {
  initRangeSliders();
  initSearchInputs();
});

export { debounce, initRangeSliders, initSearchInputs };
