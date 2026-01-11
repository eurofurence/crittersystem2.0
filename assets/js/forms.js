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

      // IMPORTANT: use the frame's current src (or data-search-url) as the base
      const base = frame.getAttribute('src') || el.getAttribute('data-search-url');
      if (!base) return;

      const url = new URL(base, window.location.origin);
      url.searchParams.set('q', el.value);

      // Trigger a Turbo visit restricted to a frame
      // Works with Turbo Frames (same-origin)
      // const frame = document.getElementById(turboFrame);
      // if (frame) frame.src = url.toString();
      frame.src = url.toString();
    }, ms);

    el.addEventListener('input', handler);
  });
}

// Turbo-safe init
document.addEventListener('turbo:load', () => {
  initRangeSliders();
  initSearchInputs();
});

export { debounce, initRangeSliders, initSearchInputs };
