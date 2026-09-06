/** DOM-only admin checks against PHP-rendered disposable fixtures. This is not browser layout testing. */
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
/** @typedef {{ name: string, pass: boolean }} Case */
const [fixtures, moduleRoot, output] = process.argv.slice(2);
if (!fixtures || !moduleRoot || !output) throw new Error('Usage: node admin-ui-030.mjs fixture-directory jsdom-module-root output.json');
const require = createRequire(pathToFileURL(path.join(moduleRoot, 'package.json')));
/** @type {typeof import('jsdom')} */
const { JSDOM } = require('jsdom');
const code = await readFile(path.join(fixtures, 'admin.js'), 'utf8');
/** @type {Case[]} */
const cases = [];
/** @param {string} name @param {unknown} passed */
function check(name, passed) { assert.ok(passed, name); cases.push({ name, pass: true }); }
/** @param {ParentNode} node @param {string} selector @returns {HTMLElement} */
function element(node, selector) {
 const found = node.querySelector(selector);
 if (!found) throw new Error(`Expected ${selector}`);
 return /** @type {HTMLElement} */ (/** @type {unknown} */ (found));
}
/** @param {ParentNode} node @param {string} selector @returns {HTMLInputElement} */
function input(node, selector) {
 const found = node.querySelector(selector);
 if (!found || found.tagName !== 'INPUT') throw new Error(`Expected input ${selector}`);
 return /** @type {HTMLInputElement} */ (/** @type {unknown} */ (found));
}
const dom = new JSDOM(await readFile(path.join(fixtures, 'connection.html'), 'utf8'), { runScripts: 'outside-only', url: 'https://fixture.invalid/connection.html' });
const w = /** @type {Window & typeof globalThis} */ (/** @type {unknown} */ (dom.window));
w.fgConnection = { ajaxUrl: 'https://fixture.invalid/admin-ajax.php', nonce: 'fixture' };
w.fetch = async () => new Response(JSON.stringify({ success: true, data: { checks: [{ label: 'OAuth enabled', status: 'pass', detail: 'Fixture details' }] } }));
w.eval(code);
check('Copy URL enhancement is initialized', !element(w.document, '[data-copy-target]').hidden);
const access = new JSDOM(await readFile(path.join(fixtures, 'access.html'), 'utf8'), { runScripts: 'outside-only' });
const a = /** @type {Window & typeof globalThis} */ (/** @type {unknown} */ (access.window));
a.eval(code);
for (const box of /** @type {NodeListOf<HTMLInputElement>} */ (a.document.querySelectorAll('input[type="checkbox"]'))) {
 check(`${box.name} has visible linked help text`, !!a.document.getElementById(box.getAttribute('aria-describedby') || '')?.textContent.trim());
}
check('Only the two intended Access Controls checkboxes are rendered', a.document.querySelectorAll('input[type="checkbox"]').length === 2);
const modes = [.../** @type {NodeListOf<HTMLInputElement>} */ (a.document.querySelectorAll('input[type="radio"][name="change_mode"]'))];
check('Three change modes are rendered with permanent help', modes.length === 3 && modes.every(mode => !!a.document.getElementById(mode.getAttribute('aria-describedby') || '')?.textContent.trim()));
const yolo = input(a.document, '#fg-mode-yolo');
yolo.click();
check('Selecting YOLO selects only one native radio option', modes.filter(mode => mode.checked).length === 1 && yolo.checked);
const modeForm = yolo.form;
if (!modeForm) throw new Error('Expected YOLO form');
check('YOLO selection is submitted as an explicit mode', new a.FormData(modeForm).get('change_mode') === 'yolo' && !new a.FormData(modeForm).has('writes'));
const form = input(w.document, 'form input[value="fg_disable_connection"]').form;
if (!form) throw new Error('Expected disable form');
const first = new w.Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(first);
const second = new w.Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(second);
check('Repeated immediate-action submission is prevented', !first.defaultPrevented && second.defaultPrevented);
await new Promise(resolve => w.setTimeout(resolve, 1));
check('Submitted immediate action is visibly busy and disabled', form.getAttribute('aria-busy') === 'true' && element(form, 'button').hasAttribute('disabled'));
element(w.document, '#fg-check-form').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
await new Promise(resolve => w.setTimeout(resolve, 1));
check('Diagnostic response uses Title Case without changing protocol labels', w.document.querySelector('#fg-check-results strong')?.textContent === 'OAuth Enabled');
check('Diagnostic completion re-enables the button', !element(w.document, '#fg-check-form button').hasAttribute('disabled') && element(w.document, '#fg-check-form button').textContent === 'Check Connection');
const withoutChecks = new JSDOM('<form class="fg-immediate-action"><button type="submit">Save</button></form>', { runScripts: 'outside-only' });
const withoutWindow = /** @type {Window & typeof globalThis} */ (/** @type {unknown} */ (withoutChecks.window));
withoutWindow.eval(code);
const f = element(withoutWindow.document, 'form'); f.dispatchEvent(new withoutWindow.Event('submit', { cancelable: true }));
check('Non-connection pages initialize actions without the diagnostics form', f.dataset.submitting === 'true');
dom.window.close(); access.window.close(); withoutChecks.window.close();
await writeFile(output, JSON.stringify({ test: 'PHP-rendered admin DOM', browser_layout_test: false, passed: cases.length, total: cases.length, cases }, null, 2));
console.log(JSON.stringify({ passed: cases.length, total: cases.length, browser_layout_test: false }));
