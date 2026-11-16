/* Progressive enhancement only: server-rendered navigation stays usable without this file. */
(() => {
    'use strict';
    const panel = document.querySelector('[data-admin-commands]');
    if (!panel) return;
    const summary = panel.querySelector('summary');
    const input = panel.querySelector('[data-command-filter]');
    const tools = panel.querySelector('[data-command-tools]');
    const status = panel.querySelector('[data-command-status]');
    const links = [...panel.querySelectorAll('[data-command-results] a[href]')];
    if (!summary || !input || !tools || !status) return;
    let restore = summary;
    const fold = value => {
        try { return value.normalize('NFKC').toLocaleLowerCase(document.documentElement.lang || 'en'); }
        catch { return value.normalize('NFKC').toLowerCase(); }
    };
    const labels = links.map(link => fold(link.textContent));
    const visible = () => links.filter(link => !link.parentElement.hidden);
    const filter = () => {
        const terms = fold(input.value.slice(0, 120)).trim().split(/\s+/).filter(Boolean);
        links.forEach((link, index) => { link.parentElement.hidden = !terms.every(term => labels[index].includes(term)); });
        status.textContent = visible().length ? '' : status.dataset.emptyMessage;
    };
    tools.hidden = false;
    input.addEventListener('input', filter);
    panel.addEventListener('toggle', () => { if (panel.open && document.activeElement === summary) input.focus(); });
    document.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.isComposing) return;
        if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            if (!panel.contains(document.activeElement)) restore = document.activeElement;
            panel.open = true;
            input.focus();
            input.select();
        }
    });
    panel.addEventListener('keydown', event => {
        if (event.isComposing || event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            panel.open = false;
            input.value = '';
            filter();
            (restore?.isConnected && restore !== document.body && !panel.contains(restore) ? restore : summary).focus();
            return;
        }
        const available = visible();
        const current = available.indexOf(document.activeElement);
        if (!available.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const index = current < 0 ? (event.key === 'ArrowDown' ? 0 : available.length - 1)
                : (current + (event.key === 'ArrowDown' ? 1 : -1) + available.length) % available.length;
            available[index].focus();
        } else if (current >= 0 && (event.key === 'Home' || event.key === 'End')) {
            event.preventDefault();
            available[event.key === 'Home' ? 0 : available.length - 1].focus();
        } else if (document.activeElement === input && event.key === 'Enter') {
            event.preventDefault();
            available[0].click();
        }
    });
})();
