/* Keep SSR submitters successful: aria-disabled prevents repetition without dropping their action field. */
(() => {
    'use strict';
    const reset = () => document.querySelectorAll('form[data-admin-progress]').forEach(form => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('[aria-disabled]').forEach(button => button.removeAttribute('aria-disabled'));
        form.querySelectorAll('[data-submit-status]').forEach(status => { status.hidden = true; });
    });
    window.addEventListener('pageshow', () => {
        reset();
        const announcement = document.querySelector('[data-admin-announcement]');
        if (announcement) announcement.textContent = '';
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-progress]')) return;
        if (event.submitter?.hasAttribute('data-no-progress')) return;
        if (form.dataset.submitting === '1') { event.preventDefault(); return; }
        if (!event.submitter?.formNoValidate && !form.checkValidity()) return;
        form.dataset.submitting = '1';
        form.setAttribute('aria-busy', 'true');
        const announcement = document.querySelector('[data-admin-announcement]');
        if (announcement) announcement.textContent = form.querySelector('[data-submit-status]')?.textContent || '';
        form.querySelectorAll('button[type=submit],input[type=submit]').forEach(button => button.setAttribute('aria-disabled', 'true'));
        form.querySelectorAll('[data-submit-status]').forEach(status => { status.hidden = false; });
    });
})();
/* Drop zones (2026-11-17): drop or choose a ZIP and the form is sent at once when its other fields are valid. */
(() => {
    'use strict';
    document.querySelectorAll('form[data-dropzone]').forEach(form => {
        const zone = form.querySelector('.dropzone');
        const input = form.querySelector('input[type=file]');
        if (!zone || !input) return;
        const send = () => {
            if (input.files.length === 0) return;
            if (form.checkValidity()) form.requestSubmit(); else form.reportValidity();
        };
        ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => {
            event.preventDefault();
            zone.classList.add('is-over');
        }));
        ['dragleave', 'dragend'].forEach(type => zone.addEventListener(type, () => zone.classList.remove('is-over')));
        zone.addEventListener('drop', event => {
            event.preventDefault();
            zone.classList.remove('is-over');
            if (!event.dataTransfer || event.dataTransfer.files.length === 0) return;
            input.files = event.dataTransfer.files;
            send();
        });
        input.addEventListener('change', send);
    });
})();
