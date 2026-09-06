// Submission-state checks against HTML rendered by the real disposable WP integration suite.
// jsdom executes the shipped script; this is not browser/layout coverage.
const fs = require('node:fs');
const path = require('node:path');
/** @type {typeof import('jsdom')} */
const jsdom = require('./design-js-runtime/node_modules/' + 'jsdom');
const { JSDOM } = jsdom;
/** @typedef {{ name: string, pass: boolean }} Case */
/** @type {Case[]} */
const cases = [];
/** @param {string} name @param {unknown} pass */
function check(name, pass) {
  cases.push({ name, pass: !!pass });
  if (!pass) throw new Error(name);
}
/** @param {import('jsdom').DOMWindow} window @param {HTMLFormElement} form */
function submit(window, form) {
  const event = new window.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(event);
  return event;
}
/** @param {Document} document @param {boolean} all @returns {HTMLFormElement | undefined} */
function cleanupForm(document, all) {
  return [...document.querySelectorAll('form')].find(form =>
    /** @type {HTMLInputElement | null} */ (form.querySelector('input[name="action"]'))?.value === 'fg_clear_connections' &&
    !!form.querySelector('input[name="clear_all"]') === all);
}
/** @param {HTMLFormElement} form */
function submitButton(form) {
  const button = form.querySelector('button[type="submit"]');
  if (!button || button.tagName !== 'BUTTON') throw new Error('Expected submit button');
  return /** @type {HTMLButtonElement} */ (/** @type {unknown} */ (button));
}
/** @param {HTMLFormElement} form @param {string} selector */
function formInput(form, selector) {
  const input = form.querySelector(selector);
  if (!input || input.tagName !== 'INPUT') throw new Error(`Expected input ${selector}`);
  return /** @type {HTMLInputElement} */ (/** @type {unknown} */ (input));
}
async function run() {
  const html = fs.readFileSync(path.join(__dirname, 'admin-033-fixtures/connections.html'), 'utf8');
  const script = fs.readFileSync(path.join(__dirname, '../../jalin-mcp-gateway/assets/admin.js'), 'utf8');
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://gateway.example.test/wp-admin/options-general.php' });
  const { window } = dom;
  const document = window.document;
  let confirms = 0;
  window.confirm = () => { confirms += 1; return false; };
  window.eval(script);
  const all = cleanupForm(document, true);
  const inactive = cleanupForm(document, false);
  if (!all || !inactive) throw new Error('Expected cleanup forms');
  check('Real Connections markup exposes distinct all/inactive cleanup actions', all.hasAttribute('data-fg-confirm') && !inactive.hasAttribute('data-fg-confirm'));
  const cancelled = submit(window, all);
  await new Promise(resolve => setTimeout(resolve, 10));
  check('Cancel keeps the destructive form idle and enabled', cancelled.defaultPrevented && confirms === 1 && all.dataset.submitting !== 'true' && !all.hasAttribute('aria-busy') && !submitButton(all).disabled);
  window.confirm = () => { confirms += 1; return true; };
  const accepted = submit(window, all);
  check('Confirmed clear-all enters submitting state', !accepted.defaultPrevented && confirms === 2 && all.dataset.submitting === 'true' && all.getAttribute('aria-busy') === 'true');
  const duplicate = submit(window, all);
  check('A second submit is blocked without prompting again', duplicate.defaultPrevented && confirms === 2);
  await new Promise(resolve => setTimeout(resolve, 10));
  check('Confirmed form disables the submit button while retaining nonce and cleanup flag', submitButton(all).disabled && formInput(all, '[name="_wpnonce"]').value !== '' && !formInput(all, '[name="_wpnonce"]').disabled && formInput(all, '[name="clear_all"]').value === '1');
  const plain = submit(window, inactive);
  await new Promise(resolve => setTimeout(resolve, 10));
  check('Inactive cleanup submits without another confirmation and blocks repeat clicks', !plain.defaultPrevented && confirms === 2 && inactive.dataset.submitting === 'true' && submitButton(inactive).disabled && submit(window, inactive).defaultPrevented);
  const deletion = [...document.querySelectorAll('form')].find(form => /** @type {HTMLInputElement | null} */ (form.querySelector('input[name="action"]'))?.value === 'fg_delete_connection');
  if (!deletion) throw new Error('Expected deletion form');
  check('Inactive Delete form remains a targeted nonce-protected action', formInput(deletion, '[name="grant_id"]').value !== '' && formInput(deletion, '[name="_wpnonce"]').value !== '' && !deletion.querySelector('[name="clear_all"]'));
  window.close();
  const accessDom = new JSDOM(fs.readFileSync(path.join(__dirname, 'admin-033-fixtures/access.html'), 'utf8'), { runScripts: 'outside-only', url: 'https://gateway.example.test/wp-admin/options-general.php' });
  accessDom.window.confirm = () => { throw new Error('Saving access must not prompt for OAuth cleanup.'); };
  accessDom.window.eval(script);
  const access = accessDom.window.document.querySelector('form.fg-settings-form');
  if (!access || access.tagName !== 'FORM') throw new Error('Expected settings form');
  const accessForm = /** @type {HTMLFormElement} */ (/** @type {unknown} */ (access));
  const saved = submit(accessDom.window, accessForm);
  check('Access tab script works without connection-check elements', !saved.defaultPrevented && accessForm.dataset.submitting === 'true' && submit(accessDom.window, accessForm).defaultPrevented);
  accessDom.window.close();
}
run().catch(/** @param {unknown} error */ error => { cases.push({name:error instanceof Error ? error.message : String(error),pass:false}); }).finally(() => {
  const output = {passed:cases.filter(test => test.pass).length,total:cases.length,engine:'jsdom; real WP-rendered markup; no browser or HTTP',cases};
  fs.writeFileSync(path.join(__dirname, 'admin-033-dom-output.json'), JSON.stringify(output,null,2));
  console.log(JSON.stringify(output,null,2));
  process.exitCode = output.passed === output.total ? 0 : 1;
});
