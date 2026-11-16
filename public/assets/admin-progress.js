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
