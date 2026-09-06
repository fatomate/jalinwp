<?php
/** Render the actual plugin page into inert local fixtures; use run-wp-tests.mjs only. */
define('FG_TEST_DISPOSABLE', true);
if (!str_starts_with(__FILE__, '/test-results/')) { throw new RuntimeException('Run only inside the disposable test runner.'); }
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
if (!current_user_can('manage_options')) { throw new RuntimeException('The disposable fixture administrator is unavailable.'); }
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
foreach (['woocommerce/woocommerce.php', 'jalin-mcp-gateway/jalin-mcp-gateway.php'] as $plugin) {
    $activated = activate_plugin($plugin);
    if (is_wp_error($activated)) { throw new RuntimeException($activated->get_error_message()); }
}
WC_Install::install();
WC()->init();
WC_Install::create_roles();
$GLOBALS['wp_roles'] = new WP_Roles();
wp_set_current_user(0);
wp_set_current_user(1);
wp_update_user(['ID'=>1, 'display_name'=>'JalinWP Preview Admin']);

$output = '/test-results/brand-preview';
wp_mkdir_p($output . '/assets/brand');
wp_mkdir_p($output . '/assets/wordpress');
$cases = [];
$assets = [];
function jalin_preview_check(string $label, bool $ok): void {
    global $cases;
    $cases[] = ['name'=>$label, 'pass'=>$ok];
    if (!$ok) { throw new RuntimeException($label); }
}
foreach (['brand.css', 'admin.css', 'finance.css', 'admin.js', 'finance.js'] as $file) {
    jalin_preview_check('Copy plugin asset ' . $file, copy(FG_DIR . 'assets/' . $file, $output . '/assets/' . $file));
    $assets[] = 'assets/' . $file;
}
foreach (glob(FG_DIR . 'assets/brand/*') as $file) {
    if (!is_file($file)) { continue; }
    jalin_preview_check('Copy brand asset ' . basename($file), copy($file, $output . '/assets/brand/' . basename($file)));
    $assets[] = 'assets/brand/' . basename($file);
}
$wordpress_css = [
    'common.css'=>ABSPATH . 'wp-admin/css/common.css',
    'forms.css'=>ABSPATH . 'wp-admin/css/forms.css',
    'list-tables.css'=>ABSPATH . 'wp-admin/css/list-tables.css',
    'buttons.css'=>ABSPATH . 'wp-includes/css/buttons.css',
];
foreach ($wordpress_css as $name=>$source) {
    jalin_preview_check('Copy WordPress asset ' . $name, copy($source, $output . '/assets/wordpress/' . $name));
    $assets[] = 'assets/wordpress/' . $name;
}
$style_links = '';
foreach (array_keys($wordpress_css) as $name) { $style_links .= '<link rel="stylesheet" href="assets/wordpress/' . $name . '">'; }
foreach (['brand', 'admin', 'finance'] as $name) { $style_links .= '<link rel="stylesheet" href="assets/' . $name . '.css">'; }
$settings = array_replace(FG_Core::settings(), [
    'enabled'=>true, 'oauth_enabled'=>true, 'writes'=>false, 'write_mode'=>'reviewed',
    'sensitive'=>true, 'users'=>[1], 'origins'=>[], 'finance_mappings'=>[],
]);
update_option('fg_settings', $settings, false);
$pages = [];
$variants = ['connect'=>'connect', 'connections'=>'connections', 'access'=>'access', 'finance'=>'finance', 'changes'=>'changes', 'audit'=>'audit', 'connect-disabled'=>'connect'];
foreach ($variants as $name=>$tab) {
    $settings['enabled'] = $name !== 'connect-disabled';
    update_option('fg_settings', $settings, false);
    $_GET = ['page'=>'jalin-mcp-gateway', 'tab'=>$tab];
    $_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=jalin-mcp-gateway&tab=' . $tab;
    ob_start();
    FG_Admin::page();
    $content = ob_get_clean();
    jalin_preview_check($name . ': full real page includes branded logo', str_contains($content, 'class="fg-brand-logo"') && str_contains($content, 'alt="JalinWP"'));
    jalin_preview_check($name . ': all six real tabs render', substr_count($content, 'class="nav-tab ') === 6);
    jalin_preview_check($name . ': active tab announced', substr_count($content, 'aria-current="page"') === 1);
    jalin_preview_check($name . ': current settings page slug', str_contains($content, 'options-general.php?page=jalin-mcp-gateway'));
    if ($name === 'finance') {
        jalin_preview_check('Finance: real WooCommerce gateways and mapping forms render', str_contains($content, 'name="mapping[fee_key]"') && str_contains($content, 'name="gateway" value="bacs"'));
    }
    if ($name === 'audit') { jalin_preview_check('Activity: Client column present', str_contains($content, '<th>Client</th>')); }
    if ($name === 'connect') { jalin_preview_check('Connection: disable button present', str_contains($content, 'Disable MCP Connection')); }
    if ($name === 'connect-disabled') { jalin_preview_check('Connection: Title Case enable button present', str_contains($content, 'Enable For My Account')); }
    // Rewrite only this disposable export; production paths/forms remain untouched.
    $content = str_replace(esc_url(plugins_url('assets/', FG_FILE)), 'assets/', $content);
    $content = preg_replace('/action="[^"]*"/', 'action="#"', $content);
    $content = preg_replace_callback('/href="([^"]*)"/', static function ($match) {
        $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_contains($href, 'options-general.php?page=jalin-mcp-gateway')) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $tab = in_array($query['tab'] ?? 'connect', ['connect','connections','access','finance','changes','audit'], true) ? ($query['tab'] ?? 'connect') : 'connect';
            return 'href="' . $tab . '.html"';
        }
        return str_starts_with($href, '#') ? $match[0] : 'href="#"';
    }, $content);
    $content = preg_replace('/(<input[^>]*name="_wpnonce"[^>]*value=")[^"]*(")/', '$1preview-nonce$2', $content);
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta http-equiv="Content-Security-Policy" content="default-src &#39;self&#39;; img-src &#39;self&#39; data:; style-src &#39;self&#39; &#39;unsafe-inline&#39;; script-src &#39;self&#39; &#39;unsafe-inline&#39;; connect-src &#39;none&#39;; form-action &#39;none&#39;; base-uri &#39;none&#39;">'
        . '<title>JalinWP Preview — ' . esc_html($name) . '</title>' . $style_links
        . '<style>body{margin:0;padding:20px 24px 48px;background:#f0f0f1}.jalin-preview-banner{max-width:1120px;margin:0 auto 20px;color:#55556A;font:13px/1.5 system-ui,sans-serif}.wrap.fg-wrap{margin-left:auto;margin-right:auto}@media(max-width:600px){body{padding:12px 12px 32px}}</style>'
        . '<script>document.addEventListener("submit",function(event){event.preventDefault();event.stopImmediatePropagation();},true);</script>'
        . '</head><body class="wp-admin wp-core-ui"><p class="jalin-preview-banner">Disposable WordPress Preview · Forms Do Not Submit · Synthetic Administrator and Settings</p>'
        . $content . '<script src="assets/admin.js"></script><script src="assets/finance.js"></script></body></html>';
    jalin_preview_check($name . ': export has no remote asset URLs', !preg_match('/(?:src|href)="https?:/', $html));
    jalin_preview_check($name . ': exported forms inert', !preg_match('/<form[^>]*action="(?!#)/', $html));
    jalin_preview_check($name . ': export written', file_put_contents($output . '/' . $name . '.html', $html) !== false);
    $pages[] = ['name'=>$name, 'file'=>'brand-preview/' . $name . '.html', 'bytes'=>strlen($html), 'sha256'=>hash('sha256', $html)];
}
$report = [
    'status'=>'passed', 'plugin_version'=>FG_VERSION, 'wordpress_version'=>get_bloginfo('version'),
    'source'=>'Real FG_Admin::page() in disposable WordPress/PHP-WASM with WooCommerce loaded',
    'limits'=>['Static browser fixture with inert forms', 'No real OAuth client or live site access', 'No orders, grants or activity records seeded', 'Responsive/browser checks are separate'],
    'pages'=>$pages, 'assets'=>$assets, 'passed'=>count($cases), 'total'=>count($cases), 'cases'=>$cases,
];
file_put_contents('/test-results/brand-verification.json', wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
