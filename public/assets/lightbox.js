/* Image lightbox (2026-11-08): progressive enhancement. Images in posts and gallery thumbnails open full size in
   a native <dialog>; Escape, the close button or a click outside closes it. Without this file the links and
   images work as before. Only same-origin image addresses already on the page are shown. */
(() => {
    'use strict';
    const labels = document.getElementById('lightbox-labels');
    if (!labels || typeof HTMLDialogElement === 'undefined') {
        return;
    }
    const dialog = document.createElement('dialog');
    dialog.className = 'lightbox';
    dialog.setAttribute('aria-label', labels.dataset.title || '');
    const image = document.createElement('img');
    image.alt = '';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'lightbox-close';
    close.textContent = labels.dataset.close || '×';
    dialog.append(close, image);
    document.body.append(dialog);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
    dialog.addEventListener('close', () => image.removeAttribute('src'));

    const sameOrigin = url => {
        try {
            return new URL(url, location.href).origin === location.origin;
        } catch (error) {
            return false;
        }
    };
    const open = (src, alt) => {
        if (!sameOrigin(src)) {
            return false;
        }
        image.src = src;
        image.alt = alt || '';
        dialog.showModal();
        return true;
    };

    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        const zoom = target ? target.closest('[data-lightbox]') : null;
        if (zoom && open(zoom.getAttribute('data-lightbox'), zoom.getAttribute('data-lightbox-alt'))) {
            event.preventDefault();
            return;
        }
        const picture = target ? target.closest('.post-body img') : null;
        if (picture && !picture.closest('a') && open(picture.currentSrc || picture.src, picture.alt)) {
            event.preventDefault();
        }
    });
})();
