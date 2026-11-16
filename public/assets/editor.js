/* BBCode toolbar (ADR-030): progressive enhancement only. Without this file the textarea works as before. */
(() => {
    'use strict';
    const blocks = new Set(['quote', 'code', 'list', 'spoiler']);

    const insert = (area, text, selectFrom, selectTo) => {
        area.focus();
        // execCommand keeps the browser undo history; setRangeText is the fallback.
        if (!document.execCommand || !document.execCommand('insertText', false, text)) {
            area.setRangeText(text, area.selectionStart, area.selectionEnd, 'end');
            area.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const end = area.selectionEnd;
        area.setSelectionRange(end - text.length + selectFrom, end - text.length + selectTo);
    };

    const wrap = (area, tag) => {
        const selected = area.value.slice(area.selectionStart, area.selectionEnd);
        if (tag === 'list') {
            const lines = (selected || '').split(/\r?\n/).filter(line => line.trim() !== '');
            const items = (lines.length ? lines : ['']).map(line => '[*]' + line).join('\n');
            const text = '[list]\n' + items + '\n[/list]';
            const caret = lines.length ? text.length : 10;
            insert(area, text, caret, caret);
            return;
        }
        if (tag === 'url') {
            const isUrl = /^https?:\/\/\S+$/i.test(selected);
            const open = isUrl ? '[url]' : '[url=https://]';
            const text = open + selected + '[/url]';
            // Select the address part when it still has to be typed, otherwise the whole link text.
            if (isUrl) insert(area, text, 5, 5 + selected.length);
            else insert(area, text, 5, 13);
            return;
        }
        const nl = blocks.has(tag) && selected.includes('\n') ? '\n' : '';
        const open = '[' + tag + ']' + nl;
        const text = open + selected + nl + '[/' + tag + ']';
        insert(area, text, open.length, open.length + selected.length);
    };

    const setup = bar => {
        const area = document.getElementById(bar.dataset.editor);
        if (!(area instanceof HTMLTextAreaElement) || bar.dataset.ready === '1') return;
        bar.dataset.ready = '1';
        const buttons = [...bar.querySelectorAll('button[data-bb]')];
        bar.hidden = false;
        bar.addEventListener('click', event => {
            const button = event.target.closest('button[data-bb]');
            if (button) wrap(area, button.dataset.bb);
        });
        // Roving tabindex: one tab stop for the toolbar, arrow keys move inside it (WAI-ARIA toolbar pattern).
        bar.addEventListener('keydown', event => {
            const index = buttons.indexOf(document.activeElement);
            if (index < 0) return;
            const rtl = getComputedStyle(bar).direction === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -index, End: buttons.length - 1 - index }[event.key];
            if (step === undefined) return;
            event.preventDefault();
            const next = (index + step + buttons.length) % buttons.length;
            buttons.forEach((button, i) => { button.tabIndex = i === next ? 0 : -1; });
            buttons[next].focus();
        });
        area.addEventListener('keydown', event => {
            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return;
            const button = buttons.find(item => item.dataset.key === event.key.toLowerCase());
            if (!button) return;
            event.preventDefault();
            wrap(area, button.dataset.bb);
        });
    };

    // Emoji picker (2026-11-08): Unicode characters only, inserted at the caret; no images or remote fonts.
    const EMOJI = ['😀', '😂', '🙂', '😉', '😍', '🤔', '😎', '😢', '😡', '😮', '🙏', '👍', '👎', '👏', '🙌', '💪',
        '🎉', '🔥', '✨', '❤️', '💔', '⭐', '✅', '❌', '⚠️', '❓', '💡', '📌', '📷', '🎮', '⚽', '🍀'];
    const emoji = (bar, area) => {
        const toggle = bar.querySelector('[data-emoji-toggle]');
        if (!toggle) return;
        let panel = null;
        const close = () => {
            if (panel) { panel.remove(); panel = null; toggle.setAttribute('aria-expanded', 'false'); }
        };
        toggle.addEventListener('click', () => {
            if (panel) { close(); return; }
            panel = document.createElement('span');
            panel.className = 'emoji-panel';
            EMOJI.forEach(char => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = char;
                button.addEventListener('click', () => { insert(area, char, char.length, char.length); close(); });
                panel.append(button);
            });
            toggle.after(panel);
            toggle.setAttribute('aria-expanded', 'true');
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    };

    // Add image (2026-11-09): upload to the member's gallery, then insert [img]address[/img] at the caret.
    const image = (bar, area) => {
        const button = bar.querySelector('[data-image-upload]');
        if (!button) return;
        const status = bar.querySelector('.editor-status');
        const picker = document.createElement('input');
        picker.type = 'file';
        picker.accept = 'image/jpeg,image/png,image/gif,image/webp';
        picker.hidden = true;
        bar.append(picker);
        const say = text => { if (status) status.textContent = text || ''; };
        // The server checks the same limit; this only saves a pointless upload.
        const full = () => (area.value.match(/\[img\]/gi) || []).length >= Number(button.dataset.max || 0);
        button.addEventListener('click', () => {
            if (button.dataset.max && full()) {
                say(button.dataset.full);
                return;
            }
            picker.click();
        });
        picker.addEventListener('change', async () => {
            const file = picker.files && picker.files[0];
            if (!file) return;
            const body = new FormData();
            body.append('file', file);
            body.append('_csrf_token', button.dataset.token || '');
            button.disabled = true;
            say(button.dataset.busy);
            try {
                const response = await fetch(button.dataset.imageUpload, {
                    method: 'POST', body, credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-Token': button.dataset.token || '' },
                });
                const data = await response.json();
                if (response.ok && typeof data.url === 'string') {
                    const text = '[img]' + data.url + '[/img]';
                    insert(area, text, text.length, text.length);
                    say(data.notice);
                } else {
                    say(typeof data.error === 'string' ? data.error : '');
                }
            } catch (error) {
                say('');
            }
            button.disabled = false;
            picker.value = '';
        });
    };

    const start = () => document.querySelectorAll('[data-editor]').forEach(bar => {
        setup(bar);
        const target = document.getElementById(bar.dataset.editor);
        if (target instanceof HTMLTextAreaElement && bar.dataset.image !== '1') { bar.dataset.image = '1'; image(bar, target); }
        const area = document.getElementById(bar.dataset.editor);
        if (area instanceof HTMLTextAreaElement && bar.dataset.emoji !== '1') { bar.dataset.emoji = '1'; emoji(bar, area); }
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
