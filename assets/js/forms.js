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

    const handler = debounce(() => {
      if (!turboFrame) return;

      const frame = document.getElementById(turboFrame);
      if (!frame) return;

      // Build on the frame's current src so the query parameters it already
      // carries (filters, page, sort) survive the search request.
      const base = frame.getAttribute('src') || el.getAttribute('data-search-url');
      if (!base) return;

      const url = new URL(base, window.location.origin);
      url.searchParams.set('q', el.value);

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
