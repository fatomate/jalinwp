import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(root, '../../jalin-mcp-gateway');
const requested = process.argv[2];
if (!requested) throw new Error('Usage: node run-wp-tests.mjs test-file.php');
const testPath = path.resolve(root, requested);
if (!testPath.startsWith(root + path.sep)) throw new Error('Test must be inside test-runtime');
await fs.access(testPath);
const testRelative = path.relative(root, testPath).split(path.sep).join('/');
const slug = path.basename(testPath, '.php').replace(/[^a-zA-Z0-9_-]/g, '-');
await fs.mkdir(path.join(root, 'runs'), { recursive: true });
const runtime = await fs.mkdtemp(path.join(root, 'runs', `${slug}-`));
const wordpress = path.join(runtime, 'wordpress');
await fs.cp(path.join(root, 'fixtures', 'wordpress'), wordpress, { recursive: true });
// Never mount the shared result directory read/write into concurrent PHP instances.
// Playground can flush an older mounted JSON result after a newer run has finished.
const resultMount = path.join(runtime, 'test-results');
await fs.mkdir(resultMount);
for (const item of await fs.readdir(root, { withFileTypes: true })) {
 if (item.isFile() && /\.(php|json)$/.test(item.name) && !item.name.endsWith('-output.json')) {
  await fs.copyFile(path.join(root,item.name),path.join(resultMount,item.name));
 }
}
await fs.mkdir(path.dirname(path.join(resultMount,testRelative)),{recursive:true});
await fs.copyFile(testPath,path.join(resultMount,testRelative));
await fs.cp(path.join(root, 'cases'), path.join(resultMount, 'cases'), { recursive: true });
const initial = new Map();
/** @param {string} dir @returns {Promise<string[]>} */
async function filesUnder(dir) {
 /** @type {string[]} */
 const all=[];
 for(const item of await fs.readdir(dir,{withFileTypes:true})) {
  const file=path.join(dir,item.name);
  if(item.isDirectory()) all.push(...await filesUnder(file));
  else if(item.isFile()) all.push(file);
 }
 return all;
}
for(const file of await filesUnder(resultMount)) initial.set(path.relative(resultMount,file),await fs.readFile(file));
const resultPath = path.join(root, `${slug}-output.json`);
await fs.rm(resultPath, { force: true });
const phpCode = `<?php
ob_start();
$test_result = ['test' => ${JSON.stringify(testRelative)}, 'status' => 'running'];
register_shutdown_function(function () use (&$test_result) {
 $error = error_get_last();
 if ($error && in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
  $test_result['status']='failed'; $test_result['fatal']=$error;
 }
 $test_result['output']=ob_get_contents();
 file_put_contents('/test-results/${slug}-output.json', json_encode($test_result, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
});
try {
 require '/test-results/${testRelative}';
 $test_result['status']='passed';
} catch (Throwable $e) {
 $test_result['status']='failed';
 $test_result['exception']=get_class($e) . ': ' . $e->getMessage();
 $test_result['trace']=$e->getTraceAsString();
}
`;
const blueprint = path.join(runtime, 'blueprint.json');
await fs.writeFile(blueprint, JSON.stringify({ preferredVersions: {php:'8.3',wp:'6.8.8'}, steps:[{step:'runPHP', code:phpCode}] }));
const args = [path.join(root,'node_modules/@wp-playground/cli/wp-playground.js'),'run-blueprint',
 '--wordpress-install-mode=install-from-existing-files-if-needed','--wp=6.8.8','--php=8.3',`--blueprint=${blueprint}`,
 `--mount-dir-before-install=${wordpress}`,'/wordpress',
 `--mount-dir=${plugin}`,'/wordpress/wp-content/plugins/jalin-mcp-gateway',
 `--mount-dir=${resultMount}`,'/test-results','--verbosity=quiet'];
const code = await new Promise((resolve,reject)=>{const proc=spawn(process.execPath,args,{cwd:root,stdio:'inherit'});proc.on('error',reject);proc.on('exit',resolve);});
for(const file of await filesUnder(resultMount)) {
 const relative=path.relative(resultMount,file), data=await fs.readFile(file);
 // Only generated/changed artifacts return to the source kit. Input scripts stay unchanged.
 if(relative.endsWith('.php') || initial.get(relative)?.equals(data)) continue;
 const destination=path.join(root,relative);
 await fs.mkdir(path.dirname(destination),{recursive:true});
 await fs.writeFile(destination,data);
}
let success=false;
try {
 const result=JSON.parse(await fs.readFile(resultPath,'utf8'));
 success=result.status==='passed';
 try {
  const summary=JSON.parse(result.output);
  if (typeof summary.passed==='number' && typeof summary.total==='number' && summary.passed < summary.total) success=false;
  if (Array.isArray(summary.cases) && summary.cases.some(/** @param {unknown} c */ c => typeof c === 'object' && c !== null && 'pass' in c && c.pass === false)) success=false;
 } catch { /* Non-JSON stdout is valid; thrown failures still fail the wrapper. */ }
 if (!success && result.status==='passed') result.status='failed_assertions';
 console.log(JSON.stringify(result,null,2));
}
catch(error){console.error(`No complete test result: ${error instanceof Error ? error.message : String(error)}`);}
// Playground may finish flushing a mounted directory just after process exit.
await fs.rm(runtime,{recursive:true,force:true,maxRetries:3,retryDelay:100});
process.exit(code===0 && success ? 0 : 1);
