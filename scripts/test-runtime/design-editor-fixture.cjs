'use strict';
// Evaluate the local WordPress editor packages inside a DOM test fixture; no browser or network.
const fs = require('node:fs');
const path = require('node:path');
/** @type {typeof import('jsdom')} */
const jsdom = require('./design-js-runtime/node_modules/' + 'jsdom');
const { JSDOM } = jsdom;
const wpRoot = path.join(__dirname, 'fixtures/wordpress/wp-includes');
const scripts = fs.readFileSync(path.join(wpRoot, 'assets/script-loader-packages.php'), 'utf8');
/** @type {Map<string, string[]>} */
const deps = new Map();
for (const match of scripts.matchAll(/'([^']+)\.js' => array\('dependencies' => array\((.*?)\), 'version'/g)) {
    deps.set(`wp-${match[1]}`, Array.from(match[2].matchAll(/'([^']+)'/g), match => match[1]));
}
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://fixture.invalid/wp-admin/', runScripts: 'outside-only', pretendToBeVisual: true });
const win = dom.window;
win.TextEncoder = TextEncoder; win.TextDecoder = TextDecoder;
win.matchMedia = /** @param {string} query */ query => /** @type {MediaQueryList} */ (/** @type {unknown} */ ({ matches: false, media: query, onchange: null, addListener(){}, removeListener(){}, addEventListener(){}, removeEventListener(){}, dispatchEvent(){ return true; } }));
win.ResizeObserver = class { observe(){} unobserve(){} disconnect(){} };
win.IntersectionObserver = class { observe(){} unobserve(){} disconnect(){} };
win.requestIdleCallback = /** @param {IdleRequestCallback} callback */ callback => win.setTimeout(() => callback({didTimeout:false,timeRemaining:()=>50}), 0);
win.cancelIdleCallback = /** @param {number} id */ id => win.clearTimeout(id);
win.fetch = () => Promise.reject(new Error('Network is disabled in the design fixture'));
const loaded = new Set(['wp-polyfill']);
/** @param {string} name */
function load(name) {
    if (loaded.has(name)) return;
    loaded.add(name);
    for (const dependency of deps.get(name) || []) load(dependency);
    let file;
    if (['react','react-dom','react-jsx-runtime','moment'].includes(name)) {
        if (name==='react-dom' || name==='react-jsx-runtime') load('react');
        file = path.join(wpRoot, 'js/dist/vendor', `${name}.js`);
    } else { file = path.join(wpRoot, 'js/dist', `${name.slice(3)}.js`); }
    win.eval(fs.readFileSync(file, 'utf8') + `\n//# sourceURL=${name}.js`);
}
load('wp-block-library');
if (!win.wp) throw new Error('WordPress block library did not load');
win.wp.blockLibrary.registerCoreBlocks();
module.exports = { window: win, wp: win.wp, close: () => win.close() };
