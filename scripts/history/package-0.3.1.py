from pathlib import Path
import hashlib, json, re, zipfile, posixpath
base=Path(__file__).resolve().parent
plugin=base/'jalin-mcp-gateway'; runtime=base/'test-runtime'; release=base/'release'
release.mkdir(exist_ok=True)
version='0.3.1'; prefix='jalin-mcp-gateway-source/'
def sha(data): return hashlib.sha256(data).hexdigest()
source_data={}
for path in sorted(plugin.rglob('*')):
 if path.is_file() and not any(x.startswith('.') for x in path.relative_to(plugin).parts):
  source_data['jalin-mcp-gateway/'+path.relative_to(plugin).as_posix()]=path.read_bytes()
install_data={n:d for n,d in source_data.items() if '/tests/' not in n and n!='jalin-mcp-gateway/bridge/test.mjs'}
assert b'Version: 0.3.1' in install_data['jalin-mcp-gateway/jalin-mcp-gateway.php']
modules=re.search(r"foreach \(\[(.*?)\] as \$fg_module\)",install_data['jalin-mcp-gateway/jalin-mcp-gateway.php'].decode()).group(1)
for module in re.findall(r"'([^']+)'",modules): assert 'jalin-mcp-gateway/includes/class-'+module+'.php' in install_data,module
for name in ['class-settings.php','class-page-design.php','class-design-blocks.php','class-finance-admin.php']:
 assert 'jalin-mcp-gateway/includes/'+name in install_data
assert all('/tests/' not in n for n in install_data)
install=release/f'jalin-mcp-gateway-{version}.zip'
with zipfile.ZipFile(install,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for name,data in sorted(install_data.items()): z.writestr(name,data)

runtime_files=['package.json','package-lock.json','DEVELOPER-TESTS.md','RUNTIME.md','setup-fixture.mjs','run-wp-tests.mjs','runtime-smoke.php','core-runtime-smoke.php','wp-tools-tests.php','wc-tools-tests.php','analytics-tests.php','analytics-hpos-tests.php','security-tests.php','oauth-tests.php','oauth-connections-audit-tests.php','setup-030-tests.php','finance-admin-tests.php','finance-admin-hpos-tests.php','finance-inactive-tests.php','page-design-tests.php','page-design-approvals-tests.php','design-blocks-tests.php','design-editor-fixture.cjs','design-blocks-canonical-tests.cjs','design-review-dom-tests.cjs','build-design-browser-preview.py','connection-tests.php','discovery-publisher-tests.php','connection-trace-tests.php','oauth-http-smoke.mjs','admin-connection-tests.php','design-blocks-fixtures.json','design-js-runtime/package.json','design-js-runtime/package-lock.json','native-030/run.php','native-030/tests.php','native-030/README.md','setup-yolo-tests.php','yolo-oauth-tests.php','yolo-execution-tests.php','yolo-metadata-tests.php']
for name in runtime_files: source_data['test-runtime/'+name]=(runtime/name).read_bytes()
index=json.loads((runtime/'release-0.3.1-evidence-index.json').read_text())
for item in index['executed']:
 assert sha((runtime/item['file']).read_bytes())==item['sha256'],item['file']
 source_data['test-runtime/evidence/'+item['file']]=(runtime/item['file']).read_bytes()
source_data['test-runtime/evidence/release-0.3.1-evidence-index.json']=(runtime/'release-0.3.1-evidence-index.json').read_bytes()
guide=release/f'JalinWP-YOLO-Handoff-v{version}.md'
source_data[guide.name]=guide.read_bytes()
source_data['JalinWP-Developer-Handoff-v0.3.0.md']=(release/'JalinWP-Developer-Handoff-v0.3.0.md').read_bytes()
source_data['JalinWP-Improvement-Plan.md']=(release/'JalinWP-Improvement-Plan.md').read_bytes()
source_data['package-0.3.1.py']=Path(__file__).read_bytes()
source_data['build-validation-031.py']=(base/'build-validation-031.py').read_bytes()
source_data['START-HERE.md']=f'''# JalinWP {version} Complete Source

Use `jalin-mcp-gateway-{version}.zip` for staging installation. This source archive is the full development kit and is not an installable plugin ZIP.

Read `{guide.name}` for the current YOLO update, setup steps, response contract, source map, migration and actual tests. The included 0.3.0 developer handoff describes the previous nine improvements; it is historical when it conflicts with the current handoff. Current docs are under `jalin-mcp-gateway/docs/`, including `YOLO-MODE.md`, `PAGE-DESIGN.md` and `VALIDATION.md`.

Keep `jalin-mcp-gateway/` and `test-runtime/` side by side. Follow `test-runtime/DEVELOPER-TESTS.md` for pinned dependencies and disposable fixtures. Evidence under `test-runtime/evidence/` contains only the checks indexed for this 0.3.1 build; older tests remain in the source but were not all rerun. Native MySQL/MariaDB, actual clients/staging, browser/editor and theme/version matrix gates remain pending.

YOLO is opt-in. Upgrading preserves the prior Read Only or Reviewed Changes behavior. Enable YOLO and reconnect each OAuth client once to approve direct changes. Supported writes then run in their original tool call without dashboard review. Native permissions, data controls, validation and audit remain. No live deployment, host edit or GitHub push occurred.

Dependencies, downloaded WordPress/WooCommerce, databases, transient runs and credentials are excluded. `SOURCE-MANIFEST.json` records SHA-256 for every member except itself, plus the separate install ZIP. To rerun packaging, place the included current handoff, previous handoff and improvement plan in `release/`, copy evidence files beside the runtime scripts, and run `python3 package-0.3.1.py` after completing new validation. `build-validation-031.py` rebuilds the validation document from successful current evidence.
'''.encode()
manifest={'plugin':'JalinWP','version':version,'date':'2026-09-05','install_zip':install.name,'install_zip_sha256':sha(install.read_bytes()),'files':{n:sha(d) for n,d in sorted(source_data.items())},'pending_gates':index['not_executed']}
source_data['SOURCE-MANIFEST.json']=(json.dumps(manifest,indent=2)+'\n').encode()
source=release/f'jalin-mcp-gateway-{version}-source.zip'
with zipfile.ZipFile(source,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for name,data in sorted(source_data.items()): z.writestr(prefix+name,data)
for path in [install,source]:
 with zipfile.ZipFile(path) as z: assert z.testzip() is None
with zipfile.ZipFile(source) as z:
 for n,d in install_data.items(): assert z.read(prefix+n)==d
 for n,d in manifest['files'].items(): assert sha(z.read(prefix+n))==d
 assert not any(any(p in {'node_modules','fixtures','runs','.git'} for p in n.split('/')) for n in z.namelist())
issues=[]
for name,data in source_data.items():
 if name.endswith('.md'):
  s=data.decode();assert len(re.findall(r'^```',s,re.M))%2==0,name
  for label,target in re.findall(r'\[([^\]]+)\]\(([^)]+)\)',s):
   if '://' in target or target.startswith('#'): continue
   resolved=posixpath.normpath(posixpath.join(posixpath.dirname(name),target.split('#')[0]))
   if resolved not in source_data: issues.append((name,target))
assert not issues,issues
result={'status':'passed','install_files':len(install_data),'source_files':len(source_data),'artifacts':[{'name':p.name,'bytes':p.stat().st_size,'sha256':sha(p.read_bytes())} for p in [install,source,guide]]}
(release/'packaging-0.3.1-result.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
