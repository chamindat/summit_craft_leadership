/* Summitcraft Leadership — story rendering */

const API_BASE = '/api';

/* ── Helpers ── */

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}

function coverUrl(record) {
  if (!record.cover_image) return null;
  return `${API_BASE}/files/${record.collectionId}/${record.id}/${record.cover_image}`;
}

function slugParam() {
  return new URLSearchParams(window.location.search).get('id');
}

/* ── Stories listing page ── */

async function loadStories() {
  const container = document.getElementById('stories-grid');
  const emptyMsg = document.getElementById('stories-empty');
  const loading = document.getElementById('stories-loading');
  if (!container) return;

  const done = () => { if (loading) loading.classList.add('d-none'); };

  try {
    const res = await fetch(
      `${API_BASE}/collections/stories/records?filter=(is_published=true)&sort=-published_date&perPage=50`
    );
    if (res.status === 404) {
      if (emptyMsg) emptyMsg.classList.remove('d-none');
      container.innerHTML = '';
      done();
      return;
    }
    if (!res.ok) throw new Error(res.statusText);
    const data = await res.json();

    if (!data.items || data.items.length === 0) {
      if (emptyMsg) emptyMsg.classList.remove('d-none');
      container.innerHTML = '';
      done();
      return;
    }

    container.innerHTML = data.items.map(item => {
      const img = coverUrl(item);
      const imgHtml = img
        ? `<img src="${img}" class="card-img-top" alt="${escHtml(item.title)}">`
        : `<div class="card-img-top bg-dark d-flex align-items-center justify-content-center" style="height:200px">
             <i class="fas fa-mountain fa-3x text-secondary opacity-50"></i>
           </div>`;
      return `
        <div class="col-md-6 col-lg-4">
          <div class="card sc-card sc-story-card h-100 shadow-sm">
            <a href="story.html?id=${item.id}">${imgHtml}</a>
            <div class="card-body">
              <p class="small text-muted mb-1">${formatDate(item.published_date || item.created)}</p>
              <h5 class="card-title fw-700">
                <a href="story.html?id=${item.id}" class="text-dark text-decoration-none">${escHtml(item.title)}</a>
              </h5>
              <p class="card-text text-muted">${escHtml(item.excerpt || '')}</p>
            </div>
            <div class="card-footer">
              <a href="story.html?id=${item.id}" class="btn btn-sm btn-outline-primary">Read More</a>
            </div>
          </div>
        </div>`;
    }).join('');
    done();
  } catch (err) {
    container.innerHTML = `<div class="col-12"><p class="text-danger">Unable to load stories. Please try again later.</p></div>`;
    done();
    console.error('Stories fetch error:', err);
  }
}

/* ── Single story page ── */

async function loadStory() {
  const id = slugParam();
  if (!id) { window.location.href = 'stories.html'; return; }

  const titleEl = document.getElementById('story-title');
  const dateEl = document.getElementById('story-date');
  const coverEl = document.getElementById('story-cover');
  const bodyEl = document.getElementById('story-body');

  try {
    const res = await fetch(`${API_BASE}/collections/stories/records/${id}`);
    if (!res.ok) throw new Error(res.statusText);
    const item = await res.json();

    document.title = `${item.title} | Summitcraft Leadership`;
    if (titleEl) titleEl.textContent = item.title;
    if (dateEl) dateEl.textContent = formatDate(item.published_date || item.created);

    const img = coverUrl(item);
    if (coverEl) {
      if (img) {
        coverEl.src = img;
        coverEl.alt = item.title;
        coverEl.classList.remove('d-none');
      } else {
        coverEl.classList.add('d-none');
      }
    }

    if (bodyEl) bodyEl.innerHTML = item.content || '';
  } catch (err) {
    if (bodyEl) bodyEl.innerHTML = '<p class="text-danger">Story not found.</p>';
    console.error('Story fetch error:', err);
  }
}

/* ── XSS guard ── */
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

/* ── Auto-init ── */
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('stories-grid')) loadStories();
  if (document.getElementById('story-title')) loadStory();
});
