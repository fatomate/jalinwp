/** DOM-only admin checks against PHP-rendered disposable fixtures. This is not browser layout testing. */
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
const [fixtures, moduleRoot, output] = process.argv.slice(2);
if (!fixtures || !moduleRoot || !output) throw new Error('Usage: node admin-ui-030.mjs fixture-directory jsdom-module-root output.json');
const require = createRequire(pathToFileURL(path.join(moduleRoot, 'package.json')));
const { JSDOM } = require('jsdom');
const code = await readFile(path.join(fixtures, 'admin.js'), 'utf8');
const cases = [];
function check(name, passed) { assert.ok(passed, name); cases.push({ name, pass: true }); }
const dom = new JSDOM(await readFile(path.join(fixtures, 'connection.html'), 'utf8'), { runScripts: 'outside-only', url: 'https://fixture.invalid/connection.html' });
const w = dom.window;
w.fgConnection = { ajaxUrl: 'https://fixture.invalid/admin-ajax.php', nonce: 'fixture' };
w.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { checks: [{ label: 'OAuth enabled', status: 'pass', detail: 'Fixture details' }] } }) });
w.eval(code);
check('Copy URL enhancement is initialized', !w.document.querySelector('[data-copy-target]').hidden);
for (const box of w.document.querySelectorAll('input[type="checkbox"]')) {
 check(`${box.name} has visible linked help text`, !!w.document.getElementById(box.getAttribute('aria-describedby'))?.textContent.trim());
}
check('Only the two intended Access Controls checkboxes are rendered', w.document.querySelectorAll('input[type="checkbox"]').length === 2);
const modes = [...w.document.querySelectorAll('input[type="radio"][name="change_mode"]')];
check('Three change modes are rendered with permanent help', modes.length === 3 && modes.every(input => !!w.document.getElementById(input.getAttribute('aria-describedby'))?.textContent.trim()));
w.document.querySelector('#fg-mode-yolo').click();
check('Selecting YOLO selects only one native radio option', modes.filter(input => input.checked).length === 1 && w.document.querySelector('#fg-mode-yolo').checked);
const modeForm = w.document.querySelector('#fg-mode-yolo').form;
check('YOLO selection is submitted as an explicit mode', new w.FormData(modeForm).get('change_mode') === 'yolo' && !new w.FormData(modeForm).has('writes'));
const form = w.document.querySelector('form input[value="fg_disable_connection"]').form;
const first = new w.Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(first);
const second = new w.Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(second);
check('Repeated immediate-action submission is prevented', !first.defaultPrevented && second.defaultPrevented);
await new Promise(resolve => w.setTimeout(resolve, 1));
check('Submitted immediate action is visibly busy and disabled', form.getAttribute('aria-busy') === 'true' && form.querySelector('button').disabled);
w.document.querySelector('#fg-check-form').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
await new Promise(resolve => w.setTimeout(resolve, 1));
check('Diagnostic response uses Title Case without changing protocol labels', w.document.querySelector('#fg-check-results strong')?.textContent === 'OAuth Enabled');
check('Diagnostic completion re-enables the button', !w.document.querySelector('#fg-check-form button').disabled && w.document.querySelector('#fg-check-form button').textContent === 'Check Connection');
const withoutChecks = new JSDOM('<form class="fg-immediate-action"><button type="submit">Save</button></form>', { runScripts: 'outside-only' });
withoutChecks.window.eval(code);
const f = withoutChecks.window.document.querySelector('form'); f.dispatchEvent(new withoutChecks.window.Event('submit', { cancelable: true }));
check('Non-connection pages initialize actions without the diagnostics form', f.dataset.submitting === 'true');
dom.window.close(); withoutChecks.window.close();
await writeFile(output, JSON.stringify({ test: 'PHP-rendered admin DOM', browser_layout_test: false, passed: cases.length, total: cases.length, cases }, null, 2));
console.log(JSON.stringify({ passed: cases.length, total: cases.length, browser_layout_test: false }));
