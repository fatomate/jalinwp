// Submission-state checks against HTML rendered by the real disposable WP integration suite.
// jsdom executes the shipped script; this is not browser/layout coverage.
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./design-js-runtime/node_modules/jsdom');
const cases = [];
function check(name, pass) {
  cases.push({ name, pass: !!pass });
  if (!pass) throw new Error(name);
}
function submit(window, form) {
  const event = new window.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(event);
  return event;
}
function cleanupForm(document, all) {
  return [...document.querySelectorAll('form')].find(form =>
    form.querySelector('[name="action"]')?.value === 'fg_clear_connections' &&
    !!form.querySelector('[name="clear_all"]') === all);
}
async function run() {
  const html = fs.readFileSync(path.join(__dirname, 'admin-033-fixtures/connections.html'), 'utf8');
  const script = fs.readFileSync(path.join(__dirname, '../fames-mcp-gateway/assets/admin.js'), 'utf8');
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://gateway.example.test/wp-admin/options-general.php' });
  const { window } = dom;
  const document = window.document;
  let confirms = 0;
  window.confirm = () => { confirms += 1; return false; };
  window.eval(script);
  const all = cleanupForm(document, true);
  const inactive = cleanupForm(document, false);
  check('Real Connections markup exposes distinct all/inactive cleanup actions', !!all && !!inactive && all.hasAttribute('data-fg-confirm') && !inactive.hasAttribute('data-fg-confirm'));
  const cancelled = submit(window, all);
  await new Promise(resolve => setTimeout(resolve, 10));
  check('Cancel keeps the destructive form idle and enabled', cancelled.defaultPrevented && confirms === 1 && all.dataset.submitting !== 'true' && !all.hasAttribute('aria-busy') && !all.querySelector('button[type="submit"]').disabled);
  window.confirm = () => { confirms += 1; return true; };
  const accepted = submit(window, all);
  check('Confirmed clear-all enters submitting state', !accepted.defaultPrevented && confirms === 2 && all.dataset.submitting === 'true' && all.getAttribute('aria-busy') === 'true');
  const duplicate = submit(window, all);
  check('A second submit is blocked without prompting again', duplicate.defaultPrevented && confirms === 2);
  await new Promise(resolve => setTimeout(resolve, 10));
  check('Confirmed form disables the submit button while retaining nonce and cleanup flag', all.querySelector('button[type="submit"]').disabled && all.querySelector('[name="_wpnonce"]').value !== '' && !all.querySelector('[name="_wpnonce"]').disabled && all.querySelector('[name="clear_all"]').value === '1');
  const plain = submit(window, inactive);
  await new Promise(resolve => setTimeout(resolve, 10));
  check('Inactive cleanup submits without another confirmation and blocks repeat clicks', !plain.defaultPrevented && confirms === 2 && inactive.dataset.submitting === 'true' && inactive.querySelector('button[type="submit"]').disabled && submit(window, inactive).defaultPrevented);
  const deletion = [...document.querySelectorAll('form')].find(form => form.querySelector('[name="action"]')?.value === 'fg_delete_connection');
  check('Inactive Delete form remains a targeted nonce-protected action', !!deletion && deletion.querySelector('[name="grant_id"]').value !== '' && deletion.querySelector('[name="_wpnonce"]').value !== '' && !deletion.querySelector('[name="clear_all"]'));
  window.close();
  const accessDom = new JSDOM(fs.readFileSync(path.join(__dirname, 'admin-033-fixtures/access.html'), 'utf8'), { runScripts: 'outside-only', url: 'https://gateway.example.test/wp-admin/options-general.php' });
  accessDom.window.confirm = () => { throw new Error('Saving access must not prompt for OAuth cleanup.'); };
  accessDom.window.eval(script);
  const access = accessDom.window.document.querySelector('.fg-settings-form');
  const saved = submit(accessDom.window, access);
  check('Access tab script works without connection-check elements', !saved.defaultPrevented && access.dataset.submitting === 'true' && submit(accessDom.window, access).defaultPrevented);
  accessDom.window.close();
}
run().catch(error => { cases.push({name:error.message,pass:false}); }).finally(() => {
  const output = {passed:cases.filter(test => test.pass).length,total:cases.length,engine:'jsdom; real WP-rendered markup; no browser or HTTP',cases};
  fs.writeFileSync(path.join(__dirname, 'admin-033-dom-output.json'), JSON.stringify(output,null,2));
  console.log(JSON.stringify(output,null,2));
  process.exitCode = output.passed === output.total ? 0 : 1;
});
