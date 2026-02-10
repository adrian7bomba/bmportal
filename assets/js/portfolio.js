document.addEventListener('DOMContentLoaded', function () {
    const dealSelect = document.querySelector('[name="bm_portfolio_deal_id"]');
    const infoBox = document.querySelector('.bm-portfolio-deal-info');

    if (!dealSelect || !infoBox || typeof bmPortfolio === 'undefined') return;

    dealSelect.addEventListener('change', function () {
        const dealId = this.value;

        infoBox.innerHTML = '';
        if (!dealId) return;

        const formData = new FormData();
        formData.append('action', 'bm_pf_check_deal_review');
        formData.append('deal_id', dealId);
        formData.append('nonce', bmPortfolio.nonce);

        fetch(bmPortfolio.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(r => r.json())
            .then(res => {
                if (!res || !res.success) return;

                infoBox.innerHTML = res.data.message;
            });
    });
});



// Lightbox dla galerii portfolio (bez zewnętrznych bibliotek) + nawigacja (prev/next)
document.addEventListener('DOMContentLoaded', function () {
  const overlayId = 'bm-portfolio-lightbox';
  let overlay = document.getElementById(overlayId);

  let currentLinks = [];
  let currentIndex = 0;

  function ensureOverlay() {
    if (overlay) return overlay;

    overlay = document.createElement('div');
    overlay.id = overlayId;
    overlay.innerHTML = ''
      + '<div class="bm-portfolio-lightbox-inner" role="dialog" aria-modal="true">'
      +   '<button type="button" class="bm-lb-close" aria-label="Zamknij">×</button>'
      +   '<button type="button" class="bm-lb-prev" aria-label="Poprzednie">‹</button>'
      +   '<img alt="">'
      +   '<button type="button" class="bm-lb-next" aria-label="Następne">›</button>'
      + '</div>';

    document.body.appendChild(overlay);

    // klik poza obrazem zamyka
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });

    // klawisze
    document.addEventListener('keydown', function (e) {
      if (!overlay.classList.contains('is-open')) return;
      if (e.key === 'Escape') { close(); return; }
      if (e.key === 'ArrowLeft') { prev(); return; }
      if (e.key === 'ArrowRight') { next(); return; }
    });

    overlay.addEventListener('click', function (e) {
      const closeBtn = e.target.closest('.bm-lb-close');
      const prevBtn  = e.target.closest('.bm-lb-prev');
      const nextBtn  = e.target.closest('.bm-lb-next');
      if (closeBtn) { e.preventDefault(); close(); }
      if (prevBtn)  { e.preventDefault(); prev(); }
      if (nextBtn)  { e.preventDefault(); next(); }
    });

    return overlay;
  }

  function openAt(index) {
    const ov = ensureOverlay();
    currentIndex = Math.max(0, Math.min(index, currentLinks.length - 1));

    const img = ov.querySelector('img');
    if (!img) return;

    const href = currentLinks[currentIndex] ? currentLinks[currentIndex].getAttribute('href') : '';
    if (!href) return;

    img.src = href;
    ov.classList.add('is-open');
    document.documentElement.classList.add('bm-lightbox-open');

    // ukryj przyciski jeśli 1 zdjęcie
    const prevBtn = ov.querySelector('.bm-lb-prev');
    const nextBtn = ov.querySelector('.bm-lb-next');
    const showNav = currentLinks.length > 1;
    if (prevBtn) prevBtn.style.display = showNav ? '' : 'none';
    if (nextBtn) nextBtn.style.display = showNav ? '' : 'none';
  }

  function close() {
    if (!overlay) return;
    overlay.classList.remove('is-open');
    document.documentElement.classList.remove('bm-lightbox-open');
    const img = overlay.querySelector('img');
    if (img) img.src = '';
    currentLinks = [];
    currentIndex = 0;
  }

  function prev() {
    if (!currentLinks.length) return;
    openAt((currentIndex - 1 + currentLinks.length) % currentLinks.length);
  }

  function next() {
    if (!currentLinks.length) return;
    openAt((currentIndex + 1) % currentLinks.length);
  }

  document.addEventListener('click', function (e) {
    const a = e.target.closest && e.target.closest('.bm-portfolio-gallery-link');
    if (!a) return;

    const wrap = a.closest('.bm-portfolio-gallery');
    if (!wrap) return;

    const lightbox = wrap.getAttribute('data-lightbox');
    if (lightbox !== '1') return;

    e.preventDefault();

    currentLinks = Array.prototype.slice.call(wrap.querySelectorAll('.bm-portfolio-gallery-link'));
    currentIndex = Math.max(0, currentLinks.indexOf(a));

    openAt(currentIndex);
  });
});


// === Portfolio: Rozwiń/Zwiń opis realizacji ===
document.addEventListener('click', function(e){
  var btn = e.target.closest && e.target.closest('.bm-portfolio-desc-toggle');
  if(btn && btn.tagName === 'A') { e.preventDefault(); }
  if(!btn) return;
  var wrap = btn.closest('.bm-portfolio-desc');
  if(!wrap) return;
  var shortEl = wrap.querySelector('.bm-portfolio-desc-short');
  var fullEl  = wrap.querySelector('.bm-portfolio-desc-full');
  var expanded = btn.getAttribute('aria-expanded') === 'true';
  if(expanded){
    if(fullEl) fullEl.style.display = 'none';
    if(shortEl) shortEl.style.display = '';
    btn.textContent = 'Rozwiń';
    btn.setAttribute('aria-expanded','false');
  } else {
    if(fullEl) fullEl.style.display = '';
    if(shortEl) shortEl.style.display = 'none';
    btn.textContent = 'Zwiń';
    btn.setAttribute('aria-expanded','true');
  }
});
