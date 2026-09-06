// DOM unit check of the review script with real WordPress editor packages. No browser or HTTP.
const fs = require('node:fs');
const f = require('./design-editor-fixture.cjs');
const data = JSON.parse(fs.readFileSync(__dirname + '/design-blocks-fixtures.json', 'utf8'));
const cases = [];
function card(name, markup) {
    const node = f.window.document.createElement('article');
    node.dataset.fgChangeCard = '';
    node.innerHTML = '<div data-fg-design-review data-content-digest="digest"><textarea data-fg-design-source hidden></textarea><p data-fg-design-status></p></div><input name="design_validation"><button data-fg-approve>Approve</button>';
    node.querySelector('textarea').value = markup;
    node.dataset.caseName = name;
    f.window.document.body.appendChild(node);
    return node;
}
const valid = data.fixtures.map(x => card(x.name, x.markup));
const invalid = card('Invalid Markup', '<!-- wp:heading --><h1>Wrong</h1><!-- /wp:heading -->');
f.window.console.error = () => {};
f.window.console.warn = () => {};
f.window.eval(fs.readFileSync(__dirname + '/../fames-mcp-gateway/assets/design-validation.js', 'utf8'));
f.window.eval(fs.readFileSync(__dirname + '/../fames-mcp-gateway/assets/design-review.js', 'utf8'));
f.window.document.dispatchEvent(new f.window.Event('DOMContentLoaded'));
for (const node of valid) cases.push({name:node.dataset.caseName + ' approval enabled only with matching digest',pass:!node.querySelector('button').disabled && node.querySelector('input').value === 'digest' && node.querySelector('p').textContent.startsWith('Gutenberg Validation Passed')});
cases.push({name:'Invalid markup keeps approval blocked and digest empty',pass:invalid.querySelector('button').disabled && invalid.querySelector('input').value === '' && invalid.querySelector('p').textContent.startsWith('Gutenberg Validation Failed')});
const output = {passed:cases.filter(x=>x.pass).length,total:cases.length,engine:'jsdom with WordPress 6.8.8 packages; not a browser',cases};
fs.writeFileSync(__dirname + '/design-review-dom-output.json', JSON.stringify(output,null,2));
console.log(JSON.stringify({passed:output.passed,total:output.total}));
f.close(); process.exitCode=output.passed === output.total ? 0 : 1;
