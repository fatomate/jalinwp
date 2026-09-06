(function () {
    'use strict';

    /** @type {NodeListOf<HTMLButtonElement>} */
    var copyButtons = document.querySelectorAll('[data-copy-target]');
    copyButtons.forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', async function () {
            var target = button.dataset.copyTarget;
            var input = target ? document.getElementById(target) : null;
            var status = document.getElementById('fg-copy-status');
            if (!(input instanceof HTMLInputElement) || !status) { return; }
            input.focus();
            input.select();
            try {
                if (!navigator.clipboard || !window.isSecureContext) { throw new Error('clipboard_unavailable'); }
                await navigator.clipboard.writeText(input.value);
                status.textContent = 'URL copied. Paste it into your MCP connector settings.';
            } catch (error) {
                status.textContent = 'URL selected. Press Ctrl+C or Command+C to copy it.';
            }
        });
    });

    /** @type {NodeListOf<HTMLFormElement>} */
    var actionForms = document.querySelectorAll('.fg-immediate-action, .fg-settings-form');
    actionForms.forEach(function (actionForm) {
        actionForm.addEventListener('submit', function (event) {
            if (actionForm.dataset.submitting === 'true') { event.preventDefault(); return; }
            if (actionForm.dataset.fgConfirm && !window.confirm(actionForm.dataset.fgConfirm)) { event.preventDefault(); return; }
            actionForm.dataset.submitting = 'true';
            actionForm.setAttribute('aria-busy', 'true');
            window.setTimeout(function () {
                /** @type {NodeListOf<HTMLButtonElement | HTMLInputElement>} */
                var submitControls = actionForm.querySelectorAll('button[type="submit"], input[type="submit"]');
                submitControls.forEach(function (button) { button.disabled = true; });
            }, 0);
        });
    });

    /** @type {Record<string, string>} */
    var diagnosticLabels = {'Administrator access': 'Administrator Access', 'OAuth enabled': 'OAuth Enabled', 'Allowed accounts': 'Allowed Accounts', 'Gateway storage': 'Gateway Storage', 'Public discovery': 'Public Discovery', 'OAuth discovery': 'OAuth Discovery', 'MCP resource discovery': 'MCP Resource Discovery', 'Proactive MCP resource discovery': 'Proactive MCP Resource Discovery', 'Sign-in challenge': 'Sign-In Challenge', 'GET sign-in challenge': 'GET Sign-In Challenge', 'Final client test': 'Final Client Test'};
    function initConnectionChecks() {
    const form = document.getElementById('fg-check-form');
    if (!(form instanceof HTMLFormElement) || typeof fgConnection === 'undefined' || !window.fetch) { return; }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        var status = document.getElementById('fg-check-status');
        var results = document.getElementById('fg-check-results');
        if (!(button instanceof HTMLButtonElement) || !status || !results) { return; }
        button.disabled = true;
        button.textContent = 'Checking…';
        status.textContent = 'Checking the gateway and sign-in URLs. This can take a few moments.';
        results.replaceChildren();
        results.setAttribute('aria-busy', 'true');
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 60000);
        try {
            var body = new URLSearchParams({ action: 'fg_connection_check', _ajax_nonce: fgConnection.nonce });
            var response = await fetch(fgConnection.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal });
            /** @type {FGConnectionResponse} */
            var payload = await response.json();
            if (!response.ok || !payload.success || !payload.data || !Array.isArray(payload.data.checks)) {
                throw new Error('diagnostics_failed');
            }
            var list = document.createElement('ul');
            list.className = 'fg-check-list';
            payload.data.checks.forEach(function (check) {
                var state = ['pass', 'fail', 'warning'].includes(check.status) ? check.status : 'warning';
                var item = document.createElement('li');
                var badge = document.createElement('span');
                badge.className = 'fg-status fg-status-' + state;
                badge.textContent = state.charAt(0).toUpperCase() + state.slice(1);
                var description = document.createElement('div');
                var label = document.createElement('strong');
                var detail = document.createElement('p');
                label.textContent = typeof check.label === 'string' ? (diagnosticLabels[check.label] || check.label) : 'Connection Check';
                detail.textContent = typeof check.detail === 'string' ? check.detail : '';
                description.append(label, detail);
                item.append(badge, description);
                list.append(item);
            });
            results.append(list);
            status.textContent = payload.data.checks.some(function (check) { return check.status === 'fail'; })
                ? 'Checks completed. Resolve the failed checks, then try connecting again.'
                : 'Checks completed. Review any warnings, then finish connecting in your AI client.';
        } catch (error) {
            status.textContent = error instanceof Error && error.name === 'AbortError'
                ? 'The check timed out. Your host may be blocking requests to its own sign-in URLs. Ask your host to check loopback requests, then try again.'
                : 'The check could not finish. Reload this page and try again. If it persists, check your host or security plugin for blocked WordPress admin requests.';
        } finally {
            window.clearTimeout(timeout);
            button.disabled = false;
            button.textContent = 'Check Connection';
            results.removeAttribute('aria-busy');
        }
    });
    }
    initConnectionChecks();
})();
