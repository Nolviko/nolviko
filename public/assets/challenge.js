/* Registration proof of work (ADR-035). Without JavaScript or Web Crypto the sum question stays and is required. */
(() => {
    'use strict';
    const form = document.querySelector('[data-work-salt]');
    if (!form || !window.crypto || !window.crypto.subtle || !window.TextEncoder) return;

    const salt = form.dataset.workSalt;
    const bits = Number(form.dataset.workBits);
    const field = form.querySelector('input[name="' + form.dataset.workField + '"]');
    const question = form.querySelector('[data-work-fallback]');
    const status = form.querySelector('[data-work-status]');
    if (!field || !Number.isInteger(bits) || bits < 1 || bits > 24) return;

    const encoder = new TextEncoder();
    const zeroBits = bytes => {
        let count = 0;
        for (const byte of bytes) {
            if (byte === 0) { count += 8; continue; }
            return count + Math.clz32(byte) - 24;
        }
        return count;
    };

    const solve = async () => {
        if (status) status.hidden = false;
        for (let nonce = 0; nonce < 50000000; nonce++) {
            const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(salt + nonce)));
            if (zeroBits(digest) >= bits) return String(nonce);
        }
        return '';
    };

    solve().then(nonce => {
        if (nonce === '') return;
        field.value = nonce;
        if (question) {
            question.hidden = true;
            question.querySelectorAll('input').forEach(input => { input.required = false; });
        }
        if (status) status.hidden = true;
    }).catch(() => { if (status) status.hidden = true; });
})();
