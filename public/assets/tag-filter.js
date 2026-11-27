/*
 * Searchable tag choice (2026-11-27, roadmap Beta 18): with a long tag list, typing in the search box above the
 * checkboxes hides the tags that do not match. Ticked tags always stay visible. Without this file every tag is
 * listed as before.
 */
(() => {
    'use strict';
    document.querySelectorAll('[data-tag-filter]').forEach(input => {
        const set = input.closest('fieldset');
        if (!set) return;
        const items = [...set.querySelectorAll('.tag-picks-list > span')];
        input.hidden = false;
        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase();
            items.forEach(item => {
                const box = item.querySelector('input');
                const label = item.textContent.toLocaleLowerCase();
                item.hidden = query !== '' && !label.includes(query) && !(box && box.checked);
            });
        });
    });
})();
