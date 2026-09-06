"""Build a local static fixture using the installed WordPress editor packages."""
from pathlib import Path
import json, re, shutil, html

root = Path(__file__).resolve().parent
out = root / 'ui-preview'
out.mkdir(exist_ok=True)
wp = root / 'fixtures/wordpress/wp-includes'
deps = {f'wp-{name}': re.findall(r"'([^']+)'", args) for name, args in re.findall(r"'([^']+)\.js' => array\('dependencies' => array\((.*?)\), 'version'", (wp / 'assets/script-loader-packages.php').read_text())}
loaded = {'wp-polyfill'}
paths = []
def load(name):
    if name in loaded: return
    loaded.add(name)
    for dep in deps.get(name, []): load(dep)
    if name in ['react', 'react-dom', 'react-jsx-runtime', 'moment']:
        if name in ['react-dom', 'react-jsx-runtime']: load('react')
        source = wp / f'js/dist/vendor/{name}.js'
    else: source = wp / f'js/dist/{name[3:]}.js'
    dest = out / f'editor/{name}.js'
    dest.parent.mkdir(exist_ok=True)
    shutil.copyfile(source, dest)
    paths.append('editor/' + dest.name)
load('wp-block-library')
for name in ['design-validation.js', 'design-review.js']:
    shutil.copyfile(root.parent / 'fames-mcp-gateway/assets' / name, out / name)
data = json.loads((root / 'design-blocks-fixtures.json').read_text())
cards = []
for fixture in data['fixtures']:
    source = html.escape(fixture['markup'])
    cards.append(f'<article data-fg-change-card><h2>{html.escape(fixture["name"])}</h2><div data-fg-design-review data-content-digest="fixture-digest"><textarea hidden data-fg-design-source>{source}</textarea><p data-fg-design-status role="status">Waiting</p></div><form><input type="hidden" name="design_validation"><button data-fg-approve type="button">Approve</button></form></article>')
cards.append('<article data-fg-change-card><h2>Invalid Design Must Stay Blocked</h2><div data-fg-design-review data-content-digest="invalid"><textarea hidden data-fg-design-source>&lt;!-- wp:heading --&gt;&lt;h1&gt;Wrong&lt;/h1&gt;&lt;!-- /wp:heading --&gt;</textarea><p data-fg-design-status role="status">Waiting</p></div><form><input type="hidden" name="design_validation"><button data-fg-approve type="button">Approve</button></form></article>')
scripts = ''.join(f'<script src="{path}"></script>' for path in paths + ['design-validation.js', 'design-review.js'])
(out / 'design-validation.html').write_text('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fames Design Browser Validation</title><style>body{font:16px/1.5 system-ui;background:#f0f2f5;margin:24px auto;max-width:1000px;color:#172033}article{padding:16px;margin:12px;background:white;border:1px solid #ccd3df;border-radius:8px}h2{font-size:18px}button{padding:8px 18px}</style></head><body><h1>Gutenberg Review Validation</h1><p>Local fixture: actual WordPress editor packages and plugin approval UI script.</p>' + ''.join(cards) + scripts + '</body></html>')
print(json.dumps({'html':str(out/'design-validation.html'), 'fixture_count':len(cards), 'editor_assets':len(paths)}))
