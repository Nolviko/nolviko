/* Imports (beta.11): while this page is open the import keeps running, one request after another; each request
   runs several small batches on the server and saves its place. When a response is lost the next request is answered
   with the saved checkpoint, so nothing is copied twice. Without JavaScript the "Continue" button does the same. */
(() => {
    'use strict';
    const form = document.querySelector('form[data-import-run]');
    if (!form) return;
    const status = document.querySelector('[data-import-status]');
    const button = form.querySelector('button');
    const revisionField = form.querySelector('input[name=revision]');
    const number = new Intl.NumberFormat(document.documentElement.lang || undefined);
    let failures = 0;
    const show = text => {
        if (!status) return;
        status.hidden = false;
        status.textContent = text;
    };
    const progress = result => (status?.dataset.running || '')
        .replace('{processed}', number.format(result.processed))
        .replace('{verified}', number.format(result.verified));
    const stop = text => {
        show(text);
        if (button) button.hidden = false;
    };
    const tick = async () => {
        const body = new FormData(form);
        body.set('action', 'run');
        body.set('revision', revisionField.value);
        let result = null;
        try {
            // getAttribute: the submit button is named "action", which hides form.action.
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'},
            });
            result = await response.json();
        } catch (error) {
            result = null;
        }
        if (result && result.ok) {
            failures = 0;
            revisionField.value = String(result.revision);
            show(progress(result));
            if (result.complete) {
                show(status?.dataset.done || '');
                window.setTimeout(() => { window.location.href = form.dataset.importDone; }, 800);
                return;
            }
            window.setTimeout(tick, 50);
            return;
        }
        if (result === null && failures < 5) {
            failures += 1;
            window.setTimeout(tick, 2000 * failures);
            return;
        }
        stop(result && result.error ? result.error : (status?.dataset.paused || ''));
    };
    if (button) button.hidden = true;
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (button) button.hidden = true;
        failures = 0;
        tick();
    });
    tick();
})();
