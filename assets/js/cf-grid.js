(function () {
  const REST_URL = CFGridData.rest_url;
  const NONCE = CFGridData.nonce;

  function escapeHTML(s) {
    return s ? String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';
  }

  function debounce(fn, wait = 300) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  function fetchCourses(params = {}) {
    const url = new URL(REST_URL, window.location.origin);
    Object.keys(params).forEach((k) => {
      if (params[k] !== '' && params[k] !== null && params[k] !== undefined) url.searchParams.set(k, params[k]);
    });

    return fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': NONCE,
        'Accept': 'application/json'
      }
    }).then((r) => {
      if (!r.ok) throw new Error('Network response was not ok');
      return r.json();
    });
  }

  function renderCard(item) {
    const cats = (item.categories || []).map(c => `<span class="cf-badge">${escapeHTML(c)}</span>`).join(' ');
    const tags = (item.tags || []).map(t => `<span class="cf-tag">${escapeHTML(t)}</span>`).join(' ');

    return `
      <article class="cf-course-card" aria-labelledby="course-${item.id}-title">
        <a class="cf-card-link" href="${item.permalink}">
          <div class="cf-thumb">
            <img src="${item.thumbnail || ''}" alt="${escapeHTML(item.title)}" loading="lazy">
          </div>
          <div class="cf-card-body">
            <h3 id="course-${item.id}-title" class="cf-card-title">${escapeHTML(item.title)}</h3>
            <div class="cf-meta">${cats}</div>
            <div class="cf-card-footer">
              <span class="cf-cta">View Course →</span>
            </div>
          </div>
        </a>
      </article>
    `;
  }

//  <div class="cf-tags">${tags}</div> 


  function renderSkeleton(count = 6) {
    let s = '';
    for (let i = 0; i < count; i++) {
      s += `
        <article class="cf-course-card cf-skeleton" aria-hidden="true">
          <div class="cf-thumb"><div class="cf-skel-rect"></div></div>
          <div class="cf-card-body">
            <div class="cf-skel-line short"></div>
            <div class="cf-skel-line"></div>
            <div class="cf-skel-line"></div>
          </div>
        </article>
      `;
    }
    return s;
  }

  function updateGrid(response, pageRequested = 1) {
    const container = document.getElementById('cf-grid-container');
    const pagination = document.querySelector('.cf-grid-pagination');
    const countEl = document.querySelector('.cf-grid-count');

    if (!container) return;

    const items = (response && response.items) || [];
    container.innerHTML = items.length ? items.map(renderCard).join('') : `<div class="cf-no-results">No courses found.</div>`;

    const total = response && response.total ? parseInt(response.total, 10) : 0;
    const perPage = response && response.per_page ? parseInt(response.per_page, 10) : parseInt(container.getAttribute('data-per-page') || 12, 10);
    const page = response && response.page ? parseInt(response.page, 10) : pageRequested;
    const pages = response && response.pages ? parseInt(response.pages, 10) : 1;

    const start = total === 0 ? 0 : (perPage * (page - 1) + 1);
    const end = Math.min(total, perPage * page);
    countEl.textContent = `Showing ${start}–${end} of ${total}`;

    let html = '';
    if (pages > 1) {
      html += `<button class="cf-page-btn cf-prev" data-page="${Math.max(1, page - 1)}" ${page === 1 ? 'disabled' : ''} aria-label="Previous page">‹ Prev</button>`;
      const windowSize = 7;
      const half = Math.floor(windowSize / 2);
      let startPage = Math.max(1, page - half);
      let endPage = Math.min(pages, page + half);
      if (endPage - startPage < windowSize - 1) {
        startPage = Math.max(1, endPage - windowSize + 1);
        endPage = Math.min(pages, startPage + windowSize - 1);
      }
      for (let i = startPage; i <= endPage; i++) {
        html += `<button class="cf-page-btn" data-page="${i}" ${i === page ? 'aria-current="true" class="active"' : ''}>${i}</button>`;
      }
      html += `<button class="cf-page-btn cf-next" data-page="${Math.min(pages, page + 1)}" ${page === pages ? 'disabled' : ''} aria-label="Next page">Next ›</button>`;
    }
    pagination.innerHTML = html;
  }

  function readFilters() {
    const f = {};
    const facetEls = document.querySelectorAll('.cf-facet [data-facet], .cf-facet select, .cf-facet input');
    facetEls.forEach(el => {
      const key = el.dataset ? el.dataset.facet : null;
      if (key) {
        if (el.type === 'checkbox') {
          if (!f[key]) f[key] = [];
          if (el.checked) f[key].push(el.value);
        } else {
          f[key] = el.value || '';
        }
      }
    });
    Object.keys(f).forEach(k => {
      if (Array.isArray(f[k])) f[k] = f[k].join(',');
    });
    return f;
  }

  let lastLoad = 0;
  function load(page = 1) {
    const container = document.getElementById('cf-grid-container');
    if (!container) return;
    const perPage = container.getAttribute('data-per-page') || 12;
    const filters = readFilters();
    const params = Object.assign({}, filters, { page: page, per_page: perPage });

    container.innerHTML = renderSkeleton(perPage >= 6 ? 6 : perPage);

    fetchCourses(params)
      .then(resp => {
        updateGrid(resp, page);
        lastLoad = Date.now();
      })
      .catch(err => {
        container.innerHTML = `<div class="cf-error">Unable to load courses. ${escapeHTML(err.message)}</div>`;
        const countEl = document.querySelector('.cf-grid-count');
        if (countEl) countEl.textContent = '';
        document.querySelector('.cf-grid-pagination').innerHTML = '';
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    load();

    const onFilterChange = debounce(() => load(1), 300);
    document.querySelectorAll('.cf-grid-facets select, .cf-grid-facets input').forEach(el => {
      el.addEventListener('change', onFilterChange);
      el.addEventListener('input', onFilterChange);
    });

    const resetBtn = document.querySelector('.cf-reset-filters');
    if (resetBtn) {
      resetBtn.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.cf-grid-facets select').forEach(s => s.value = '');
        document.querySelectorAll('.cf-grid-facets input[type="text"]').forEach(i => i.value = '');
        load(1);
      });
    }

    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.cf-page-btn');
      if (btn) {
        const page = parseInt(btn.getAttribute('data-page'), 10) || 1;
        load(page);
      }
    });

    const mobileToggle = document.querySelector('.cf-facets-toggle');
    if (mobileToggle) {
      mobileToggle.addEventListener('click', function () {
        document.querySelector('.cf-grid-facets').classList.toggle('open');
      });
    }
  });
})();
