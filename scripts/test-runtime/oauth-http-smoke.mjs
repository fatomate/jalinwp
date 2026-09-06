// Disposable full HTTP lifecycle test. Never points at a user site or a real OAuth client.
import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { randomBytes, createHash } from 'node:crypto';
import { discoverAuthorizationServerMetadata, discoverOAuthServerInfo } from '@modelcontextprotocol/sdk/client/auth.js';

const root = path.dirname(fileURLToPath(import.meta.url));
const runtime = await fs.mkdtemp(path.join(root, 'oauth-http-disposable-'));
const site = 'https://gateway.example';
const issuer = `${site}/wp-json/jalin-mcp/v1/oauth/issuer.json`;
const resource = `${site}/wp-json/jalin-mcp/v1/mcp`;
const mode = process.argv.find(arg => arg.startsWith('--mode='))?.slice(7) || 'gridpane-static';
if (!['blocked-root', 'normal', 'gridpane-static'].includes(mode)) throw new Error('Use --mode=gridpane-static, --mode=blocked-root or --mode=normal');
const clientType = process.argv.find(arg => arg.startsWith('--client='))?.slice(9) || 'chatgpt';
if (!['chatgpt', 'claude'].includes(clientType)) throw new Error('Use --client=chatgpt or --client=claude');
const writeMode = process.argv.find(arg => arg.startsWith('--write-mode='))?.slice(13) || 'read_only';
if (!['read_only', 'yolo'].includes(writeMode)) throw new Error('Use --write-mode=read_only or --write-mode=yolo');
const callback = clientType === 'claude' ? 'https://claude.ai/api/mcp/auth_callback' : 'https://chatgpt.com/connector_platform_oauth_redirect';
const callbackOrigin = new URL(callback).origin;
const reportPath = path.join(root, `oauth-http-${mode}-${clientType}${writeMode === 'yolo' ? '-yolo' : ''}-output.json`);
// The proxy serves only these two plugin-owned fixture metadata paths. In a
// default Nginx MIME table the extensionless resource document is octet-stream.
const staticMetadataTypes = new Map([
    ['/.well-known/oauth-authorization-server/wp-json/jalin-mcp/v1/oauth/issuer.json', 'application/json'],
    ['/.well-known/oauth-protected-resource/wp-json/jalin-mcp/v1/mcp', 'application/octet-stream'],
]);
const wordpress = path.join(runtime, 'wordpress');
const password = randomBytes(24).toString('hex');
/** @typedef {{ name: string, pass: boolean }} Case */
/** @typedef {{ path: string, status: number, contentType?: string | null }} DiscoveryAttempt */
/** @typedef {{ method?: string, headers?: HeadersInit, body?: BodyInit | null, browser?: boolean }} RequestOptions */
const cookies = new Map();
/** @type {Case[]} */
const cases = [];
/** @type {import('node:child_process').ChildProcess | undefined} */
let server;
/** @type {http.Server | undefined} */
let proxy;
let upstreamRequests = 0;
let blockedRequests = 0;
let staticRequests = 0;
let output = '';
/** @param {string} name @param {unknown} condition */
const check = (name, condition) => { if (!condition) throw new Error(name); cases.push({ name, pass: true }); };
try {
    await fs.cp(path.join(root, 'fixtures/wordpress'), wordpress, { recursive: true });
    const mu = path.join(wordpress, 'wp-content/mu-plugins');
    await fs.mkdir(mu, { recursive: true });
    // This is fixture-only proxy simulation. It is never part of the plugin/install archive.
    await fs.writeFile(path.join(mu, 'fg-disposable-https.php'), "<?php $_SERVER['HTTPS']='on'; $_SERVER['SERVER_PORT']='443';\n");
    const blueprint = path.join(runtime, 'setup.json');
    const php = `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
update_option('home', '${site}'); update_option('siteurl', '${site}');
update_option('permalink_structure', '/%postname%/');
$id = wp_create_user('fg_oauth_http_admin', '${password}', 'fixture@example.test');
if (is_wp_error($id)) { throw new RuntimeException('Could not create disposable administrator'); }
$user = new WP_User($id); $user->set_role('administrator');
$activation = activate_plugin('jalin-mcp-gateway/jalin-mcp-gateway.php');
if (is_wp_error($activation)) { throw new RuntimeException('Plugin activation failed'); }
update_option('fg_settings', ['enabled'=>true, 'oauth_enabled'=>true, 'writes'=>${writeMode === 'yolo' ? 'true' : 'false'}, 'write_mode'=>'${writeMode === 'yolo' ? 'yolo' : 'reviewed'}', 'sensitive'=>false, 'users'=>[$id], 'origins'=>[], 'finance_mappings'=>[]]);
${mode === 'gridpane-static' ? "wp_set_current_user($id); $published=FG_Discovery::publish(); if(($published['status']??'')!=='ready'){throw new RuntimeException('Public discovery publication failed');}" : ''}
flush_rewrite_rules(false);
`;
    await fs.writeFile(blueprint, JSON.stringify({ steps: [{ step: 'runPHP', code: php }] }));
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', () => resolve(undefined)));
    const address = listener.address();
    if (!address || typeof address === 'string') throw new Error('Expected local TCP listener address');
    const port = address.port;
    await new Promise(resolve => listener.close(resolve));
    const startedServer = spawn(process.execPath, [path.join(root, 'node_modules/@wp-playground/cli/wp-playground.js'), 'server',
        '--port', String(port), '--workers', '1', '--site-url', site, '--wp', '6.8.8', '--php', '8.3',
        '--wordpress-install-mode=install-from-existing-files-if-needed', '--blueprint', blueprint,
        `--mount-dir-before-install=${wordpress}`, '/wordpress',
        `--mount-dir=${path.resolve(root, '../../jalin-mcp-gateway')}`, '/wordpress/wp-content/plugins/jalin-mcp-gateway', '--verbosity=quiet'],
        { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
    server = startedServer;
    startedServer.stdout?.on('data', b => { output = (output + b.toString()).slice(-12000); });
    startedServer.stderr?.on('data', b => { output = (output + b.toString()).slice(-12000); });
    // Real reverse proxy: in blocked-root mode, the host returns 404 before PHP
    // for root discovery paths. Ordinary REST routes still reach WordPress.
    // This models root-only interception, not every possible host regex rule.
    const startedProxy = http.createServer(async (incoming, outgoing) => {
        const incomingURL = new URL(incoming.url || '/', 'http://127.0.0.1');
        if ((mode === 'blocked-root' && incomingURL.pathname.startsWith('/.well-known/')) || (mode==='gridpane-static' && incomingURL.pathname.includes('/.well-known'))) {
            // GridPane-like unanchored match: nested REST discovery routes are
            // intercepted too. Only the actual owned discovery files are served.
            const target = path.resolve(wordpress, `.${incomingURL.pathname}`);
            const contentType = staticMetadataTypes.get(incomingURL.pathname);
            if (mode==='gridpane-static' && incoming.method==='GET' && contentType) {
                try {
                    const data = await fs.readFile(target);
                    staticRequests++;
                    outgoing.writeHead(200, {'Content-Type':contentType,'Cache-Control':'no-cache'});
                    outgoing.end(data);
                    return;
                } catch(error) { if(!(error instanceof Error && 'code' in error && error.code === 'ENOENT')) { outgoing.writeHead(500); outgoing.end('Fixture static read failed'); return; } }
            }
            blockedRequests++;
            outgoing.writeHead(404, {'Content-Type': 'application/json', 'Cache-Control': 'no-store'});
            outgoing.end(JSON.stringify({error:'fixture_host_route_not_found'}));
            return;
        }
        upstreamRequests++;
        const forwarded = http.request({host:'127.0.0.1', port, path:incoming.url || '/', method:incoming.method,
            headers:{...incoming.headers,host:'gateway.example'}}, response => {
            outgoing.writeHead(response.statusCode || 502, response.headers);
            response.pipe(outgoing);
        });
        forwarded.on('error', () => { outgoing.writeHead(502); outgoing.end('Fixture upstream unavailable'); });
        incoming.pipe(forwarded);
    });
    proxy = startedProxy;
    await new Promise(resolve => startedProxy.listen(0, '127.0.0.1', () => resolve(undefined)));
    const proxyAddress = startedProxy.address();
    if (!proxyAddress || typeof proxyAddress === 'string') throw new Error('Expected local proxy address');
    const proxyPort = proxyAddress.port;
    /** @param {string | URL} url @param {RequestOptions} [options] */
    const request = async (url, { method = 'GET', headers = {}, body, browser = false } = {}) => {
        const original = new URL(url, site);
        if (original.origin !== site) throw new Error('Test refuses to follow an external URL');
        const safeHeaders = new Headers(headers);
        safeHeaders.set('Host', 'gateway.example');
        if (browser && cookies.size) safeHeaders.set('Cookie', [...cookies].map(([k,v]) => `${k}=${v}`).join('; '));
        const response = await fetch(`http://127.0.0.1:${proxyPort}${original.pathname}${original.search}`, {
            method, headers:safeHeaders, body, redirect: 'manual', signal: AbortSignal.timeout(15000),
        });
        if (browser) for (const cookie of response.headers.getSetCookie()) {
            const pair = cookie.split(';', 1)[0]; const pos = pair.indexOf('=');
            cookies.set(pair.slice(0, pos), pair.slice(pos+1));
        }
        return response;
    };
    let ready = false;
    for (let attempt=0; attempt<100; attempt++) {
        if (server.exitCode !== null) throw new Error('Disposable HTTP server stopped during startup');
        try { const r=await request('/wp-json/jalin-mcp/v1/oauth/authorization-server'); if(r.status===200){ ready=true; break; } } catch {}
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    check('Disposable HTTP server starts with plugin enabled', ready);
    const forwardedBeforeRootCheck = upstreamRequests;
    const rootDiscovery = await request('/.well-known/oauth-authorization-server');
    if (mode !== 'normal') {
        check('Host intercepts root discovery with 404 before WordPress', rootDiscovery.status===404 && upstreamRequests===forwardedBeforeRootCheck && blockedRequests===1);
    } else {
        const rootMetadata = await rootDiscovery.json();
        check('Normal host preserves legacy root discovery', rootDiscovery.status===200 && rootMetadata.issuer===issuer);
    }
    /** @type {DiscoveryAttempt[]} */
    const proactiveDiscoveryAttempts = [];
    // Proactive discovery begins with only the user-entered MCP URL. No prior
    // challenge, known issuer or resourceMetadataUrl override is supplied.
    const proactive = await discoverOAuthServerInfo(resource, {fetchFn:async (url, options) => {
        const response = await request(url, options);
        proactiveDiscoveryAttempts.push({path:new URL(url).pathname,status:response.status,contentType:response.headers.get('content-type')});
        return response;
    }});
    if (mode === 'blocked-root') {
        check('Proactive official SDK discovery fails when the host blocks all standard root discovery', proactive.resourceMetadata===undefined && proactive.authorizationServerMetadata===undefined && proactiveDiscoveryAttempts.length>=4 && proactiveDiscoveryAttempts.every(attempt=>attempt.status===404));
    } else {
        check('Official SDK proactively discovers the issuer from only the MCP URL before any 401 challenge', proactive.authorizationServerUrl===issuer && proactive.resourceMetadata?.resource===resource && proactive.authorizationServerMetadata?.issuer===issuer);
        check('Proactive discovery uses the path-specific protected resource and RFC8414 issuer documents', proactiveDiscoveryAttempts.length===2 && proactiveDiscoveryAttempts[0].path==='/.well-known/oauth-protected-resource/wp-json/jalin-mcp/v1/mcp' && proactiveDiscoveryAttempts[1].path==='/.well-known/oauth-authorization-server/wp-json/jalin-mcp/v1/oauth/issuer.json' && proactiveDiscoveryAttempts.every(attempt=>attempt.status===200));
        if(mode === 'gridpane-static') {
            check('Official SDK parses extensionless static resource JSON served with the default Nginx MIME type', proactiveDiscoveryAttempts[0].contentType==='application/octet-stream' && staticRequests===2);
        }
    }
    const unauthenticated = await request(resource, { method:'POST', headers:{'Content-Type':'application/json',Accept:'application/json, text/event-stream'}, body:JSON.stringify({jsonrpc:'2.0',id:1,method:'ping'}) });
    check('MCP HTTP 401 advertises Bearer resource metadata', unauthenticated.status===401 && /Bearer.*resource_metadata=/.test(unauthenticated.headers.get('www-authenticate') || ''));
    const resourceMetadataMatch = /resource_metadata="([^"]+)"/.exec(unauthenticated.headers.get('www-authenticate') || '');
    if (!resourceMetadataMatch) throw new Error('Missing resource metadata URL');
    const resourceURL = resourceMetadataMatch[1];
    const protectedResource = await (await request(resourceURL)).json();
    check('Protected resource discovery matches MCP audience and stable REST issuer', protectedResource.resource===resource && protectedResource.authorization_servers.includes(issuer));
    /** @type {DiscoveryAttempt[]} */
    const discoveryAttempts = [];
    const staticBeforeIssuerDiscovery = staticRequests;
    // Use the published MCP SDK's discovery implementation, not a copied plugin
    // algorithm. Only network transport is redirected to the disposable proxy.
    const metadata = await discoverAuthorizationServerMetadata(issuer, {fetchFn:async (url, options) => {
        const response = await request(url, options);
        discoveryAttempts.push({path:new URL(url).pathname,status:response.status});
        return response;
    }});
    if (!metadata || !metadata.registration_endpoint || !metadata.authorization_endpoint || !metadata.token_endpoint) throw new Error('Expected complete OAuth authorization server metadata');
    const expectedDiscoveryPaths = [
        '/.well-known/oauth-authorization-server/wp-json/jalin-mcp/v1/oauth/issuer.json',
        '/.well-known/openid-configuration/wp-json/jalin-mcp/v1/oauth/issuer.json',
        '/wp-json/jalin-mcp/v1/oauth/issuer.json/.well-known/openid-configuration',
    ];
    if (mode === 'blocked-root') {
        check('Official SDK correctly fails when standard discovery is blocked and no static metadata exists', metadata===undefined && discoveryAttempts.length===3 && discoveryAttempts.every((attempt,index)=>attempt.path===expectedDiscoveryPaths[index] && attempt.status===404));
        const reachableMetadata = await request('/wp-json/jalin-mcp/v1/oauth/authorization-server');
        check('Reachable nonstandard REST metadata alone is not claimed as client connectivity', reachableMetadata.status===200 && (await reachableMetadata.json()).issuer===issuer);
    } else {
    check('Official MCP SDK discovers exact issuer and mandatory S256 metadata', metadata?.issuer===issuer && metadata?.code_challenge_methods_supported?.includes('S256'));
    if(mode === 'normal') {
        check('Official SDK uses first standard discovery URL when host permits it', discoveryAttempts.length===1 && discoveryAttempts[0].path===expectedDiscoveryPaths[0] && discoveryAttempts[0].status===200);
        const response = await request(expectedDiscoveryPaths[0]);
        const candidateMetadata = await response.json();
        check('Canonical RFC8414 discovery route returns the stable issuer and registration endpoint', response.status===200 && candidateMetadata.issuer===issuer && candidateMetadata.registration_endpoint===metadata.registration_endpoint);
    } else {
        check('Official SDK discovers static RFC8414 metadata on a GridPane-like host', discoveryAttempts.length===1 && discoveryAttempts[0].path===expectedDiscoveryPaths[0] && discoveryAttempts[0].status===200 && staticRequests===staticBeforeIssuerDiscovery+1);
        const forwardedBeforeStaticCheck = upstreamRequests;
        const staticMetadata = await request(expectedDiscoveryPaths[0]);
        check('Published metadata is served as JSON without reaching WordPress', staticMetadata.headers.get('content-type')==='application/json' && (await staticMetadata.json()).issuer===issuer && upstreamRequests===forwardedBeforeStaticCheck && staticRequests===staticBeforeIssuerDiscovery+2);
        const forwardedBeforeNestedCheck = upstreamRequests;
        const nestedMetadata = await request(expectedDiscoveryPaths[2]);
        check('GridPane-like host also intercepts nested well-known URLs before PHP', nestedMetadata.status===404 && upstreamRequests===forwardedBeforeNestedCheck);
    }
    check('REST metadata responses remain uncached', (await request('/wp-json/jalin-mcp/v1/oauth/authorization-server')).headers.get('cache-control')?.includes('no-store'));
    const registration = await request(metadata.registration_endpoint, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({client_name:`Disposable ${clientType} HTTP test`,redirect_uris:[callback],grant_types:['authorization_code','refresh_token'],response_types:['code'],token_endpoint_auth_method:'none'})});
    const client=await registration.json();
    check('HTTP dynamic client registration succeeds without manually provisioned credentials',registration.status===201 && typeof client.client_id==='string');
    const verifier=randomBytes(48).toString('base64url');
    const auth=new URL(metadata.authorization_endpoint);
    auth.searchParams.set('client_id',client.client_id);auth.searchParams.set('redirect_uri',callback);auth.searchParams.set('response_type','code');auth.searchParams.set('scope','mcp');auth.searchParams.set('state','disposable-state');auth.searchParams.set('resource',resource);auth.searchParams.set('code_challenge_method','S256');auth.searchParams.set('code_challenge',createHash('sha256').update(verifier).digest('base64url'));
    const start=await request(auth.href,{browser:true});
    check('Authorization sends an anonymous user to native WordPress login',start.status===302 && (start.headers.get('location')||'').includes('wp-login.php'));
    const loginURL=start.headers.get('location');
    await request(loginURL || auth.href,{browser:true});
    const login=await request('/wp-login.php',{browser:true,method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({log:'fg_oauth_http_admin',pwd:password,'wp-submit':'Log In',redirect_to:new URL(loginURL || auth.href).searchParams.get('redirect_to')||auth.href,testcookie:'1'})});
    check('Native WordPress login succeeds using the synthetic account',login.status===302 && cookies.size>1);
    const consent=await request(login.headers.get('location')||auth.href,{browser:true});
    const html=await consent.text();
    // Export only a sanitized synthetic consent page for local brand visual QA.
    // No live accounts, cookies, codes, tokens or callback submissions are recorded.
    const previewDir = path.join(root, 'brand-preview');
    await fs.mkdir(previewDir, {recursive: true});
    let previewHtml = html.replace(/<input\b[^>]*>/gi, '').replace(/<form\b[^>]*>/gi, '<div class="jalinwp-oauth-actions">').replaceAll('</form>', '</div>');
    previewHtml = previewHtml.replace(/<button\b[^>]*>/gi, tag => tag.replace(/type=["']submit["']/i, 'type="button"').replace('<button', '<button disabled'));
    previewHtml = previewHtml.replace(/https:\/\/gateway\.example\/wp-content\/plugins\/jalin-mcp-gateway\/assets\//g, 'assets/');
    await fs.writeFile(path.join(previewDir, `oauth-${writeMode}.html`), previewHtml);
    await fs.cp(path.resolve(root, '../../jalin-mcp-gateway/assets'), path.join(previewDir, 'assets'), {recursive: true});
    const logo = await request('/wp-content/plugins/jalin-mcp-gateway/assets/brand/jalinwp-logo-horizontal-white.png');
    const logoBytes = new Uint8Array(await logo.arrayBuffer());
    check('Bundled JalinWP logo is served as a PNG', logo.status === 200 && logoBytes[0] === 137 && logoBytes[1] === 80);

    check('Authenticated authorization renders an explicit consent form',consent.status===200 && /<form\b/.test(html) && /approve|allow/i.test(html));
    if (writeMode === 'yolo') check('Consent explicitly describes YOLO access without dashboard approval', /YOLO/i.test(html) && /without approval in the WordPress dashboard/i.test(html));
    const expectedCsp = `default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self' ${callbackOrigin}; frame-ancestors 'none'; base-uri 'none'`;
    check('Consent page CSP permits only this registered client origin and same-site forms',consent.headers.get('content-security-policy')===expectedCsp);
    const params=new URLSearchParams();
    for (const input of html.matchAll(/<input\b[^>]*>/gi)) {
        const name=/\bname=["']([^"']+)["']/.exec(input[0]);const value=/\bvalue=["']([^"']*)["']/.exec(input[0]);
        if(name)params.set(name[1],(value?.[1]||'').replaceAll('&amp;','&').replaceAll('&#039;',"'").replaceAll('&quot;','"'));
    }
    // Decision is defined by the rendered submit button rather than a privileged test shortcut.
    const buttons=[...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/gi)];
    const approve=buttons.find(button=>/approve|allow/i.test(button[2]));
    if (!approve) throw new Error('Consent contains no named approval control');
    check('Consent contains a named approval control',true);
    const buttonName=/\bname=["']([^"']+)["']/.exec(approve[1]);const buttonValue=/\bvalue=["']([^"']*)["']/.exec(approve[1]);
    if(buttonName)params.set(buttonName[1],buttonValue?.[1]||'');
    const formAction=/<form\b[^>]*action=["']([^"']*)["']/.exec(html)?.[1]?.replaceAll('&amp;','&')||metadata.authorization_endpoint;
    const approved=await request(formAction,{browser:true,method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
    const returned=new URL(approved.headers.get('location')||site);
    check(`Consent returns a code, state and exact issuer to the registered ${clientType} callback`,approved.status===302 && `${returned.origin}${returned.pathname}`===callback && returned.searchParams.has('code') && returned.searchParams.get('state')==='disposable-state' && returned.searchParams.get('iss')===issuer);
    check('Consent POST redirect preserves the same restrictive client-specific CSP',approved.headers.get('content-security-policy')===expectedCsp);
    const exchange=await request(metadata.token_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'authorization_code',client_id:client.client_id,redirect_uri:callback,resource,code:returned.searchParams.get('code') || '',code_verifier:verifier})});
    const tokens=await exchange.json();
    check('HTTP authorization code exchange returns access and refresh tokens',exchange.status===200 && typeof tokens.access_token==='string' && typeof tokens.refresh_token==='string');
    /** @param {string} token @param {string} method @param {unknown} [params] */
    const call=async (token,method,params={})=>request(resource,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json, text/event-stream',Authorization:`Bearer ${token}`},body:JSON.stringify({jsonrpc:'2.0',id:2,method,params})});
    const initialize=await call(tokens.access_token,'initialize',{protocolVersion:'2025-11-25',capabilities:{},clientInfo:{name:'HTTP fixture',version:'1'}});
    const initialized=await initialize.json();
    check('Bearer token initializes MCP through a real HTTP request',initialize.status===200 && initialized.result?.serverInfo?.version==='0.3.3' && initialized.result?.serverInfo?.name==='jalinwp');
    const listing=await (await call(tokens.access_token,'tools/list')).json();
    if (writeMode === 'yolo') {
        check('Consented OAuth connection advertises immediate YOLO writes', /YOLO Mode is active/.test(initialized.result?.instructions || '') && listing.result?.tools.some(/** @param {{ name: string, description: string }} tool */ tool=>tool.name==='wp_content_create' && tool.description.startsWith('YOLO Mode:')));
        const written = await (await call(tokens.access_token,'tools/call',{name:'wp_content_create',arguments:{kind:'posts',title:'Synthetic OAuth YOLO Draft',content:'Created through the real MCP HTTP endpoint.'}})).json();
        const change = written.result?.structuredContent?.data;
        check('OAuth write applies in its original HTTP tool call without dashboard approval', written.result?.isError===false && change?.status==='applied' && change?.execution_mode==='yolo' && !change.review_url && !!change.change_id && Number.isInteger(change.result?.data?.id));
        const status = await (await call(tokens.access_token,'tools/call',{name:'gateway_change_status',arguments:{change_id:change.change_id}})).json();
        check('Applied YOLO change is visible to its OAuth connection', status.result?.structuredContent?.data?.status==='applied' && status.result?.structuredContent?.data?.execution_mode==='yolo');
        const duplicate = await (await call(tokens.access_token,'tools/call',{name:'gateway_apply_change',arguments:{change_id:change.change_id}})).json();
        check('Reviewed apply tool cannot repeat the YOLO change', duplicate.result?.isError===true);
        const draft = await (await call(tokens.access_token,'tools/call',{name:'wp_content_get',arguments:{kind:'posts',id:change.result.data.id}})).json();
        check('Created OAuth draft can be read in the next request', draft.result?.isError===false && draft.result?.structuredContent?.data?.data?.status==='draft' && draft.result?.structuredContent?.data?.data?.title?.raw==='Synthetic OAuth YOLO Draft');
    } else {
        check('MCP catalog respects a read-only consent grant',Array.isArray(listing.result?.tools) && listing.result.tools.some(/** @param {{ name: string }} tool */ tool=>tool.name==='wp_content_list') && !listing.result.tools.some(/** @param {{ name: string }} tool */ tool=>tool.name==='wp_content_create'));
    }
    const contentResponse=await call(tokens.access_token,'tools/call',{name:'wp_content_list',arguments:{kind:'posts',per_page:1}});
    const contentResult=await contentResponse.json();
    check('OAuth grant executes a WordPress read tool over HTTP',contentResponse.status===200 && contentResult.result?.isError===false && !!contentResult.result?.structuredContent);
    const native=await request('/wp-json/wp/v2/users/me',{headers:{Authorization:`Bearer ${tokens.access_token}`}});
    check('Gateway bearer does not authenticate ordinary WordPress REST routes',native.status===401 || native.status===403);
    const refreshed=await request(metadata.token_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'refresh_token',client_id:client.client_id,resource,refresh_token:tokens.refresh_token})});
    const rotated=await refreshed.json();
    check('HTTP refresh rotates the refresh token',refreshed.status===200 && typeof rotated.access_token==='string' && rotated.refresh_token!==tokens.refresh_token);
    check('Refreshed access token remains usable', (await call(rotated.access_token,'ping')).status===200);
    const replay=await request(metadata.token_endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'refresh_token',client_id:client.client_id,resource,refresh_token:tokens.refresh_token})});
    check('Reused refresh token is rejected',replay.status===400);
    check('Refresh replay revokes the associated active access grant',(await call(rotated.access_token,'ping')).status===401);
    }
    const report={status:'passed',mode,clientType,writeMode,passed:cases.length,total:cases.length,cases,proactiveDiscoveryAttempts,discoveryAttempts,discoveryClient:'@modelcontextprotocol/sdk 1.30.0',blockedRequests,staticRequests,stack:'WordPress 6.8.8 / PHP 8.3 / SQLite / local HTTP reverse proxy with fixture-only HTTPS recognition',limits:['Not a live GridPane host, native MySQL, real TLS, browser automation or actual ChatGPT/Claude connection.','Official MCP SDK exercises discovery only. The remaining OAuth flow uses a local synthetic HTTP client with the documented hosted callback. No authorization code is sent to the real callback.','GridPane-static mode models an unanchored well-known path interception serving two owned metadata files, including extensionless JSON as application/octet-stream; host-specific WAF/CDN policies are not simulated.']};
    await fs.writeFile(reportPath,JSON.stringify(report,null,2));
    console.log(JSON.stringify(report,null,2));
} catch(error) {
    // Never print raw requests, responses, credentials, auth codes or server access logs.
    const report={status:'failed',mode,clientType,writeMode,passed:cases.length,cases,error:error instanceof Error ? error.message : String(error)};
    await fs.writeFile(reportPath,JSON.stringify(report,null,2));
    console.error(JSON.stringify(report,null,2));
    process.exitCode=1;
} finally {
    if(proxy) { const closingProxy = proxy; await new Promise(resolve => { closingProxy.closeAllConnections(); closingProxy.close(() => resolve(undefined)); }); }
    if(server && server.exitCode===null) {
        const stoppingServer = server;
        stoppingServer.kill('SIGTERM');
        await Promise.race([new Promise(resolve=>stoppingServer.once('exit',resolve)),new Promise(resolve=>setTimeout(resolve,1500))]);
        if(stoppingServer.exitCode===null)stoppingServer.kill('SIGKILL');
    }
    await fs.rm(runtime,{recursive:true,force:true,maxRetries:3,retryDelay:100});
}
