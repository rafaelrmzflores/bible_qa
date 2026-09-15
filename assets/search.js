(function () {
    const input   = document.getElementById('bible-qa-search');
    const results = document.getElementById('bible-qa-results');
    if (!input || !results || typeof BibleQA === 'undefined') return;

    let timer;
    let controller;

    input.addEventListener('input', (e) => {
        clearTimeout(timer);
        const q = e.target.value.trim();

        if (q.length < 2) {
            results.innerHTML = '';
            return;
        }

        timer = setTimeout(() => runSearch(q), 250);
    });

    async function runSearch(q) {
        if (controller) controller.abort();
        controller = new AbortController();

        results.innerHTML = '<p class="bqa-loading">Searching…</p>';

        try {
            const url = `${BibleQA.root}search?q=${encodeURIComponent(q)}`;
            const res = await fetch(url, {
                headers: { 'X-WP-Nonce': BibleQA.nonce },
                signal: controller.signal,
            });
            const data = await res.json();
            renderResults(data.results || []);
        } catch (err) {
            if (err.name !== 'AbortError') {
                results.innerHTML = '<p class="bqa-error">Search failed. Try again.</p>';
            }
        }
    }

    function renderResults(items) {
        if (!items.length) {
            results.innerHTML = '<p class="bqa-empty">No results found.</p>';
            return;
        }
        results.innerHTML = items.map(item => `
            <a class="bqa-result" href="/qa/${encodeURIComponent(item.slug)}/">
                <h4>${escapeHtml(item.question)}</h4>
                <p>${escapeHtml(item.excerpt)}…</p>
            </a>
        `).join('');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }
})();