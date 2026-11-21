/* Member name suggestions (2026-11-08; thread participants first and caret placement 2026-11-09): progressive
   enhancement for inputs with data-autocomplete="members" (message recipient, search author) and for editors
   with data-mentions (@mentions). In a thread (data-thread) typing "@" already lists the people who posted
   there; more letters narrow the list and add other members. Without this file the fields stay plain text. */
(() => {
    'use strict';
    const source = document.querySelector('[data-suggest]');
    const endpoint = source ? source.getAttribute('data-suggest') : null;
    if (!endpoint) {
        return;
    }
    const cache = new Map();
    const lookup = async (prefix, thread) => {
        const key = (thread || '') + ':' + prefix.toLowerCase();
        if (cache.has(key)) {
            return cache.get(key);
        }
        try {
            const query = '?q=' + encodeURIComponent(prefix) + (thread ? '&thread=' + encodeURIComponent(thread) : '');
            const response = await fetch(endpoint + query, {
                credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            const names = response.ok ? await response.json() : [];
            const list = Array.isArray(names) ? names.filter(name => typeof name === 'string').slice(0, 8) : [];
            cache.set(key, list);
            return list;
        } catch (error) {
            return [];
        }
    };
    const debounce = (fn, ms) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    };

    // Page position of the caret in a textarea, measured on an invisible copy with the same text layout.
    const caret = area => {
        const style = getComputedStyle(area);
        const mirror = document.createElement('div');
        ['boxSizing', 'width', 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'paddingTop',
            'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderLeftWidth', 'whiteSpace',
            'wordWrap', 'tabSize', 'direction'].forEach(property => { mirror.style[property] = style[property]; });
        mirror.style.position = 'absolute';
        mirror.style.visibility = 'hidden';
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.overflowWrap = 'break-word';
        mirror.textContent = area.value.slice(0, area.selectionStart);
        const mark = document.createElement('span');
        mark.textContent = '​';
        mirror.append(mark);
        document.body.append(mirror);
        const rect = area.getBoundingClientRect();
        const top = rect.top + window.scrollY + mark.offsetTop - area.scrollTop + parseFloat(style.lineHeight || '20');
        const left = rect.left + window.scrollX + Math.min(mark.offsetLeft, rect.width - 40);
        mirror.remove();
        return { top, left };
    };

    let menu = null;
    let active = -1;
    const close = () => {
        if (menu) {
            menu.remove();
            menu = null;
            active = -1;
        }
    };
    const show = (field, names, choose, place) => {
        close();
        if (!names.length) {
            return;
        }
        menu = document.createElement('ul');
        menu.className = 'suggest-menu';
        menu.setAttribute('role', 'listbox');
        names.forEach((name, index) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
            item.textContent = name;
            item.addEventListener('mousedown', event => {
                event.preventDefault();
                choose(name);
                close();
            });
            menu.append(item);
        });
        active = 0;
        const rect = field.getBoundingClientRect();
        const at = place || { top: rect.bottom + window.scrollY + 2, left: rect.left + window.scrollX };
        menu.style.left = at.left + 'px';
        menu.style.top = at.top + 'px';
        menu.style.minWidth = '180px';
        document.body.append(menu);
    };
    const keys = choose => event => {
        if (!menu) {
            return;
        }
        const items = Array.from(menu.children);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items.forEach((item, index) => item.setAttribute('aria-selected', index === active ? 'true' : 'false'));
        } else if ((event.key === 'Enter' || event.key === 'Tab') && active >= 0) {
            event.preventDefault();
            choose(items[active].textContent);
            close();
        } else if (event.key === 'Escape') {
            close();
        }
    };

    document.querySelectorAll('input[data-autocomplete="members"]').forEach(field => {
        // Beta 17: data-multiple fields hold a comma-separated list; only the last name is completed.
        const multiple = field.hasAttribute('data-multiple');
        const head = () => multiple ? field.value.replace(/[^,;]*$/u, '') : '';
        const choose = name => {
            field.value = multiple ? head() + (head() === '' ? '' : ' ') + name + ', ' : name;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        };
        field.addEventListener('input', debounce(async () => {
            const value = field.value.slice(head().length).trim();
            show(field, value.length >= 2 ? await lookup(value, '') : [], choose, null);
        }, 150));
        field.addEventListener('keydown', keys(choose));
        field.addEventListener('blur', () => setTimeout(close, 150));
    });

    document.querySelectorAll('textarea[data-mentions]').forEach(area => {
        const thread = area.getAttribute('data-thread') || '';
        const token = () => {
            const before = area.value.slice(0, area.selectionStart);
            const match = /(^|[\s(>])@([^\s@"]{0,32})$/u.exec(before);
            if (!match) {
                return null;
            }
            return match[2].length >= 2 || thread ? match[2] : null;
        };
        const choose = name => {
            const at = area.selectionStart;
            const before = area.value.slice(0, at).replace(/@([^\s@"]{0,32})$/u, '');
            const mention = /\s/.test(name) ? '@"' + name + '" ' : '@' + name + ' ';
            area.value = before + mention + area.value.slice(at);
            const position = before.length + mention.length;
            area.setSelectionRange(position, position);
            area.focus();
        };
        const update = debounce(async () => {
            const prefix = token();
            if (prefix === null) {
                close();
                return;
            }
            show(area, await lookup(prefix, thread), choose, caret(area));
        }, 120);
        area.addEventListener('input', update);
        area.addEventListener('keydown', keys(choose));
        area.addEventListener('blur', () => setTimeout(close, 150));
    });
})();
