/*
 * Unsent replies survive an ended session (beta.10). The reply text is copied to this browser while it is typed;
 * if the session ends and the member signs in again, the reply page fills the text back in and says so. Nothing is
 * sent without the member pressing the button. After a successful reply the server sets a short cookie with that
 * form's one-time token and the copy is dropped, so a posted text never comes back.
 */
(() => {
    'use strict';
    const PREFIX = 'nolviko-draft:';
    const WEEK = 7 * 24 * 60 * 60 * 1000;
    const read = key => {
        try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch { return null; }
    };
    const drop = key => {
        try { window.localStorage.removeItem(key); } catch { /* storage may be unavailable */ }
    };
    const posted = (document.cookie.match(/(?:^|;\s*)nolviko_posted=([0-9a-f]{32})/) || [])[1] || null;
    try {
        for (let i = window.localStorage.length - 1; i >= 0; i--) {
            const key = window.localStorage.key(i);
            if (!key || !key.startsWith(PREFIX)) continue;
            const value = read(key);
            if (!value || value.until < Date.now() || (posted !== null && value.token === posted)) drop(key);
        }
    } catch { /* storage may be unavailable */ }

    const form = document.querySelector('form[data-draft-keeper]');
    if (!form) return;
    const key = PREFIX + form.dataset.draftKeeper;
    const area = form.querySelector('textarea[name=content]');
    const token = form.querySelector('input[name=token]');
    if (!area) return;
    const saved = read(key);
    if (saved && typeof saved.text === 'string' && saved.text.trim() !== '' && area.value.trim() === '') {
        area.value = saved.text;
        const note = document.querySelector('[data-draft-restored]');
        if (note) note.hidden = false;
    }
    const save = () => {
        if (area.value.trim() === '') { drop(key); return; }
        try {
            window.localStorage.setItem(key, JSON.stringify({
                text: area.value, token: token ? token.value : '', until: Date.now() + WEEK,
            }));
        } catch { /* storage may be full or unavailable */ }
    };
    let timer = 0;
    area.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(save, 400);
    });
    form.addEventListener('submit', save);
})();
