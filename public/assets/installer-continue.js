/* Installer (beta.10): when one batch of database updates was not enough, the next one starts by itself.
   Without JavaScript the page keeps its "Continue" button. */
(() => {
    'use strict';
    const form = document.querySelector('form[data-auto-continue]');
    if (!form) return;
    const note = document.querySelector('[data-auto-continue-note]');
    if (note) note.hidden = false;
    window.setTimeout(() => form.requestSubmit(), 400);
})();
