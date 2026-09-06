import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const cli = path.join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const fixtures = path.join(root, 'fixtures');
const destination = path.join(fixtures, 'wordpress');
await fs.access(cli).catch(() => { throw new Error('Run npm ci in test-runtime before setting up the fixture.'); });
try {
 const entries = await fs.readdir(destination);
 if (entries.length) throw new Error('fixtures/wordpress already contains a fixture. Setup leaves it unchanged. To rebuild, move that directory aside first.');
} catch (error) {
 if (error.code !== 'ENOENT') throw error;
}
await fs.mkdir(fixtures, { recursive: true });
const staging = await fs.mkdtemp(path.join(fixtures, 'setup-'));
const wordpress = path.join(staging, 'wordpress');
await fs.mkdir(wordpress, { recursive: true });

async function runBlueprint(name, mode) {
 const args = [cli, 'run-blueprint', '--wp=6.8.8', '--php=8.3',
  `--wordpress-install-mode=${mode}`, `--blueprint=${path.join(root, name)}`,
  `--mount-dir-before-install=${wordpress}`, '/wordpress',
  `--mount-dir=${root}`, '/test-results', '--verbosity=normal'];
 const code = await new Promise((resolve, reject) => {
  const child = spawn(process.execPath, args, { cwd: root, stdio: 'inherit' });
  child.on('error', reject);
  child.on('exit', resolve);
 });
 if (code !== 0) throw new Error(`${name} failed (exit ${code}).`);
}

let installed = false;
try {
 await fs.rm(path.join(root, 'baseline-result.json'), { force: true });
 await fs.rm(path.join(root, 'woo-fixture-result.json'), { force: true });
 await runBlueprint('baseline-blueprint.json', 'download-and-install');
 const baseline = JSON.parse(await fs.readFile(path.join(root, 'baseline-result.json'), 'utf8'));
 if (baseline.wordpress !== '6.8.8' || !baseline.php.startsWith('8.3.') || baseline.db !== 'WP_SQLite_DB') {
  throw new Error(`Unexpected fixture versions or database: ${JSON.stringify(baseline)}`);
 }
 await runBlueprint('woo-fixture-blueprint.json', 'install-from-existing-files-if-needed');
 const woo = JSON.parse(await fs.readFile(path.join(root, 'woo-fixture-result.json'), 'utf8'));
 if (!woo.exists) throw new Error('WooCommerce fixture was not installed.');
 const pluginHeader = await fs.readFile(path.join(wordpress, 'wp-content', 'plugins', 'woocommerce', 'woocommerce.php'), 'utf8');
 if (!/Version:\s*10\.2\.2\s/.test(pluginHeader)) throw new Error('Unexpected WooCommerce fixture version.');
 // Setup never replaces an existing populated fixture, including one created concurrently.
 try { await fs.rmdir(destination); } catch (error) { if (error.code !== 'ENOENT') throw error; }
 await fs.rename(wordpress, destination);
 installed = true;
 console.log('Fixture ready: WordPress 6.8.8, PHP 8.3, SQLite, WooCommerce 10.2.2 (inactive).');
 console.log('Run: node run-wp-tests.mjs wp-tools-tests.php');
} finally {
 await fs.rm(staging, { recursive: true, force: true });
 if (!installed) console.error('Fixture setup did not complete. Existing fixtures were not replaced.');
}
