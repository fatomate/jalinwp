<?php
defined('ABSPATH') || exit;

final class FG_Admin {
    public static function boot(): void {
        add_action('admin_menu', static function () {
            add_options_page('JalinWP', 'JalinWP', 'manage_options', 'fames-mcp-gateway', [self::class, 'page']);
        });
        add_action('admin_post_fg_save', [self::class, 'save']);
        add_action('admin_post_fg_disable_connection', [self::class, 'disable_connection']);
        add_action('admin_post_fg_remove_account', [self::class, 'remove_account']);
        add_action('admin_post_fg_review', [self::class, 'review']);
        add_action('admin_post_fg_quick_connect', [self::class, 'quick_connect']);
        add_action('admin_post_fg_connection_check', [self::class, 'connection_check']);
        add_action('wp_ajax_fg_connection_check', [self::class, 'connection_check_ajax']);
        add_action('admin_post_fg_revoke_connection', [self::class, 'revoke_connection']);
        add_action('admin_post_fg_delete_connection', [self::class, 'delete_connection']);
        add_action('admin_post_fg_clear_connections', [self::class, 'clear_connections']);
        add_action('admin_post_fg_start_connection_trace', [self::class, 'start_connection_trace']);
        add_action('admin_post_fg_connection_report', [self::class, 'connection_report']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook === 'settings_page_fames-mcp-gateway') {
                wp_enqueue_style('jalinwp-brand', plugins_url('assets/brand.css', FG_FILE), [], FG_VERSION);
                wp_enqueue_style('fames-mcp', plugins_url('assets/admin.css', FG_FILE), ['jalinwp-brand'], FG_VERSION);
                wp_enqueue_script('fames-mcp-admin', plugins_url('assets/admin.js', FG_FILE), [], FG_VERSION, true);
                wp_localize_script('fames-mcp-admin', 'fgConnection', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('fg_connection_check')]);
            }
        });
    }
    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { wp_die('POST required.', '', ['response' => 405]); }
        check_admin_referer($nonce);
    }
    private static function expected(): string {
        return isset($_POST['settings_version']) && is_string($_POST['settings_version']) ? wp_unslash($_POST['settings_version']) : '';
    }
    private static function finish_settings($result, array $input = [], string $tab = 'access'): void {
        $url = admin_url('options-general.php?page=fames-mcp-gateway&tab=' . ($tab === 'connect' ? 'connect' : 'access'));
        if (is_wp_error($result)) {
            set_transient('fg_connection_form_' . get_current_user_id(), ['message'=>$result->get_error_message(), 'code'=>$result->get_error_code(), 'input'=>$input], 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect($url . '&settings_error=1' . ($tab === 'connect' ? '#fg-connect-title' : '#fg-access'));
        } else {
            wp_safe_redirect($url . '&saved=' . (!empty($result['changed']) ? 'changed' : 'unchanged'));
        }
        exit;
    }
    public static function save(): void {
        self::guard('fg_save');
        $mode = isset($_POST['change_mode']) && is_string($_POST['change_mode']) ? wp_unslash($_POST['change_mode']) : '';
        $input = ['oauth_enabled'=>isset($_POST['oauth_enabled']), 'writes'=>$mode !== 'readonly', 'write_mode'=>$mode === 'yolo' ? 'yolo' : 'reviewed', 'sensitive'=>isset($_POST['sensitive']),
            'origins'=>isset($_POST['origins']) && is_string($_POST['origins']) ? wp_unslash($_POST['origins']) : ''];
        if (!in_array($mode, ['readonly', 'reviewed', 'yolo'], true)) {
            self::finish_settings(new WP_Error('fg_settings_mode', 'Choose Read Only, Reviewed Changes, or YOLO Mode before saving.'), $input);
        }
        $origins = [];
        foreach (preg_split('/\R/', $input['origins']) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $parts = wp_parse_url($line);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
                self::finish_settings(new WP_Error('fg_origins', 'Allowed browser origins must be HTTPS origins only, for example https://client.example. Do not include a path or trailing slash.'), $input);
            }
            $origins[] = $line;
        }
        if (count($origins) > 20 || strlen($input['origins']) > 6000) {
            self::finish_settings(new WP_Error('fg_origins', 'Use no more than 20 browser origins and 6,000 characters.'), $input);
        }
        $values = $input;
        $values['origins'] = array_values(array_unique($origins));
        self::finish_settings(FG_Settings::save_section('connection', $values, self::expected()), $input);
    }
    public static function quick_connect(): void {
        self::guard('fg_quick_connect');
        self::finish_settings(FG_Settings::enable_for_user(get_current_user_id(), self::expected()), [], 'connect');
    }
    public static function disable_connection(): void {
        self::guard('fg_disable_connection');
        self::finish_settings(FG_Settings::disable(self::expected()), [], 'connect');
    }
    public static function remove_account(): void {
        self::guard('fg_remove_account');
        $id = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        self::finish_settings(FG_Settings::remove_account($id, self::expected()));
    }
    private static function action_form(string $action, string $label, string $class = 'button', array $fields = [], string $version = ''): void {
        echo '<form class="fg-immediate-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="settings_version" value="' . esc_attr($version) . '">';
        foreach ($fields as $key => $value) { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">'; }
        echo '<button class="' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button></form>';
    }
    public static function connection_check(): void {
        self::guard('fg_connection_check');
        $report = FG_Connection::diagnostics();
        set_transient('fg_connection_check_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS);
        if (class_exists('FG_Connection_Trace')) { FG_Connection_Trace::remember_checks($report); }
        wp_safe_redirect(admin_url('options-general.php?page=fames-mcp-gateway&tab=connect&checked=1#fg-checks'));
        exit;
    }
    public static function connection_check_ajax(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Administrator access required.'], 403); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { wp_send_json_error(['message' => 'POST required.'], 405); }
        check_ajax_referer('fg_connection_check', '_ajax_nonce');
        $report = FG_Connection::diagnostics();
        set_transient('fg_connection_check_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS);
        if (class_exists('FG_Connection_Trace')) { FG_Connection_Trace::remember_checks($report); }
        wp_send_json_success($report);
    }
    public static function start_connection_trace(): void {
        self::guard('fg_start_connection_trace');
        if (!FG_Connection_Trace::start()) { wp_die('The connection trace could not be started. Check database errors and retry.', '', ['response' => 503, 'back_link' => true]); }
        wp_safe_redirect(admin_url('options-general.php?page=fames-mcp-gateway&tab=connect&trace_started=1#fg-trace'));
        exit;
    }
    public static function connection_report(): void {
        self::guard('fg_connection_report');
        $report = FG_Connection_Trace::report();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="jalinwp-connection-report.json"');
        header('X-Content-Type-Options: nosniff');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    public static function revoke_connection(): void {
        self::guard('fg_revoke_connection');
        if (isset($_POST['revoke_all']) && $_POST['revoke_all'] === '1') {
            $revoked = FG_OAuth::revoke_all();
        } else {
            $id = isset($_POST['grant_id']) && is_string($_POST['grant_id']) ? sanitize_text_field(wp_unslash($_POST['grant_id'])) : '';
            if ($id === '') { wp_die('A connection ID is required.', '', ['response' => 400]); }
            $revoked = FG_OAuth::revoke($id);
        }
        if (!$revoked) {
            wp_die('WordPress could not confirm that connection access was revoked. It may still be active. Use Disable MCP Connection, ask your host to check database errors, then retry revocation.', 'Connection Revocation Failed', ['response' => 503, 'back_link' => true]);
        }
        FG_Core::audit('gateway_oauth_revoke', isset($_POST['revoke_all']) ? 'revoked_all' : 'revoked', '', ['auth_source'=>'wordpress_admin']);
        wp_safe_redirect(admin_url('options-general.php?page=fames-mcp-gateway&tab=connections&revoked=1#fg-connections'));
        exit;
    }
    private static function finish_connection_cleanup(array|WP_Error $result, string $outcome): void {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && in_array($data['status'] ?? null, [400, 404, 409, 503], true) ? $data['status'] : 503;
            wp_die(esc_html($result->get_error_message()), 'Connection Cleanup Failed', ['response' => $status, 'back_link' => true]);
        }
        $deleted = (int) ($result['deleted'] ?? 0);
        if (!FG_Core::audit('gateway_oauth_cleanup', $outcome, '', ['auth_source'=>'wordpress_admin'])) {
            wp_die('The connection cleanup completed, but its activity entry could not be saved. Refresh OAuth Connections to see the current list.', 'Activity Entry Could Not Be Saved', ['response' => 503, 'back_link' => true]);
        }
        wp_safe_redirect(admin_url('options-general.php?page=fames-mcp-gateway&tab=connections&connections_cleared=' . $deleted . '#fg-connections'));
        exit;
    }
    public static function delete_connection(): void {
        self::guard('fg_delete_connection');
        $id = isset($_POST['grant_id']) && is_string($_POST['grant_id']) ? wp_unslash($_POST['grant_id']) : '';
        if (!wp_is_uuid($id)) { wp_die('A valid connection ID is required.', '', ['response' => 400]); }
        self::finish_connection_cleanup(FG_OAuth::delete_connection($id), 'inactive_connection_deleted');
    }
    public static function clear_connections(): void {
        self::guard('fg_clear_connections');
        if (isset($_POST['clear_all']) && (!is_string($_POST['clear_all']) || $_POST['clear_all'] !== '1')) {
            wp_die('Choose a valid connection cleanup action.', '', ['response' => 400]);
        }
        $all = isset($_POST['clear_all']) && $_POST['clear_all'] === '1';
        self::finish_connection_cleanup(FG_OAuth::clear_connections($all), $all ? 'all_connections_revoked_and_cleared' : 'inactive_connections_cleared');
    }
    public static function review(): void {
        self::guard('fg_review');
        $id = isset($_POST['change_id']) && is_string($_POST['change_id']) ? sanitize_text_field(wp_unslash($_POST['change_id'])) : '';
        $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? sanitize_key($_POST['decision']) : '';
        $validation = isset($_POST['design_validation']) && is_string($_POST['design_validation']) ? sanitize_text_field(wp_unslash($_POST['design_validation'])) : '';
        try { FG_Approvals::review($id, $decision, $validation); }
        catch (FG_Failure $error) { wp_die(esc_html($error->getMessage())); }
        wp_safe_redirect(admin_url('options-general.php?page=fames-mcp-gateway&tab=changes'));
        exit;
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $snapshot = FG_Settings::snapshot('connection');
        if (is_wp_error($snapshot)) { echo '<div class="wrap fg-wrap"><h1>JalinWP</h1><div class="notice notice-error"><p>' . esc_html($snapshot->get_error_message()) . '</p></div></div>'; return; }
        $settings = $snapshot['settings'];
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connect';
        $tabs = ['connect' => 'Connection Setup', 'connections' => 'OAuth Connections', 'access' => 'Access Controls', 'finance' => 'Finance Setup', 'changes' => 'Changes & Review', 'audit' => 'Activity Log'];
        if (!isset($tabs[$tab])) { $tab = 'connect'; }
        $base = admin_url('options-general.php?page=fames-mcp-gateway');
        ?>
        <div class="wrap fg-wrap">
            <header class="fg-header">
                <div class="fg-brand-row">
                    <h1 class="fg-brand-title"><img class="fg-brand-logo" src="<?php echo esc_url(plugins_url('assets/brand/jalinwp-logo-horizontal-white.png', FG_FILE)); ?>" alt="JalinWP"></h1>
                    <span class="fg-version"><?php echo esc_html(FG_VERSION); ?></span>
                </div>
                <p>Connect your AI workspace to WordPress and WooCommerce.</p>
            </header>
            <nav class="nav-tab-wrapper" aria-label="JalinWP Settings">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($base . '&tab=' . $key); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p><?php echo $_GET['saved'] === 'unchanged' ? 'No settings changed. Existing change approvals are preserved.' : 'Settings saved. Earlier unfinished change requests have been revoked.'; ?></p></div><?php endif; ?>
            <?php if (isset($_GET['connected_setup'])) : ?><div class="notice notice-success"><p>Gateway and OAuth enabled for your account. Your data access and change settings have been preserved. Continue with step 2 below.</p></div><?php endif; ?>
            <?php if (isset($_GET['revoked'])) : ?><div class="notice notice-success"><p>Connection access revoked. The client must connect again and request fresh approval.</p></div><?php endif; ?>
            <?php if (isset($_GET['connections_cleared']) && is_string($_GET['connections_cleared']) && ctype_digit($_GET['connections_cleared'])) : ?><div class="notice notice-success"><p><?php echo (int) $_GET['connections_cleared']; ?> connection record(s) removed. Activity Log entries are retained.</p></div><?php endif; ?>
            <?php if (isset($_GET['trace_started'])) : ?><div class="notice notice-success"><p>Connection trace started for 15 minutes. Retry connecting once in your AI client, then download the connection report below.</p></div><?php endif; ?>
            <?php if ($tab === 'connect' && isset($_GET['settings_error'])) : $error = get_transient('fg_connection_form_' . get_current_user_id()); if (is_array($error)) : delete_transient('fg_connection_form_' . get_current_user_id()); ?><div class="notice notice-error" role="alert"><p><?php echo esc_html($error['message']); ?></p></div><?php endif; endif; ?>
            <?php if ($tab === 'changes') { self::changes(); } elseif ($tab === 'audit') { self::audit(); } elseif ($tab === 'finance') { FG_Finance_Admin::render($settings); } elseif ($tab === 'connections') { self::connections(); } elseif ($tab === 'access') { self::form($settings); } else { self::connect($settings); } ?>
        </div>
        <?php
    }
    private static function connect(array $settings): void {
        $version = FG_Settings::version_for('connection', $settings);
        $site_enabled = !empty($settings['enabled']);
        $ready = !empty($settings['enabled']) && !empty($settings['oauth_enabled']) && in_array(get_current_user_id(), array_map('intval', $settings['users']), true);
        $report = isset($_GET['checked']) ? get_transient('fg_connection_check_' . get_current_user_id()) : false;
        ?>
        <section class="fg-card fg-connect-card" aria-labelledby="fg-connect-title">
            <div class="fg-section-heading"><div><span class="fg-kicker">Start Here</span><h2 id="fg-connect-title">Connect In Three Steps</h2></div><span class="fg-status <?php echo $site_enabled ? 'fg-status-pass' : 'fg-status-warning'; ?>"><?php echo $site_enabled ? 'MCP Enabled' : 'MCP Disabled'; ?></span></div>
            <p class="fg-current-mode"><strong>Change Mode:</strong> <?php echo esc_html(self::change_mode_label($settings)); ?> <a href="<?php echo esc_url(admin_url('options-general.php?page=fames-mcp-gateway&tab=access#fg-change-mode')); ?>">Change Mode Settings</a></p>
            <p>Connect ChatGPT or Claude directly to this site. Sign in with WordPress when prompted and approve the access shown.</p>
            <ol class="fg-steps">
                <li><div class="fg-step-number" aria-hidden="true">1</div><div><h3>Your MCP Connection</h3>
                    <?php if ($ready) : ?><p>Your account is allowed, and the gateway and OAuth are enabled. You can continue to step 2.</p>
                    <?php else : ?><p><?php echo $site_enabled ? 'MCP is enabled for this site. Enable your account and OAuth sign-in to connect.' : 'Enable MCP and OAuth sign-in for your current administrator account.'; ?></p>
                    <?php self::action_form('fg_quick_connect', 'Enable For My Account', 'button button-primary', [], $version); ?>
                    <?php endif; ?>
                    <?php if ($site_enabled) : ?>
                        <?php self::action_form('fg_disable_connection', 'Disable MCP Connection', 'button', [], $version); ?>
                        <p class="description">Stops MCP access for this site and revokes its OAuth connections. Re-enable and reconnect to restore access.</p>
                    <?php endif; ?>
                    <p class="description">Order and financial data access, and change requests, stay as configured in <a href="<?php echo esc_url(admin_url('options-general.php?page=fames-mcp-gateway&tab=access')); ?>">Access Controls</a>. Enable each explicitly when needed.</p>
                </div></li>
                <li><div class="fg-step-number" aria-hidden="true">2</div><div><h3>Copy Your Connector URL</h3><label class="screen-reader-text" for="fg-endpoint">MCP Server URL</label>
                    <div class="fg-copy-row"><input id="fg-endpoint" class="large-text code" type="url" readonly value="<?php echo esc_attr(rest_url('fames-mcp/v1/mcp')); ?>" spellcheck="false"><button type="button" class="button fg-copy-button" data-copy-target="fg-endpoint" hidden>Copy URL</button></div><span id="fg-copy-status" class="fg-inline-status" role="status" aria-live="polite"></span>
                </div></li>
                <li><div class="fg-step-number" aria-hidden="true">3</div><div><h3>Add The Connector In ChatGPT Or Claude</h3>
                    <p>In your AI client's app, plugin or connector settings, create a custom MCP connection. Paste the URL above and select <strong>OAuth</strong>. Leave <strong>OAuth Client ID</strong> and <strong>Client Secret</strong> blank for automatic registration.</p>
                    <p>Click <strong>Connect</strong>, sign in to this WordPress site with an allowed account, and approve the requested access. You will then return to your AI client.</p>
                    <p class="description">If your earlier connector still shows a registration error after updating this plugin, run the checks below and recreate the connector with OAuth and blank client credentials.</p>
                </div></li>
            </ol>
            <?php if (class_exists('FG_Connection_Trace')) : ?>
                <details class="fg-advanced"><summary>Advanced OAuth Settings</summary>
                    <p>Automatic discovery is the default. If your client offers manual OAuth configuration, copy these public settings. Keep automatic client registration enabled, leave Client ID and Client Secret blank, and use the exact server URL above.</p>
                    <?php foreach (['issuer' => 'Issuer', 'authorization_endpoint' => 'Authorization URL', 'token_endpoint' => 'Token URL', 'registration_endpoint' => 'Registration URL', 'scope' => 'Scope'] as $key => $label) : ?>
                        <p><label for="fg-oauth-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label><br><input id="fg-oauth-<?php echo esc_attr($key); ?>" type="text" class="large-text code" readonly value="<?php echo esc_attr(FG_Connection_Trace::manual_settings()[$key]); ?>" spellcheck="false"></p>
                    <?php endforeach; ?>
                    <p class="description">Manual settings cannot bypass a blocked registration, token or sign-in endpoint. Run the connection checks and trace below if setup still fails.</p>
                </details>
            <?php endif; ?>
        </section>
        <section id="fg-checks" class="fg-card" aria-labelledby="fg-checks-title">
            <div class="fg-section-heading"><div><h2 id="fg-checks-title">Connection Checks</h2><p>Check HTTPS, gateway settings and the sign-in discovery URLs used by MCP clients.</p></div>
                <form id="fg-check-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_connection_check"><?php wp_nonce_field('fg_connection_check'); ?><button class="button" type="submit">Check Connection</button></form>
            </div>
            <p id="fg-check-status" class="fg-inline-status" role="status" aria-live="polite"><?php echo is_array($report) ? 'Checks completed.' : 'Run these checks if ChatGPT or Claude cannot register or connect.'; ?></p>
            <div id="fg-check-results"><?php if (is_array($report)) { self::render_checks($report); } ?></div>
            <p class="description">These checks run from your WordPress host. A successful check does not confirm that your AI client has connected or that your host permits its requests.</p>
        </section>
        <?php if (class_exists('FG_Connection_Trace')) : $trace = FG_Connection_Trace::state(); ?>
            <section id="fg-trace" class="fg-card" aria-labelledby="fg-trace-title"><h2 id="fg-trace-title">Troubleshoot A Connection Attempt</h2>
                <p>Start a temporary trace, retry connecting once in ChatGPT or Claude, then download the report to share with support. The report includes public connection URLs, the last check statuses and up to 50 recent request summaries.</p>
                <p><strong><?php echo $trace['active'] ? 'Trace active until ' . esc_html(gmdate('H:i:s', $trace['expires'])) . ' UTC.' : 'Trace is off.'; ?></strong> <?php echo (int) $trace['events']; ?> request summaries available.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_start_connection_trace"><?php wp_nonce_field('fg_start_connection_trace'); ?><button class="button" type="submit">Start 15-Minute Connection Trace</button></form>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_connection_report"><?php wp_nonce_field('fg_connection_report'); ?><p><button class="button" type="submit">Download Connection Report</button></p></form>
                <p class="description">The trace records only request stage, method, HTTP status, a fixed error category, authentication scheme and callback family. It excludes credentials, request bodies, account data and IP addresses. Recording stops after 15 minutes; summaries expire after 24 hours and cleanup runs through WordPress scheduled tasks.</p>
                <p class="description">Only REST requests reaching WordPress are visible. Requests blocked before PHP, static discovery files and browser sign-in/consent are not captured. Host self-checks may appear in the trace; rapid requests may be omitted.</p>
            </section>
        <?php endif; ?>
        <?php
    }
    private static function diagnostic_label(string $label): string {
        $labels = ['Administrator access'=>'Administrator Access', 'OAuth enabled'=>'OAuth Enabled', 'Allowed accounts'=>'Allowed Accounts', 'Gateway storage'=>'Gateway Storage', 'Public discovery'=>'Public Discovery', 'OAuth discovery'=>'OAuth Discovery', 'MCP resource discovery'=>'MCP Resource Discovery', 'Proactive MCP resource discovery'=>'Proactive MCP Resource Discovery', 'Sign-in challenge'=>'Sign-In Challenge', 'GET sign-in challenge'=>'GET Sign-In Challenge', 'Final client test'=>'Final Client Test'];
        return $labels[$label] ?? $label;
    }
    private static function render_checks(array $report): void {
        $checks = isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : [];
        echo '<ul class="fg-check-list">';
        foreach ($checks as $check) {
            if (!is_array($check)) { continue; }
            $status = in_array($check['status'] ?? '', ['pass', 'fail', 'warning'], true) ? $check['status'] : 'warning';
            $label = is_string($check['label'] ?? null) ? $check['label'] : 'Connection Check';
            $label = self::diagnostic_label($label);
            $detail = is_string($check['detail'] ?? null) ? $check['detail'] : '';
            echo '<li><span class="fg-status fg-status-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span><div><strong>' . esc_html($label) . '</strong><p>' . esc_html($detail) . '</p></div></li>';
        }
        echo '</ul>';
    }
    private static function connections(): void {
        $rows = FG_OAuth::connections();
        ?>
        <section id="fg-connections" class="fg-card">
            <div class="fg-section-heading"><h2>OAuth Connections</h2><a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=fames-mcp-gateway&tab=connections#fg-connections')); ?>">Refresh Connections</a></div>
            <p>Each connection can use only the access approved during sign-in and still allowed by your settings and WordPress role. Reconnect to approve additional access.</p>
            <?php if (is_wp_error($rows)) : ?>
                <div class="notice notice-error inline" role="alert"><h3>Connections Could Not Be Loaded</h3><p>WordPress could not read the OAuth connection records. Refresh this list to retry. Existing authorizations have not been changed.</p></div>
            <?php elseif (!$rows) : ?><p class="fg-empty">No OAuth connections are recorded. <a href="<?php echo esc_url(admin_url('options-general.php?page=fames-mcp-gateway&tab=connect')); ?>">Open Connection Setup</a> to connect ChatGPT or Claude, then refresh this list.</p>
            <?php else : ?>
                <div class="fg-table-scroll"><table class="widefat striped"><thead><tr><th>Client</th><th>WordPress Account</th><th>Approved Access</th><th>Status</th><th>Connection Details</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($rows as $row) :
                    $user_id = (int) ($row['user_id'] ?? 0);
                    $user = get_userdata($user_id);
                    $client = is_string($row['client_name'] ?? null) && $row['client_name'] !== '' ? $row['client_name'] : 'MCP Client';
                    $permissions = ['WordPress Access'];
                    if (!empty($row['sensitive'])) { $permissions[] = 'Order, Customer, Comment, and Financial Data'; }
                    if (!empty($row['writes'])) { $permissions[] = !empty($row['yolo']) ? 'YOLO Changes (No Dashboard Approval)' : 'Reviewed Changes'; }
                    $state = $row['lifecycle_status'] ?? 'authorization_recorded';
                    $label = $row['lifecycle_label'] ?? 'Authorization Recorded';
                    $badge = $state === 'active' ? 'pass' : (in_array($state, ['revoked', 'reconnect_required'], true) ? 'fail' : 'warning');
                    ?>
                    <tr><td><strong><?php echo esc_html($client); ?></strong></td><td><?php echo esc_html($user ? $user->display_name . ' (' . $user->user_login . ')' : 'Unavailable Account #' . $user_id); ?></td>
                    <td><?php echo esc_html(implode('; ', $permissions)); ?><?php if (!empty($row['writes']) && empty($row['effective_writes']) || !empty($row['sensitive']) && empty($row['effective_sensitive']) || !empty($row['yolo']) && empty($row['effective_yolo'])) : ?><p class="description">Some approved access is currently restricted by site settings or account permissions.</p><?php endif; ?><?php if (!empty($row['effective_yolo'])) : ?><p class="description"><strong>YOLO Active:</strong> Supported changes can execute without dashboard approval.</p><?php elseif (!empty($row['effective_writes'])) : ?><p class="description">Dashboard approval is required for this connection. Reconnect once after enabling YOLO Mode to approve direct changes.</p><?php endif; ?></td>
                    <td><span class="fg-status fg-status-<?php echo esc_attr($badge); ?> fg-status-wrap"><?php echo esc_html($label); ?></span></td>
                    <td><details><summary>View Dates</summary><dl class="fg-connection-dates">
                        <?php foreach (['created'=>'Authorized On', 'tokens_issued_at'=>'Tokens First Issued', 'last_used'=>'Last Authenticated', 'expires'=>'Expires On'] as $key=>$title) : ?><dt><?php echo esc_html($title); ?></dt><dd><?php echo !empty($row[$key]) ? esc_html(wp_date('Y-m-d H:i T', (int) $row[$key])) : 'Not Recorded'; ?></dd><?php endforeach; ?>
                    </dl></details></td>
                    <td><?php if (!empty($row['can_delete'])) : ?><form class="fg-immediate-action" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_delete_connection"><input type="hidden" name="grant_id" value="<?php echo esc_attr((string) ($row['id'] ?? '')); ?>"><?php wp_nonce_field('fg_delete_connection'); ?><button class="button" type="submit" aria-label="<?php echo esc_attr('Delete inactive ' . $client . ' connection'); ?>">Delete</button></form>
                    <?php elseif (($row['status'] ?? '') !== 'revoked') : ?><form class="fg-immediate-action" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_revoke_connection"><input type="hidden" name="grant_id" value="<?php echo esc_attr((string) ($row['id'] ?? '')); ?>"><?php wp_nonce_field('fg_revoke_connection'); ?><button class="button" type="submit" aria-label="<?php echo esc_attr('Revoke ' . $client . ' connection'); ?>">Revoke</button></form><?php else : ?>—<?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <p class="description">Displays up to 100 retained connections. Active means the connection authenticated an MCP request; it does not mean a continuously open connection or successful tool execution. Client names are labels supplied during registration.</p>
            <?php endif; ?>
            <div class="fg-connection-actions">
                <form class="fg-immediate-action" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_clear_connections"><?php wp_nonce_field('fg_clear_connections'); ?><button class="button" type="submit">Clear Inactive Connections</button></form>
                <form class="fg-immediate-action" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_revoke_connection"><input type="hidden" name="revoke_all" value="1"><?php wp_nonce_field('fg_revoke_connection'); ?><button class="button" type="submit">Revoke All Connections</button></form>
            </div>
            <p class="description">Clear Inactive Connections removes revoked and expired records. Revoke All Connections disconnects clients and cancels unfinished sign-ins, while keeping their connection records. Activity Log entries are retained.</p>
            <details class="fg-advanced"><summary>Connection Maintenance</summary>
                <p>Revoke and clear all connections to disconnect every client, cancel unfinished OAuth sign-ins, and remove all connection records. MCP stays enabled. Clients must connect again; Activity Log entries are retained.</p>
                <form class="fg-immediate-action" data-fg-confirm="Disconnect every OAuth client and permanently clear all connection records? Unfinished sign-ins will be cancelled. Activity Log entries will remain." action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_clear_connections"><input type="hidden" name="clear_all" value="1"><?php wp_nonce_field('fg_clear_connections'); ?><button class="button" type="submit">Revoke And Clear All Connections</button></form>
            </details>
        </section>
        <?php
    }
    private static function checkbox(string $key, string $label, string $help, array $settings): void {
        echo '<div class="fg-access-control"><label for="fg-access-' . esc_attr($key) . '"><input id="fg-access-' . esc_attr($key) . '" type="checkbox" name="' . esc_attr($key) . '" value="1" aria-describedby="fg-help-' . esc_attr($key) . '" ' . checked(!empty($settings[$key]), true, false) . '> <strong>' . esc_html($label) . '</strong></label><p class="description" id="fg-help-' . esc_attr($key) . '">' . esc_html($help) . '</p></div>';
    }
    private static function change_mode(array $settings): string {
        return empty($settings['writes']) ? 'readonly' : (($settings['write_mode'] ?? 'reviewed') === 'yolo' ? 'yolo' : 'reviewed');
    }
    private static function change_mode_label(array $settings): string {
        return ['readonly'=>'Read Only', 'reviewed'=>'Reviewed Changes', 'yolo'=>'YOLO Mode (No Dashboard Approval)'][self::change_mode($settings)];
    }
    private static function form(array $settings): void {
        $version = FG_Settings::version_for('connection', $settings);
        $error = isset($_GET['settings_error']) ? get_transient('fg_connection_form_' . get_current_user_id()) : false;
        $form_settings = $settings;
        $origins = implode("\n", $settings['origins']);
        if (is_array($error)) {
            delete_transient('fg_connection_form_' . get_current_user_id());
            $input = is_array($error['input'] ?? null) ? $error['input'] : [];
            foreach (['oauth_enabled', 'writes', 'sensitive'] as $key) { if (array_key_exists($key, $input)) { $form_settings[$key] = (bool) $input[$key]; } }
            if (isset($input['write_mode']) && in_array($input['write_mode'], ['reviewed', 'yolo'], true)) { $form_settings['write_mode'] = $input['write_mode']; }
            if (is_string($input['origins'] ?? null)) { $origins = $input['origins']; }
        }
        ?>
        <section class="fg-card" id="fg-access">
            <h2>Access Controls</h2>
            <?php if (is_array($error)) : ?><div class="notice notice-error inline" role="alert"><p><?php echo esc_html($error['message']); ?></p></div>
                <?php if (!empty($error['input'])) : ?><details class="fg-advanced"><summary>Compare With Current Saved Settings</summary><p>The form below contains your unsaved input. These values are currently saved:</p><ul><li><strong>Change Mode:</strong> <?php echo esc_html(self::change_mode_label($settings)); ?></li><?php foreach (['oauth_enabled'=>'OAuth Sign-In', 'sensitive'=>'Order, Customer, Comment, and Financial Data Access'] as $key=>$label) : ?><li><strong><?php echo esc_html($label); ?>:</strong> <?php echo !empty($settings[$key]) ? 'Enabled' : 'Disabled'; ?></li><?php endforeach; ?><li><strong>Browser Origins:</strong> <?php echo esc_html(implode(', ', $settings['origins']) ?: 'None'); ?></li></ul></details><?php endif; ?>
            <?php endif; ?>
            <h3>Your Account</h3>
            <?php $user = wp_get_current_user(); $allowed = in_array((int) $user->ID, array_map('intval', $settings['users']), true); ?>
            <div class="fg-account-summary"><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($user->user_login); ?></span><span class="fg-status <?php echo $allowed ? 'fg-status-pass' : 'fg-status-warning'; ?>"><?php echo $allowed ? 'Account Enabled' : 'Account Not Enabled'; ?></span></div>
            <p class="description">Only explicitly enabled accounts can connect. Your WordPress and WooCommerce permissions continue to apply. MCP access is <?php echo empty($settings['enabled']) ? 'currently disabled for this site.' : ($allowed ? 'available to your account.' : 'not enabled for your account.'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="fg-settings-form">
                <input type="hidden" name="action" value="fg_save"><?php wp_nonce_field('fg_save'); ?>
                <input type="hidden" name="settings_version" value="<?php echo esc_attr($version); ?>">
                <fieldset id="fg-change-mode" class="fg-change-mode" aria-describedby="fg-change-mode-help">
                    <legend><h3>Change Mode</h3></legend>
                    <p class="description fg-mode-default">New setups start in Read Only. Choose another mode only when you want clients to make changes.</p>
                    <?php foreach ([
                        'readonly'=>['Read Only', 'Retrieve permitted content and data. Clients cannot create, update, or apply changes.'],
                        'reviewed'=>['Reviewed Changes', 'Clients propose edits. An administrator approves each request in WordPress before the requesting connection applies it. Requests expire after 15 minutes.'],
                        'yolo'=>['YOLO Mode (No Dashboard Approval)', 'Clients can execute supported changes immediately. No approval in the WordPress dashboard is required. WordPress permissions, data access controls, validation, and activity logging still apply.'],
                    ] as $value=>$option) : ?>
                        <div class="fg-mode-option"><label for="fg-mode-<?php echo esc_attr($value); ?>"><input id="fg-mode-<?php echo esc_attr($value); ?>" type="radio" name="change_mode" value="<?php echo esc_attr($value); ?>" aria-describedby="fg-mode-help-<?php echo esc_attr($value); ?>" <?php checked(self::change_mode($form_settings), $value); ?> required> <strong><?php echo esc_html($option[0]); ?></strong></label><p class="description" id="fg-mode-help-<?php echo esc_attr($value); ?>"><?php echo esc_html($option[1]); ?></p></div>
                    <?php endforeach; ?>
                    <p class="description" id="fg-change-mode-help">Save Access Controls to apply the selected mode. Existing OAuth connections keep their approved permissions. After enabling YOLO Mode, reconnect each client once and approve direct changes during sign-in. Previously approved reviewed connections continue to require dashboard approval until then. Your AI client may still ask for its own confirmation.</p>
                </fieldset>
                <?php self::checkbox('sensitive', 'Allow Order, Customer, Comment, And Financial Data Access', 'Allow access to these records and reports, subject to your WordPress and WooCommerce permissions. Leave this off when the connection only needs content access.', $form_settings); ?>
                <p class="description">Granting extra access requires reconnecting the client and approving the new access. Removing access takes effect immediately. Actual settings changes revoke unfinished change requests; saving unchanged values preserves them.</p>
                <details class="fg-advanced"><summary>Advanced Access</summary>
                    <?php self::checkbox('oauth_enabled', 'Enable OAuth Sign-In', 'Allow compatible clients to connect by signing in to WordPress and approving access. Turning this off revokes existing OAuth connections.', $form_settings); ?>
                    <h3>Local Bridge And Application Passwords</h3>
                    <p>For a local desktop or CLI client using the included bridge, create a WordPress Application Password on an enabled account's profile, then follow <code>bridge/README.md</code> in the source package.</p>
                    <p><a href="<?php echo esc_url(admin_url('profile.php')); ?>">Manage Your Application Passwords</a></p>
                    <p class="description">An Application Password inherits the account's WordPress permissions, including the standard REST API outside this gateway. Use an account with the permissions you intend to grant.</p>
                    <h3><label for="fg-origins">Allowed Browser Origins</label></h3><textarea id="fg-origins" name="origins" rows="3" class="large-text code" aria-describedby="fg-origins-help" maxlength="6000"><?php echo esc_textarea($origins); ?></textarea>
                    <p class="description" id="fg-origins-help">Usually leave blank for ChatGPT, Claude, and the local bridge. Add at most 20 exact HTTPS origins only when required by your client. Paths, trailing slashes, and wildcards are not supported. This setting is separate from OAuth redirect URLs.</p>
                </details>
                <?php submit_button('Save Access Controls'); ?>
            </form>
            <?php $additional_ids = array_values(array_diff(array_map('intval', $settings['users']), [(int) $user->ID])); if ($additional_ids) : ?>
                <details class="fg-advanced"><summary>Existing Account Access (<?php echo count($additional_ids); ?>)</summary>
                    <p>These accounts were explicitly enabled before this update. Their access has been preserved. Removing an account immediately stops its MCP access.</p>
                    <div class="fg-existing-accounts">
                    <?php foreach ($additional_ids as $id) : $existing = get_userdata($id); ?>
                        <div class="fg-account-row"><span><?php echo esc_html($existing ? $existing->display_name . ' (' . $existing->user_login . ')' : 'Unavailable Account #' . $id); ?></span><?php self::action_form('fg_remove_account', 'Remove Access', 'button', ['user_id'=>$id], $version); ?></div>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>
        <?php
    }
    private static function changes(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT id,user_id,tool,arguments,status,created,expires,server_context FROM ' . FG_Core::table('changes') . ' ORDER BY created DESC LIMIT 50', ARRAY_A);
        echo '<section class="fg-card"><h2>Changes And Review</h2><p>Reviewed changes require approval of the exact requested values before the requesting connection can apply them. YOLO changes run without dashboard approval and are shown here as history. Order changes can recalculate totals, affect stock, or trigger native emails.</p></section>';
        if (!$rows) { echo '<section class="fg-card"><p>No change requests yet.</p></section>'; }
        foreach ($rows as $row) {
            $context = json_decode($row['server_context'] ?? '', true);
            $yolo = is_array($context) && ($context['execution_mode'] ?? 'reviewed') === 'yolo';
            $pending = $row['status'] === 'pending' && time() < (int) $row['expires'];
            $expired = in_array($row['status'], ['pending', 'approved'], true) && time() >= (int) $row['expires'];
            echo '<section class="fg-card" data-fg-change-card><h3>' . esc_html($row['tool']) . ' <span class="fg-badge">' . esc_html($expired ? 'expired' : $row['status']) . '</span></h3>';
            echo '<p><strong>Change Mode:</strong> ' . ($yolo ? 'YOLO — No Dashboard Approval' : 'Reviewed Changes') . '</p>';
            echo '<p>Requested by user #' . (int) $row['user_id'] . ' · Expires ' . esc_html(gmdate('Y-m-d H:i:s', (int) $row['expires'])) . ' UTC</p><p><code>' . esc_html($row['id']) . '</code></p>';
            $args = json_decode($row['arguments'], true);
            if (class_exists('FG_Design_Review')) { FG_Design_Review::render($row); }
            echo '<details open><summary>Requested Values</summary><pre class="fg-json">' . esc_html(wp_json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
            if ($pending && !$yolo) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="fg_review"><input type="hidden" name="change_id" value="' . esc_attr($row['id']) . '">';
                wp_nonce_field('fg_review');
                if (FG_Approvals::is_design($row['tool']) && isset($args['blocks'])) { echo '<input type="hidden" name="design_validation" value="" data-fg-design-validation>'; }
                echo '<p><button class="button button-primary" data-fg-approve name="decision" value="approved">Approve These Values</button> <button class="button" name="decision" value="rejected">Reject</button></p></form>';
            }
            echo '</section>';
        }
    }
    private static function audit(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . FG_Core::table('audit') . ' ORDER BY id DESC LIMIT 100', ARRAY_A);
        echo '<section class="fg-card"><h2>Activity Log</h2><p>Latest 100 events. Metadata is retained for 30 days; credentials and response bodies are not logged. Cleanup runs with WordPress scheduled tasks. This is a local operational log, not a tamper-proof record.</p>';
        if ($wpdb->last_error !== '') { echo '<p class="notice notice-error inline">Activity could not be loaded. Reload this page to retry.</p></section>'; return; }
        echo '<div class="fg-table-scroll"><table class="widefat striped"><thead><tr><th>UTC Time</th><th>User</th><th>Client</th><th>Operation</th><th>Outcome</th><th>Change</th></tr></thead><tbody>';
        if (!$rows) { echo '<tr><td colspan="6">No activity recorded yet.</td></tr>'; }
        foreach ($rows as $row) {
            $source = $row['auth_source'] ?? '';
            $client = $source === 'wordpress_admin' ? 'WordPress Admin' : ($source === 'application_password' ? 'Application Password' : ($source === 'oauth' ? ($row['client_name'] ?: 'OAuth Client') : 'Not Recorded'));
            $outcomes = ['yolo_requested'=>'YOLO Requested', 'yolo_execution_started'=>'YOLO Execution Started', 'yolo_applied'=>'YOLO Applied', 'yolo_failed_check_site'=>'YOLO Failed — Check Site'];
            echo '<tr><td>' . esc_html(gmdate('Y-m-d H:i:s', (int) $row['created'])) . '</td><td>' . (int) $row['user_id'] . '</td><td>' . esc_html($client) . '</td><td>' . esc_html($row['tool']) . '</td><td>' . esc_html($outcomes[$row['outcome']] ?? $row['outcome']) . '</td><td><code>' . esc_html($row['change_id']) . '</code></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}
