/* Multi-quote and quote selection (2026-11-08): progressive enhancement only. Without this file the per-post
   "Quote" link works as before. Choices live in sessionStorage per reply address; nothing is sent until the
   member follows the reply link, and a quoted selection is plain text put into the reply textarea. */
(() => {
    'use strict';
    const MAX = 10;
    const read = (key) => {
        try {
            const value = JSON.parse(window.sessionStorage.getItem(key) || 'null');
            return Array.isArray(value) ? value : [];
        } catch (error) {
            return [];
        }
    };
    const write = (key, value) => {
        try {
            if (value.length) {
                window.sessionStorage.setItem(key, JSON.stringify(value));
            } else {
                window.sessionStorage.removeItem(key);
            }
        } catch (error) {
            // Storage blocked: the buttons still work for this page view.
        }
    };
    const clean = (name) => String(name || '').replace(/[[\]]/g, '');

    // Reply page: append the selections quoted on the thread page.
    const area = document.getElementById('reply-content');
    if (area) {
        const key = 'nolviko.sq:' + location.pathname;
        const pending = read(key).filter(item => item && typeof item.text === 'string').slice(0, MAX);
        if (pending.length) {
            const text = pending.map(item => '[quote' + (clean(item.author) ? '=' + clean(item.author) : '') + ']'
                + item.text.slice(0, 5000) + '[/quote]\n').join('\n');
            area.value = (area.value.trim() ? area.value.replace(/\s+$/, '') + '\n\n' : '') + text;
            write(key, []);
        }
        return;
    }

    const bar = document.getElementById('multi-quote');
    if (!bar) {
        return;
    }
    const replyPath = new URL(bar.href, location.href).pathname;
    const mqKey = 'nolviko.mq:' + replyPath;
    const count = bar.querySelector('[data-count]');
    const buttons = Array.from(document.querySelectorAll('button.post-mq'));
    let ids = read(mqKey).map(String).filter(id => /^[1-9][0-9]{0,17}$/.test(id)).slice(0, MAX);

    const sync = () => {
        write(mqKey, ids);
        bar.hidden = ids.length === 0;
        if (count) {
            count.textContent = String(ids.length);
        }
        buttons.forEach(button => button.setAttribute('aria-pressed', ids.includes(button.dataset.post) ? 'true' : 'false'));
    };
    buttons.forEach(button => {
        button.hidden = false;
        button.addEventListener('click', () => {
            const id = button.dataset.post;
            ids = ids.includes(id) ? ids.filter(other => other !== id) : ids.concat(id).slice(-MAX);
            sync();
        });
    });
    bar.addEventListener('click', () => {
        const url = new URL(bar.href, location.href);
        url.searchParams.set('quote', ids.join(','));
        bar.href = url.toString();
        ids = [];
        write(mqKey, ids);
    });
    sync();

    // Quote selection: the button of the post whose body holds the whole selection.
    const selectionButtons = Array.from(document.querySelectorAll('button.post-sq'));
    const selected = () => {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
            return null;
        }
        const text = selection.toString().trim();
        const range = selection.getRangeAt(0);
        const body = range.commonAncestorContainer.nodeType === 1
            ? range.commonAncestorContainer.closest('.post-body')
            : range.commonAncestorContainer.parentElement && range.commonAncestorContainer.parentElement.closest('.post-body');
        return text && body ? { text, body } : null;
    };
    document.addEventListener('selectionchange', () => {
        const current = selected();
        selectionButtons.forEach(button => {
            const article = button.closest('article');
            button.hidden = !current || !article || !article.contains(current.body);
        });
    });
    selectionButtons.forEach(button => {
        // Keep the selection: a mousedown on the button would clear it before the click.
        button.addEventListener('mousedown', event => event.preventDefault());
        button.addEventListener('click', () => {
            const current = selected();
            if (!current) {
                return;
            }
            const key = 'nolviko.sq:' + replyPath;
            write(key, read(key).concat({ author: button.dataset.author || '', text: current.text.slice(0, 5000) }).slice(-MAX));
            location.href = bar.href.split('?')[0];
        });
    });
})();
