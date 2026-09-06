(() => {
    'use strict';
    const statuses = {
        available: 'Available', missing: 'Missing', invalid_value: 'Invalid Value',
        not_configured: 'Not Configured', assumed_order_currency: 'Assumed Order Currency',
        missing_or_invalid: 'Missing or Invalid Currency'
    };
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = String(text);
        if (className) node.className = className;
        return node;
    };
    document.querySelectorAll('.fg-finance-form').forEach((form) => {
        const button = form.querySelector('.fg-finance-preview');
        const output = form.querySelector('.fg-finance-results');
        const order = form.querySelector('[name="order_id"]');
        let generation = 0;
        let controller;
        form.addEventListener('input', () => {
            generation += 1;
            if (controller) controller.abort();
            if (output.childNodes.length) output.replaceChildren(element('p', 'Fields changed. Test again to see current results.', 'fg-finance-notice'));
            output.removeAttribute('aria-busy');
            if (button && button.dataset.testing === 'true') {
                button.disabled = false;
                button.dataset.testing = 'false';
                button.textContent = 'Test With This Order';
            }
        });
        if (button && window.fgFinance) button.addEventListener('click', async () => {
            if (!/^[1-9][0-9]{0,17}$/.test(order.value.trim())) {
                output.replaceChildren(element('p', 'Enter a valid Order ID to test.', 'fg-finance-notice'));
                order.focus();
                return;
            }
            const run = ++generation;
            controller = new AbortController();
            const requestController = controller;
            const timeout = window.setTimeout(() => requestController.abort(), 20000);
            const data = new FormData(form);
            data.set('action', 'fg_finance_preview');
            data.set('nonce', window.fgFinance.nonce);
            data.set('order_id', order.value.trim());
            button.disabled = true;
            button.dataset.testing = 'true';
            button.textContent = 'Testing…';
            output.setAttribute('aria-busy', 'true');
            output.replaceChildren(element('p', 'Reading only the selected finance fields…'));
            try {
                const response = await fetch(window.fgFinance.ajaxUrl, {method: 'POST', body: data, credentials: 'same-origin', signal: requestController.signal});
                const result = await response.json();
                if (run !== generation) return;
                if (!response.ok || !result.success) throw new Error(result.data?.message || 'The order could not be tested. Reload the page and retry.');
                const preview = result.data;
                const content = document.createDocumentFragment();
                content.append(element('h4', `Tested on Order #${preview.order_id} — Unsaved Mapping`));
                if (!preview.gateway_matches) content.append(element('p', `Different Payment Method: this order uses “${preview.order_gateway || 'none'}”, while this mapping is for “${preview.selected_gateway}”. Check a matching order before relying on these results.`, 'fg-finance-notice'));
                if (preview.affiliate_adapter) content.append(element('p', 'A site affiliate adapter supplies the affiliate result. Its values take precedence over the affiliate metadata fields.', 'fg-finance-notice'));
                const wrap = element('div', undefined, 'fg-finance-table-wrap');
                const table = element('table', undefined, 'widefat striped');
                const caption = element('caption', 'Normalized Finance Fields');
                table.append(caption);
                const head = element('thead');
                const heading = element('tr');
                ['Field', 'Value', 'Status', 'Source', 'Stored Amount Units'].forEach((label) => {
                    const cell = element('th', label);
                    cell.scope = 'col';
                    heading.append(cell);
                });
                head.append(heading);
                table.append(head);
                const body = element('tbody');
                preview.rows.forEach((row) => {
                    const tr = element('tr');
                    const label = element('th', row.field);
                    label.scope = 'row';
                    tr.append(label);
                    tr.append(element('td', row.value === null ? '—' : `${row.value}${row.currency ? ' ' + row.currency : ''}`));
                    const status = element('td', statuses[row.status] || 'Unavailable');
                    if (row.currency_status && row.currency_status !== 'available') status.append(element('small', statuses[row.currency_status] || 'Check Currency'));
                    tr.append(status);
                    const source = element('td');
                    source.append(element('code', row.source || 'Not Configured'));
                    tr.append(source);
                    tr.append(element('td', row.divisor === null ? '—' : `Divide by ${row.divisor}`));
                    body.append(tr);
                });
                table.append(body);
                wrap.append(table);
                content.append(wrap, element('p', preview.note, 'description'));
                output.replaceChildren(content);
            } catch (error) {
                if (run === generation) output.replaceChildren(element('p', error.name === 'AbortError' ? 'The order test timed out. Retry with the same Order ID.' : error.message, 'fg-finance-notice'));
            } finally {
                window.clearTimeout(timeout);
                if (run === generation) {
                    button.disabled = false;
                    button.dataset.testing = 'false';
                    button.textContent = 'Test With This Order';
                    output.removeAttribute('aria-busy');
                }
            }
        });
        form.addEventListener('submit', () => {
            const save = form.querySelector('[type="submit"]');
            if (save) { save.disabled = true; save.textContent = 'Saving…'; }
        });
    });
})();
