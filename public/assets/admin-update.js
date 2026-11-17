/*
 * One-click core update (2026-11-17, owner: "like WordPress"). The button drives the existing server steps one after
 * another: download and verify, prepare the files, the safety copy, then the recovery steps (backup, maintenance,
 * file switch, migrations, check, finish). Every step is resumable on the server, so a closed page loses nothing.
 */
(() => {
    'use strict';
    const box = document.querySelector('[data-update-driver]');
    if (!box) return;
    box.hidden = false;
    const start = box.querySelector('[data-update-start]');
    const status = box.querySelector('[data-update-status]');
    const problems = box.querySelector('[data-update-problems]');
    const bar = box.querySelector('progress');
    const fallback = box.querySelector('[data-update-code]');
    const text = JSON.parse(box.dataset.text);
    let hasJob = box.dataset.job === '1';
    let grant = null;
    let running = false;

    const say = (key, count) => {
        status.textContent = text[key] + (Number.isSafeInteger(count) && count > 0 ? ' (' + count + ')' : '');
    };

    const dialog = box.querySelector('[data-update-password]');

    /* Owner decision 2026-11-18: steps that can break the site ask for the password in this popup, once. */
    const askPassword = async () => {
        const form = dialog.querySelector('form');
        const error = dialog.querySelector('[data-update-password-error]');
        const codeField = dialog.querySelector('[data-update-code-field]');
        const headers = {'X-Nolviko-Update': '1', 'Accept': 'application/json'};
        try {
            const info = await (await fetch(dialog.dataset.url, {credentials: 'same-origin', cache: 'no-store', headers})).json();
            codeField.hidden = info.needs_code !== true;
        } catch { codeField.hidden = false; }
        form.reset();
        error.hidden = true;
        dialog.showModal();
        return new Promise(resolve => {
            let settled = false;
            const finish = value => {
                if (settled) return;
                settled = true;
                form.removeEventListener('submit', submit);
                dialog.removeEventListener('close', closed);
                resolve(value);
            };
            const closed = () => finish(false);
            dialog.addEventListener('close', closed);
            const submit = async event => {
                if (event.submitter?.value === 'cancel') {
                    finish(false);
                    return;
                }
                event.preventDefault();
                const body = new FormData(form);
                body.append(box.dataset.csrfField, box.dataset.csrf);
                let result = null;
                try {
                    result = await (await fetch(dialog.dataset.url, {
                        method: 'POST', credentials: 'same-origin', cache: 'no-store', headers, body,
                    })).json();
                } catch { result = null; }
                if (result && result.ok) {
                    finish(true);
                    dialog.close();
                    return;
                }
                error.textContent = (result && result.message) || text.failed;
                error.hidden = false;
            };
            form.addEventListener('submit', submit);
        });
    };

    const post = async action => {
        const body = new FormData();
        body.append(box.dataset.csrfField, box.dataset.csrf);
        body.append('action', action);
        body.append('confirmed', '1');
        const response = await fetch(box.dataset.url, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'X-Nolviko-Update': '1', 'Accept': 'application/json'}, body,
        });
        let result = null;
        try { result = await response.json(); } catch { result = null; }
        if (response.status === 403 && result && result.password === true) {
            if (await askPassword()) return post(action);
            throw new Error('update_cancelled');
        }
        if (!response.ok || !result || !result.ok) {
            const failure = new Error('update_step_failed');
            failure.result = result;
            throw failure;
        }
        return result;
    };

    const recover = async () => {
        const parts = grant.split('.');
        let sequence = 0;
        const send = async (action, reconcile = true) => {
            const response = await fetch(box.dataset.recovery, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json', 'X-Nolviko-Recovery-Grant': parts[0],
                    'X-Nolviko-Recovery-Code': parts[1], 'X-Nolviko-Recovery-Nonce': parts[2],
                    'X-Nolviko-Recovery-Sequence': String(sequence),
                },
                body: JSON.stringify({action}),
            });
            const result = await response.json();
            if (Number.isSafeInteger(result.next_sequence)) sequence = result.next_sequence;
            if (response.status === 409 && result.error === 'recovery_sequence_conflict' && reconcile) {
                return send('status', false);
            }
            if (!response.ok) throw new Error('update_recovery_failed');
            return result;
        };
        for (let round = 0; round < 5000; round++) {
            const state = await send('status');
            if (state.complete) return;
            if (!state.next_action) throw new Error('update_recovery_stopped');
            say('recovery_' + state.next_action, state.cursor);
            await send(state.next_action);
        }
        throw new Error('update_recovery_budget');
    };

    const run = async () => {
        running = true;
        start.disabled = true;
        problems.hidden = true;
        problems.replaceChildren();
        bar.hidden = false;
        try {
            if (grant === null) {
                if (!hasJob) {
                    say('download');
                    await post('download_release');
                    hasJob = true;
                }
                for (let round = 0; ; round++) {
                    const step = await post('stage_release');
                    say('stage', step.cursor);
                    if (step.ready) break;
                    if (round > 1000) throw new Error('update_stage_budget');
                }
                for (let round = 0; grant === null; round++) {
                    say('copy');
                    const step = await post('prepare_recovery');
                    if (step.refused || round > 1000) throw new Error('update_copy_refused');
                    grant = step.grant;
                }
                fallback.querySelector('code').textContent = grant;
                fallback.hidden = false;
            }
            await recover();
            running = false;
            say('done');
            window.setTimeout(() => window.location.reload(), 2000);
        } catch (failure) {
            running = false;
            say(failure.message === 'update_cancelled' ? 'cancelled' : 'failed');
            const result = failure.result || {};
            for (const message of (result.problems || [])) {
                const item = document.createElement('li');
                item.textContent = message;
                problems.append(item);
            }
            if ((result.codes || []).length > 0) {
                const item = document.createElement('li');
                item.textContent = text.code + ' ' + result.codes.join(', ');
                problems.append(item);
            }
            problems.hidden = problems.children.length === 0;
            bar.hidden = true;
            start.textContent = text.continue;
            start.disabled = false;
        }
    };

    window.addEventListener('beforeunload', event => {
        if (running) event.preventDefault();
    });
    start.addEventListener('click', run);
})();
