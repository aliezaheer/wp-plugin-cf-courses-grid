(function(){
  const restUrl = CFGridData.rest_url;
  const nonce = CFGridData.nonce;

  function fetchCourses(params = {}) {
    const url = new URL(restUrl, window.location.origin);
    Object.keys(params).forEach(k => { if (params[k]) url.searchParams.set(k, params[k]); });
    return fetch(url.toString(), { credentials: 'same-origin' })
      .then(r => r.json());
  }

  function renderCard(item){
    return `
      <article class="cf-course-card">
        <a href="${item.permalink}">
          <div class="cf-thumb"><img src="${item.thumbnail || ''}" alt="${escapeHTML(item.title)}"></div>
          <h3>${escapeHTML(item.title)}</h3>
          <p class="cf-excerpt">${escapeHTML(item.excerpt)}</p>
        </a>
      </article>
    `;
  }

  function escapeHTML(s){ return s ? s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''; }

  function updateGrid(response){
    const container = document.getElementById('cf-grid-container');
    const pagination = document.querySelector('.cf-grid-pagination');
    const count = document.querySelector('.cf-grid-count');
    if (!container) return;
    container.innerHTML = response.items.map(renderCard).join('');
    count.textContent = `Showing ${response.items.length} of ${response.total}`;
    // pagination simple:
    const pages = response.pages;
    const page = response.page;
    let html = '';
    for(let i=1;i<=pages;i++){
      html += `<button class="cf-page-btn" data-page="${i}" ${i===page?'aria-current="true"':''}>${i}</button>`;
    }
    pagination.innerHTML = html;
  }

  function readFilters(){
    const f = {};
    document.querySelectorAll('.cf-facet [data-facet], .cf-facet select, .cf-facet input').forEach(el=>{
      if (el.dataset && el.dataset.facet) {
        f[el.dataset.facet] = el.value || '';
      }
    });
    return f;
  }

  function load(page=1){
    const container = document.getElementById('cf-grid-container');
    if (!container) return;
    const perPage = container.getAttribute('data-per-page') || 12;
    const filters = readFilters();
    const params = Object.assign({}, filters, { page: page, per_page: perPage });
    fetchCourses(params).then(updateGrid);
  }

  document.addEventListener('DOMContentLoaded', function(){
    // init load
    load();

    // facet change
    document.querySelectorAll('.cf-grid-facets select').forEach(s=>{
      s.addEventListener('change', function(){ load(1); });
    });
    // reset
    const resetBtn = document.querySelector('.cf-reset-filters');
    if (resetBtn) resetBtn.addEventListener('click', function(){
      document.querySelectorAll('.cf-grid-facets select').forEach(s=> s.value = '');
      load(1);
    });
    // pagination click
    document.addEventListener('click', function(e){
      if (e.target.matches('.cf-page-btn')) {
        const page = parseInt(e.target.getAttribute('data-page'),10) || 1;
        load(page);
      }
    });
  });

})();
