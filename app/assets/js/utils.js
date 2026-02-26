// CSRF token from meta tag
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

// Wrap fetch to auto-inject CSRF header on mutating requests
const _origFetch = window.fetch.bind(window);
window.fetch = function(url, opts = {}) {
    const method = (opts.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        opts.headers = Object.assign({}, opts.headers, { 'X-CSRF-Token': CSRF_TOKEN });
    }
    return _origFetch(url, opts);
};

// Toast notifications
function showToast(message, type = 'success', duration = 3000) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('toast-visible'), 10);
    setTimeout(() => {
        toast.classList.remove('toast-visible');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// Confirm modal
// options: { confirmLabel, confirmClass, requireWord }
// When requireWord is set, the confirm button stays disabled until the user types it exactly.
function showConfirm(message, onConfirm, options = {}) {
    const modal     = document.getElementById('confirm-modal');
    const wordDiv   = document.getElementById('confirm-word-check');
    const wordInput = document.getElementById('confirm-word-input');
    let yesBtn = document.getElementById('confirm-yes');
    let noBtn  = document.getElementById('confirm-no');

    document.getElementById('confirm-message').textContent = message;
    yesBtn.textContent = options.confirmLabel || 'Confirmar';
    yesBtn.className   = 'btn ' + (options.confirmClass || 'btn-danger');

    const requireWord = options.requireWord || null;
    wordInput.value   = '';

    if (requireWord) {
        wordDiv.style.display = 'block';
        yesBtn.disabled = true;
        wordInput.oninput = () => {
            yesBtn.disabled = wordInput.value !== requireWord;
        };
        wordInput.onkeydown = (e) => {
            if (e.key === 'Enter' && !yesBtn.disabled) yesBtn.click();
        };
    } else {
        wordDiv.style.display = 'none';
        yesBtn.disabled = false;
        wordInput.oninput  = null;
        wordInput.onkeydown = null;
    }

    modal.classList.remove('hidden');
    if (requireWord) setTimeout(() => wordInput.focus(), 50);

    function cleanup() {
        modal.classList.add('hidden');
        wordInput.value     = '';
        wordInput.oninput   = null;
        wordInput.onkeydown = null;
        const newYes = yesBtn.cloneNode(true);
        const newNo  = noBtn.cloneNode(true);
        newYes.disabled = false;
        yesBtn.replaceWith(newYes);
        noBtn.replaceWith(newNo);
    }

    yesBtn.addEventListener('click', () => { cleanup(); onConfirm(); }, { once: true });
    noBtn.addEventListener('click', cleanup, { once: true });
}

async function apiFetch(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    return res.json();
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const now   = new Date();
    const then  = new Date(dateStr);
    if (isNaN(then.getTime())) return dateStr;
    const diffMs  = now - then;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffH   = Math.floor(diffMin / 60);
    const diffD   = Math.floor(diffH / 24);
    const diffW   = Math.floor(diffD / 7);
    const diffMo  = Math.floor(diffD / 30);
    const diffY   = Math.floor(diffD / 365);
    if (diffSec < 60)  return 'ahora mismo';
    if (diffMin < 60)  return `hace ${diffMin} min`;
    if (diffH < 24)    return `hace ${diffH}h`;
    if (diffD === 1)   return 'ayer';
    if (diffD < 7)     return `hace ${diffD} días`;
    if (diffW < 5)     return `hace ${diffW} semanas`;
    if (diffMo < 12)   return `hace ${diffMo} meses`;
    return `hace ${diffY} años`;
}

(function initKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        const tag = document.activeElement ? document.activeElement.tagName : '';
        const isEditing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                       || (document.activeElement && document.activeElement.isContentEditable);
        if (isEditing) return;

        if (e.key === '/') {
            e.preventDefault();
            var searchInput = document.getElementById('search-input');
            if (searchInput) searchInput.focus();
        }

        if (e.key === 'Escape') {
            // Close search results
            var searchResults = document.getElementById('search-results');
            if (searchResults) searchResults.classList.add('hidden');
            // Close any open modal
            document.querySelectorAll('.modal:not(.hidden)').forEach(function(m) {
                m.classList.add('hidden');
            });
            // Blur search input if focused
            if (document.activeElement && document.activeElement.id === 'search-input') {
                document.activeElement.blur();
            }
            // Fire custom event so page-specific code can react
            document.dispatchEvent(new CustomEvent('app:escape'));
        }

        if (e.key === '?') {
            showToast('Atajos: / = buscar · n = nueva issue (en issues) · Esc = cerrar', 'info', 5000);
        }
    });
})();
