<?php
/** First-party OAuth for remote MCP clients. Credentials are valid only at the MCP endpoint. */
defined('ABSPATH') || exit;

final class FG_OAuth {
    private const VERSION = '3';
    private const SCOPE = 'mcp';
    private const ACCESS_TTL = 3600;
    private const GRANT_TTL = 2592000;
    private const REQUEST_TTL = 300;

    public static function boot(): void {
        add_action('admin_post_fg_oauth_authorize', [self::class, 'authorize_page']);
        add_action('admin_post_nopriv_fg_oauth_authorize', [self::class, 'authorize_page']);
        add_action('parse_request', [self::class, 'well_known'], 0);
        // OAuth Basic credentials are client credentials, never WordPress Application Passwords.
        add_filter('application_password_is_api_request', static function ($is_api) {
            return self::is_client_endpoint() ? false : $is_api;
        }, 100);
    }
    private static function is_client_endpoint(): bool {
        global $wp;
        $allowed = ['/fames-mcp/v1/oauth/token', '/fames-mcp/v1/oauth/revoke'];
        // Prefer WordPress's resolved route; a URL-looking string cannot override it.
        if (isset($wp->query_vars['rest_route'])) {
            return is_string($wp->query_vars['rest_route']) && in_array('/' . ltrim($wp->query_vars['rest_route'], '/'), $allowed, true);
        }
        if (isset($_GET['rest_route'])) {
            return is_string($_GET['rest_route']) && in_array('/' . ltrim(wp_unslash($_GET['rest_route']), '/'), $allowed, true);
        }
        $request_path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        foreach (['token', 'revoke'] as $endpoint) {
            $url = rest_url('fames-mcp/v1/oauth/' . $endpoint);
            if (!wp_parse_url($url, PHP_URL_QUERY) && $request_path === wp_parse_url($url, PHP_URL_PATH)) { return true; }
        }
        return false;
    }
    public static function register(): void {
        foreach (['register' => 'register_client', 'token' => 'token', 'revoke' => 'revoke_token'] as $path => $callback) {
            register_rest_route('fames-mcp/v1', '/oauth/' . $path, ['methods' => 'POST', 'callback' => [self::class, $callback], 'permission_callback' => '__return_true']);
        }
        foreach (['authorization-server' => 'authorization_metadata', 'protected-resource' => 'resource_metadata'] as $path => $callback) {
            register_rest_route('fames-mcp/v1', '/oauth/' . $path, ['methods' => 'GET',
                'callback' => static function () use ($callback) { return self::response(self::$callback()); }, 'permission_callback' => '__return_true']);
        }
    }
    public static function resource(): string { return rest_url('fames-mcp/v1/mcp'); }
    // The JSON suffix lets hosts serve the corresponding public discovery file as JSON.
    // Keep one stable issuer regardless of whether discovery is served by WordPress or the host.
    public static function issuer(): string { return untrailingslashit(rest_url('fames-mcp/v1/oauth/issuer.json')); }
    public static function resource_metadata_url(): string { return rest_url('fames-mcp/v1/oauth/protected-resource'); }
    /** RFC 8414 discovery for the configured path-based issuer. */
    public static function authorization_metadata_urls(): array {
        $issuer = self::issuer(); $parts = wp_parse_url($issuer);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { return []; }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = (string) ($parts['path'] ?? '');
        return [$origin . '/.well-known/oauth-authorization-server' . $path];
    }
    public static function authorization_metadata(): array {
        return ['issuer' => self::issuer(),
            'authorization_endpoint' => add_query_arg('action', 'fg_oauth_authorize', admin_url('admin-post.php')),
            'token_endpoint' => rest_url('fames-mcp/v1/oauth/token'), 'registration_endpoint' => rest_url('fames-mcp/v1/oauth/register'),
            'revocation_endpoint' => rest_url('fames-mcp/v1/oauth/revoke'), 'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => [self::SCOPE],
            'authorization_response_iss_parameter_supported' => true];
    }
    public static function resource_metadata(): array {
        return ['resource' => self::resource(), 'authorization_servers' => [self::issuer()], 'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'], 'resource_name' => 'JalinWP'];
    }
    /** Host-root aliases remain available when the server routes them to WordPress. */
    public static function well_known(): void {
        $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $resource_path = (string) wp_parse_url(self::resource(), PHP_URL_PATH);
        $as = [$home . '/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server' . $home];
        foreach (self::authorization_metadata_urls() as $url) { $as[] = wp_parse_url($url, PHP_URL_PATH); }
        $pr = [$home . '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource' . $resource_path];
        $data = in_array($path, $as, true) ? self::authorization_metadata() : (in_array($path, $pr, true) ? self::resource_metadata() : null);
        if ($data === null) { return; }
        nocache_headers(); header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Methods: GET, OPTIONS'); header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { status_header(204); exit; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { status_header(405); header('Allow: GET, OPTIONS'); exit; }
        wp_send_json($data, 200);
    }
    private static function table(string $name): string { return FG_Core::table('oauth_' . $name); }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $schemas = [
            'clients' => "id char(36) NOT NULL, secret_hash char(64) NOT NULL DEFAULT '', name varchar(120) NOT NULL, auth_method varchar(32) NOT NULL, redirects text NOT NULL, authorized tinyint(1) NOT NULL DEFAULT 0, created bigint(20) unsigned NOT NULL, expires bigint(20) unsigned NOT NULL, PRIMARY KEY  (id), KEY expires (expires)",
            'requests' => "id char(36) NOT NULL, user_id bigint(20) unsigned NOT NULL, session_hash char(64) NOT NULL, parameters text NOT NULL, status varchar(16) NOT NULL, created bigint(20) unsigned NOT NULL, expires bigint(20) unsigned NOT NULL, PRIMARY KEY  (id), KEY user_expires (user_id,expires), KEY expires (expires)",
            'grants' => "id char(36) NOT NULL, client_id char(36) NOT NULL, user_id bigint(20) unsigned NOT NULL, user_hash char(64) NOT NULL, resource text NOT NULL, allow_writes tinyint(1) NOT NULL DEFAULT 0, allow_yolo tinyint(1) NOT NULL DEFAULT 0, allow_sensitive tinyint(1) NOT NULL DEFAULT 0, status varchar(16) NOT NULL, created bigint(20) unsigned NOT NULL, expires bigint(20) unsigned NOT NULL, last_used bigint(20) unsigned NOT NULL DEFAULT 0, consent_recorded_at bigint(20) unsigned DEFAULT NULL, tokens_issued_at bigint(20) unsigned DEFAULT NULL, first_authenticated_at bigint(20) unsigned DEFAULT NULL, PRIMARY KEY  (id), KEY user_status (user_id,status), KEY client_id (client_id), KEY expires (expires)",
            'codes' => "hash char(64) NOT NULL, grant_id char(36) NOT NULL, client_id char(36) NOT NULL, redirect_uri text NOT NULL, challenge varchar(128) NOT NULL, resource text NOT NULL, consumed tinyint(1) NOT NULL DEFAULT 0, expires bigint(20) unsigned NOT NULL, PRIMARY KEY  (hash), KEY grant_id (grant_id), KEY expires (expires)",
            'tokens' => "hash char(64) NOT NULL, grant_id char(36) NOT NULL, kind varchar(8) NOT NULL, status varchar(12) NOT NULL, expires bigint(20) unsigned NOT NULL, created bigint(20) unsigned NOT NULL, PRIMARY KEY  (hash), KEY grant_kind (grant_id,kind), KEY expires (expires)",
        ];
        foreach ($schemas as $name => $columns) {
            $columns = str_replace(', ', ",\n", $columns);
            dbDelta('CREATE TABLE ' . self::table($name) . " (\n$columns\n) $charset;");
        }
        $indexes = ['clients'=>['PRIMARY'=>['id'],'expires'=>['expires']], 'requests'=>['PRIMARY'=>['id'],'user_expires'=>['user_id','expires'],'expires'=>['expires']],
            'grants'=>['PRIMARY'=>['id'],'user_status'=>['user_id','status'],'client_id'=>['client_id'],'expires'=>['expires']],
            'codes'=>['PRIMARY'=>['hash'],'grant_id'=>['grant_id'],'expires'=>['expires']], 'tokens'=>['PRIMARY'=>['hash'],'grant_kind'=>['grant_id','kind'],'expires'=>['expires']]];
        foreach ($schemas as $name => $definition) {
            $columns = [];
            foreach (explode(', ', $definition) as $column) {
                if (preg_match('/^([a-z_]+) /', $column, $match)) { $columns[] = $match[1]; }
            }
            if (!FG_Core::schema_ready(self::table($name), $columns, $indexes[$name])) { return; }
        }
        add_option('fg_oauth_epoch', wp_generate_uuid4(), '', false);
        if (self::epoch() === '') { return; }
        update_option('fg_oauth_schema', self::VERSION, false);
    }
    public static function maybe_upgrade(): void { if (get_option('fg_oauth_schema') !== self::VERSION) { self::install(); } }
    public static function cleanup(): void {
        global $wpdb;
        self::prune_clients(false);
        foreach (['requests', 'codes', 'tokens', 'grants', 'clients'] as $name) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table($name) . ' WHERE expires > 0 AND expires < %d', time()));
        }
    }
    public static function revoke_all(): bool {
        global $wpdb;
        // Rotate first: even an in-flight consent/token request cannot recreate a usable old grant.
        $epoch = wp_generate_uuid4();
        $rotated = update_option('fg_oauth_epoch', $epoch, false);
        $grants = $wpdb->query('UPDATE ' . self::table('grants') . " SET status='revoked' WHERE status='active'");
        $requests = $wpdb->query('UPDATE ' . self::table('requests') . " SET status='revoked' WHERE status='pending'");
        return $rotated && $grants !== false && $requests !== false;
    }
    private static function epoch(): string {
        global $wpdb;
        // Bypass the per-request option cache so concurrent disconnect-all is observed immediately.
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'fg_oauth_epoch'));
        return is_string($value) && wp_is_uuid($value) ? $value : '';
    }
    public static function revoke(string $grant_id): bool {
        global $wpdb;
        return false !== $wpdb->update(self::table('grants'), ['status' => 'revoked'], ['id' => $grant_id], ['%s'], ['%s']);
    }
    /** Remove a terminal connection, never one that could become usable again. */
    public static function delete_connection(string $grant_id): array|WP_Error {
        if (!wp_is_uuid($grant_id)) { return new WP_Error('fg_oauth_connection_id', 'Choose a valid connection.', ['status'=>400,'deleted'=>0]); }
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $table = self::table('grants');
            // Permanently fence expired grants before touching credentials. A disabled
            // gateway, removed account, or missing client alone is not a terminal grant.
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='revoked' WHERE id=%s AND (status='revoked' OR expires<=%d)", $grant_id, time()));
            if ($claimed === false || $wpdb->last_error !== '') { return self::cleanup_error(); }
            $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM $table WHERE id=%s", $grant_id), ARRAY_A);
            if ($wpdb->last_error !== '') { return self::cleanup_error(); }
            if (!$row) { return ['deleted'=>0]; } // Already removed by another cleanup.
            if ($row['status'] !== 'revoked') {
                return new WP_Error('fg_oauth_connection_active', 'Revoke this connection before deleting it. Its access may still be usable.', ['status'=>409,'deleted'=>0]);
            }
            // Retain the revoked grant if a dependent delete fails, so a retry can
            // finish cleanup. In-flight token issuance cannot authorize without it.
            foreach (['tokens','codes'] as $name) {
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table($name) . ' WHERE grant_id=%s', $grant_id));
                if ($deleted === false || $wpdb->last_error !== '') { return self::cleanup_error(); }
            }
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id=%s AND status='revoked'", $grant_id));
            if ($deleted === false || $wpdb->last_error !== '') { return self::cleanup_error(); }
            return ['deleted'=>(int) $deleted];
        } finally { $wpdb->suppress_errors($suppressed); }
    }
    /** Clear inactive history, or explicitly disconnect everyone before clearing it. */
    public static function clear_connections(bool $all = false): array|WP_Error {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        try {
            if ($all && !self::revoke_all()) {
                return new WP_Error('fg_oauth_connections_revoke', 'Connections could not all be revoked. No connection records were deleted. Refresh and retry; some connections may already be revoked.', ['status'=>503,'deleted'=>0]);
            }
            // Snapshot all terminal records, independently of the 100-row display.
            // Newly authorized active grants after revoke_all() are not selected.
            $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table('grants') . " WHERE status='revoked' OR expires<=%d ORDER BY id", time()));
            if ($wpdb->last_error !== '' || !is_array($ids)) { return self::cleanup_error(); }
            $deleted = 0; $failed = 0;
            foreach ($ids as $id) {
                $result = self::delete_connection((string) $id);
                if (is_wp_error($result)) { $failed++; }
                else { $deleted += $result['deleted']; }
            }
            if ($failed) {
                return new WP_Error('fg_oauth_connections_cleanup', sprintf('%d connection record(s) deleted; %d could not be cleared. Refresh and retry.', $deleted, $failed), ['status'=>503,'deleted'=>$deleted,'failed'=>$failed]);
            }
            return ['deleted'=>$deleted];
        } finally { $wpdb->suppress_errors($suppressed); }
    }
    private static function cleanup_error(): WP_Error {
        return new WP_Error('fg_oauth_connections_cleanup', 'Connection records could not be cleared. Refresh and retry.', ['status'=>503,'deleted'=>0]);
    }
    /** Retained authorization records. A query error must never masquerade as an empty list. */
    public static function connections(): array|WP_Error {
        global $wpdb;
        $g = self::table('grants'); $c = self::table('clients'); $t = self::table('tokens');
        // SENSITIVE is reserved by MySQL. Select storage names; present friendlier keys in PHP.
        $suppressed = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results("SELECT g.*,c.id AS registered_client_id,c.name AS client_name,c.expires AS client_expires,
            (SELECT MIN(t.created) FROM $t t WHERE t.grant_id=g.id) AS retained_token_created
            FROM $g g LEFT JOIN $c c ON c.id=g.client_id ORDER BY g.created DESC,g.id DESC LIMIT 100", ARRAY_A);
        $failed = $wpdb->last_error !== '' || !is_array($rows);
        $wpdb->suppress_errors($suppressed);
        if ($failed) { return new WP_Error('fg_oauth_connections_storage', 'Connections Could Not Be Loaded. Refresh this page to retry.', ['status'=>503]); }
        $settings = FG_Core::fresh_settings();
        if (is_wp_error($settings)) { return $settings; }
        $result = [];
        foreach ($rows as $row) {
            $client = empty($row['registered_client_id']) ? null : ['id'=>$row['registered_client_id'],'expires'=>$row['client_expires']];
            $valid = !empty($settings['enabled']) && !empty($settings['oauth_enabled']) && self::grant_valid($row, $client);
            if ($row['status'] === 'revoked') { $state = 'revoked'; $label = 'Revoked'; }
            elseif ((int) $row['expires'] <= time()) { $state = 'expired'; $label = 'Expired'; }
            elseif (!$valid) { $state = 'reconnect_required'; $label = 'Reconnect Required'; }
            elseif ((int) $row['last_used'] > 0 || !empty($row['first_authenticated_at'])) { $state = 'active'; $label = 'Active'; }
            elseif (!empty($row['tokens_issued_at']) || !empty($row['retained_token_created'])) { $state = 'authorized'; $label = 'Authorized — Awaiting First Request'; }
            elseif (!empty($row['consent_recorded_at'])) { $state = 'approval_received'; $label = 'Approval Received — Finish Connecting'; }
            else { $state = 'authorization_recorded'; $label = 'Authorization Recorded'; }
            $context = FG_Core::normalize_audit_context(['auth_source'=>'oauth','oauth_client_id'=>$row['client_id'],'oauth_grant_id'=>$row['id'],'client_name'=>$row['client_name'] ?? 'Unavailable Client']);
            $result[] = ['id'=>$row['id'],'client_id'=>$row['client_id'],'user_id'=>(int) $row['user_id'],'status'=>$row['status'],
                'created'=>(int) $row['created'],'expires'=>(int) $row['expires'],'last_used'=>(int) $row['last_used'],
                'consent_recorded_at'=>isset($row['consent_recorded_at']) ? (int) $row['consent_recorded_at'] : null,
                'tokens_issued_at'=>isset($row['tokens_issued_at']) ? (int) $row['tokens_issued_at'] : null,
                'first_authenticated_at'=>isset($row['first_authenticated_at']) ? (int) $row['first_authenticated_at'] : null,
                'writes'=>(bool) $row['allow_writes'],'sensitive'=>(bool) $row['allow_sensitive'],'yolo'=>!empty($row['allow_yolo']),
                'effective_writes'=>$valid && !empty($row['allow_writes']) && !empty($settings['writes']),
                'effective_sensitive'=>$valid && !empty($row['allow_sensitive']) && !empty($settings['sensitive']),
                'effective_yolo'=>$valid && !empty($row['allow_writes']) && !empty($row['allow_yolo']) && !empty($settings['writes']) && ($settings['write_mode'] ?? 'reviewed') === 'yolo',
                'client_name'=>$context['client_name'] ?: 'Unavailable Client', 'lifecycle_status'=>$state,'lifecycle_label'=>$label,
                'can_delete'=>$row['status'] === 'revoked' || (int) $row['expires'] <= time()];
        }
        return $result;
    }
    private static function ready(): bool {
        $s = FG_Core::fresh_settings();
        if (is_wp_error($s)) { return false; }
        return is_ssl() && !empty($s['enabled']) && !empty($s['oauth_enabled']) && get_option('fg_oauth_schema') === self::VERSION && self::epoch() !== '' && self::secure_urls();
    }
    private static function secure_urls(): bool {
        // OAuth issuer identifiers cannot contain a query. Plain WordPress REST URLs
        // (?rest_route=...) cannot provide the required path-based discovery location.
        if (self::authorization_metadata_urls() === []) { return false; }
        foreach ([self::issuer(), self::resource(), admin_url('admin-post.php'), rest_url('fames-mcp/v1/oauth/token')] as $url) {
            $parts = wp_parse_url($url);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { return false; }
        }
        return true;
    }
    private static function user_allowed(int $id): bool {
        $s = FG_Core::fresh_settings();
        if (is_wp_error($s)) { return false; }
        $user = get_userdata($id);
        return $user && in_array($id, array_map('intval', $s['users']), true) && is_user_member_of_blog($id) && user_can($user, 'read');
    }
    private static function user_hash(int $id, ?string $epoch = null): string {
        $user = get_userdata($id);
        return $user ? hash_hmac('sha256', $id . '|' . $user->user_pass . '|' . ($epoch ?? self::epoch()), wp_salt('auth')) : '';
    }
    private static function secret(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private static function hash(string $value): string { return hash('sha256', $value); }
    private static function error(string $code, string $message, int $status = 400): WP_REST_Response {
        return self::response(['error' => $code, 'error_description' => $message], $status);
    }
    private static function response(array $data, int $status = 200): WP_REST_Response {
        return new WP_REST_Response($data, $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
    private static function limit(string $purpose, int $maximum, int $window, bool $global = false): bool {
        global $wpdb;
        $identity = $global ? 'global' : (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucket = 'oauth:' . substr(self::hash($purpose . '|' . $identity), 0, 40) . ':' . intdiv(time(), $window);
        $t = FG_Core::table('limits');
        $ok = $wpdb->query($wpdb->prepare("INSERT INTO $t (bucket,hits,expires) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1", $bucket, time() + $window + 60));
        if ($ok === false) { return false; }
        $hits = $wpdb->get_var($wpdb->prepare("SELECT hits FROM $t WHERE bucket=%s", $bucket));
        return is_numeric($hits) && (int) $hits > 0 && (int) $hits <= $maximum;
    }
    /** Keep established registrations; discard only abandoned, never-consented registrations. */
    private static function prune_clients(bool $pressure): void {
        global $wpdb;
        $cutoff = time() - ($pressure ? HOUR_IN_SECONDS : DAY_IN_SECONDS);
        $limit = $pressure ? 10 : 100;
        $c = self::table('clients'); $r = self::table('requests');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $c WHERE authorized=0 AND created<%d ORDER BY created ASC LIMIT %d", $cutoff, $limit));
        foreach (is_array($ids) ? $ids : [] as $id) {
            // Do not interrupt an open consent form (requests are short-lived and bounded).
            $pattern = '%' . $wpdb->esc_like('"client_id":"' . $id . '"') . '%';
            $pending = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $r WHERE status='pending' AND expires>%d AND parameters LIKE %s", time(), $pattern));
            if (!is_numeric($pending) || (int) $pending !== 0) { continue; }
            $wpdb->query($wpdb->prepare("DELETE FROM $c WHERE id=%s AND authorized=0", $id));
        }
    }
    private static function room(string $table, int $maximum): bool {
        global $wpdb;
        $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table($table));
        return is_numeric($count) && (int) $count < $maximum;
    }
    public static function valid_redirect(string $uri): bool {
        if (strlen($uri) > 512) { return false; }
        if ($uri === 'https://chatgpt.com/connector_platform_oauth_redirect') { return true; }
        // Claude's hosted web, Desktop, mobile and Cowork clients share this exact callback.
        if ($uri === 'https://claude.ai/api/mcp/auth_callback') { return true; }
        return (bool) preg_match('~\Ahttps://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]{1,160}\z~D', $uri);
    }
    /** Permit consent POST redirects only to this request's validated client origin. */
    private static function consent_policy(string $redirect = ''): string {
        $destination = self::valid_redirect($redirect) ? ' https://' . wp_parse_url($redirect, PHP_URL_HOST) : '';
        return "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'" . $destination . "; frame-ancestors 'none'; base-uri 'none'";
    }
    public static function register_client(WP_REST_Request $request): WP_REST_Response {
        if (!self::ready()) { return self::error('temporarily_unavailable', 'Enable the gateway and OAuth sign-in in WordPress over HTTPS.', 503); }
        if (!self::limit('registration', 20, 3600) || !self::limit('registration', 100, 3600, true)) { return self::error('temporarily_unavailable', 'Registration rate limit reached. Try later.', 429); }
        if (strlen($request->get_body()) > 16384 || stripos((string) $request->get_header('content-type'), 'application/json') !== 0) { return self::error('invalid_client_metadata', 'Send a JSON object smaller than 16 KiB.'); }
        $data = json_decode($request->get_body(), true);
        if (!is_array($data) || !is_object(json_decode($request->get_body()))) { return self::error('invalid_client_metadata', 'A JSON object is required.'); }
        $redirects = $data['redirect_uris'] ?? null;
        if (!is_array($redirects) || !$redirects || count($redirects) > 5 || !array_is_list($redirects)) { return self::error('invalid_redirect_uri', 'Register one to five exact supported callback URLs.'); }
        foreach ($redirects as $uri) { if (!is_string($uri) || !self::valid_redirect($uri)) { return self::error('invalid_redirect_uri', 'Only the supported HTTPS ChatGPT and Claude callbacks are allowed.'); } }
        $method = $data['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (!is_string($method) || !in_array($method, ['none', 'client_secret_basic', 'client_secret_post'], true)) { return self::error('invalid_client_metadata', 'Unsupported token authentication method.'); }
        $grants = $data['grant_types'] ?? ['authorization_code'];
        if (!is_array($grants) || !array_is_list($grants) || !in_array('authorization_code', $grants, true)) { return self::error('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.'); }
        foreach ($grants as $grant) { if (!is_string($grant) || !in_array($grant, ['authorization_code', 'refresh_token'], true)) { return self::error('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.'); } }
        if (isset($data['response_types']) && $data['response_types'] !== ['code']) { return self::error('invalid_client_metadata', 'Only the code response type is supported.'); }
        if (isset($data['scope']) && $data['scope'] !== self::SCOPE) { return self::error('invalid_client_metadata', 'The only supported scope is mcp.'); }
        if (isset($data['client_name']) && (!is_string($data['client_name']) || strlen($data['client_name']) > 120)) { return self::error('invalid_client_metadata', 'Client name must be at most 120 bytes.'); }
        if (!self::room('clients', 500)) { self::prune_clients(true); }
        if (!self::room('clients', 500)) { return self::error('temporarily_unavailable', 'Client storage limit reached. Ask the site administrator.', 503); }
        $id = wp_generate_uuid4(); $secret = $method === 'none' ? '' : self::secret(); $now = time();
        $name = sanitize_text_field($data['client_name'] ?? 'MCP client');
        global $wpdb;
        $ok = $wpdb->insert(self::table('clients'), ['id' => $id, 'secret_hash' => $secret === '' ? '' : self::hash($secret), 'name' => $name ?: 'MCP client',
            'auth_method' => $method, 'redirects' => wp_json_encode(array_values(array_unique($redirects))), 'created' => $now, 'expires' => 0]);
        if ($ok === false) { return self::error('temporarily_unavailable', 'Client registration could not be saved.', 503); }
        $result = ['client_id' => $id, 'client_id_issued_at' => $now, 'client_name' => $name ?: 'MCP client', 'redirect_uris' => array_values(array_unique($redirects)),
            'token_endpoint_auth_method' => $method, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'scope' => self::SCOPE];
        if ($secret !== '') { $result['client_secret'] = $secret; $result['client_secret_expires_at'] = 0; }
        return self::response($result, 201);
    }
    private static function client(string $id): ?array {
        global $wpdb;
        if (!wp_is_uuid($id)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('clients') . ' WHERE id=%s AND (expires=0 OR expires>%d)', $id, time()), ARRAY_A);
        return is_array($row) ? $row : null;
    }
    public static function authorization_request(array $parameters) {
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'state', 'scope'] as $key) {
            if (isset($parameters[$key]) && !is_string($parameters[$key])) { return new WP_Error('invalid_request', 'Authorization fields must be strings.'); }
        }
        $client = self::client($parameters['client_id'] ?? ''); $redirect = $parameters['redirect_uri'] ?? '';
        if (!$client || !in_array($redirect, json_decode($client['redirects'], true) ?: [], true)) { return new WP_Error('invalid_request', 'This client or callback is not registered. Re-create the connection in your MCP client.'); }
        if (($parameters['response_type'] ?? '') !== 'code' || ($parameters['resource'] ?? '') !== self::resource() || ($parameters['scope'] ?? self::SCOPE) !== self::SCOPE) { return new WP_Error('invalid_request', 'Use response_type=code, scope=mcp and the exact MCP URL as resource.'); }
        if (($parameters['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $parameters['code_challenge'] ?? '')) { return new WP_Error('invalid_request', 'PKCE with an S256 code challenge is required.'); }
        if (strlen($parameters['state'] ?? '') > 2048) { return new WP_Error('invalid_request', 'State exceeds the supported size.'); }
        return ['client_id' => $client['id'], 'client_name' => $client['name'], 'redirect_uri' => $redirect,
            'code_challenge' => $parameters['code_challenge'], 'resource' => self::resource(), 'scope' => self::SCOPE, 'state' => $parameters['state'] ?? ''];
    }
    public static function begin_consent(array $parameters) {
        if (!self::ready() || !self::user_allowed(get_current_user_id())) { return new WP_Error('access_denied', 'Your WordPress account is not enabled for this gateway. Ask the site administrator.'); }
        $valid = self::authorization_request($parameters);
        if (is_wp_error($valid)) { return $valid; }
        if (!self::limit('consent', 30, 60) || !self::room('requests', 500)) { return new WP_Error('temporarily_unavailable', 'Too many connection requests. Wait a few minutes.'); }
        $session = wp_get_session_token();
        if ($session === '') { return new WP_Error('access_denied', 'Sign in to WordPress in this browser first.'); }
        $s = FG_Core::fresh_settings();
        if (is_wp_error($s)) { return $s; }
        $valid['allow_writes'] = !empty($s['writes']);
        $valid['allow_sensitive'] = !empty($s['sensitive']);
        $valid['allow_yolo'] = !empty($s['writes']) && ($s['write_mode'] ?? 'reviewed') === 'yolo';
        $valid['epoch'] = self::epoch();
        $id = wp_generate_uuid4();
        global $wpdb;
        $ok = $wpdb->insert(self::table('requests'), ['id' => $id, 'user_id' => get_current_user_id(), 'session_hash' => self::hash($session),
            'parameters' => wp_json_encode($valid), 'status' => 'pending', 'created' => time(), 'expires' => time() + self::REQUEST_TTL]);
        return $ok === false ? new WP_Error('temporarily_unavailable', 'The connection request could not be saved.') : ['id' => $id, 'request' => $valid];
    }
    /** The browser handler validates the WP nonce; this helper independently enforces session ownership and one-use. */
    public static function complete_consent(string $id, bool $allow) {
        global $wpdb;
        if (!self::ready() || !self::user_allowed(get_current_user_id()) || !wp_is_uuid($id)) { return new WP_Error('access_denied', 'The connection is unavailable.'); }
        $t = self::table('requests');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%s AND user_id=%d AND status='pending' AND expires>%d", $id, get_current_user_id(), time()), ARRAY_A);
        $session = wp_get_session_token();
        if (!$row || $session === '' || !hash_equals($row['session_hash'], self::hash($session))) { return new WP_Error('invalid_request', 'This connection request expired or was already used. Connect again from your MCP client.'); }
        $p = json_decode($row['parameters'], true);
        if (!is_array($p) || !self::client($p['client_id']) || !self::valid_redirect($p['redirect_uri']) || $p['resource'] !== self::resource() || !isset($p['epoch']) || !hash_equals(self::epoch(), $p['epoch'])) { return new WP_Error('invalid_request', 'The connection request is no longer valid.'); }
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $t SET status='consumed' WHERE id=%s AND status='pending' AND user_id=%d AND session_hash=%s AND expires>%d", $id, get_current_user_id(), self::hash($session), time()));
        if ($claimed !== 1) { return new WP_Error('invalid_request', 'This connection request was already used or could not be saved.'); }
        $out = ['iss' => self::issuer()]; if ($p['state'] !== '') { $out['state'] = $p['state']; }
        if (!$allow) { $out['error'] = 'access_denied'; return add_query_arg($out, $p['redirect_uri']); }
        if (!self::room('grants', 500) || !self::room('codes', 1000)) { return new WP_Error('temporarily_unavailable', 'Connection storage limit reached. Ask the administrator.'); }
        $grant_id = wp_generate_uuid4(); $code = self::secret(); $now = time(); $s = FG_Core::fresh_settings();
        if (is_wp_error($s)) { return $s; }
        $ok = $wpdb->insert(self::table('grants'), ['id' => $grant_id, 'client_id' => $p['client_id'], 'user_id' => get_current_user_id(),
            'user_hash' => self::user_hash(get_current_user_id(), $p['epoch']), 'resource' => self::resource(), 'allow_writes' => !empty($p['allow_writes']) && !empty($s['writes']) ? 1 : 0,
            'allow_yolo' => !empty($p['allow_writes']) && !empty($p['allow_yolo']) && !empty($s['writes']) && ($s['write_mode'] ?? 'reviewed') === 'yolo' ? 1 : 0,
            'allow_sensitive' => !empty($p['allow_sensitive']) && !empty($s['sensitive']) ? 1 : 0, 'status' => 'active', 'created' => $now, 'expires' => $now + self::GRANT_TTL, 'last_used' => 0, 'consent_recorded_at' => $now]);
        if ($ok === false) { return new WP_Error('temporarily_unavailable', 'The connection could not be saved.'); }
        $ok = $wpdb->insert(self::table('codes'), ['hash' => self::hash($code), 'grant_id' => $grant_id, 'client_id' => $p['client_id'],
            'redirect_uri' => $p['redirect_uri'], 'challenge' => $p['code_challenge'], 'resource' => self::resource(), 'consumed' => 0, 'expires' => $now + self::REQUEST_TTL]);
        if ($ok === false) { self::revoke($grant_id); return new WP_Error('temporarily_unavailable', 'The authorization code could not be saved.'); }
        $client_saved = $wpdb->update(self::table('clients'), ['authorized' => 1], ['id' => $p['client_id']], ['%d'], ['%s']);
        if ($client_saved === false) { self::revoke($grant_id); return new WP_Error('temporarily_unavailable', 'The client connection could not be saved.'); }
        if (!self::active_grant($grant_id)) { self::revoke($grant_id); return new WP_Error('access_denied', 'The connection was revoked while approval was in progress. Connect again.'); }
        $out['code'] = $code; return add_query_arg($out, $p['redirect_uri']);
    }
    public static function authorize_page(): void {
        nocache_headers(); header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: ' . self::consent_policy());
        header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
        if (!self::ready()) { wp_die('OAuth sign-in is disabled. Ask the WordPress administrator to enable it in Settings → JalinWP.', 'Connection Unavailable', ['response' => 403]); }
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST') {
            $id = isset($_POST['request_id']) && is_string($_POST['request_id']) ? wp_unslash($_POST['request_id']) : '';
            $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? wp_unslash($_POST['_wpnonce']) : '';
            if (!is_user_logged_in() || !wp_verify_nonce($nonce, 'fg_oauth_consent_' . $id)) { wp_die('This approval expired. Return to your MCP client and connect again.', 'Connection Expired', ['response' => 403]); }
            $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? wp_unslash($_POST['decision']) : '';
            if (!in_array($decision, ['allow', 'deny'], true)) { wp_die('Choose Allow or Cancel.', 'Invalid Request', ['response' => 400]); }
            $result = self::complete_consent($id, $decision === 'allow');
            if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), 'Connection Failed', ['response' => 400]); }
            // complete_consent appends only OAuth response fields to a stored, validated callback.
            header('Content-Security-Policy: ' . self::consent_policy(explode('?', $result, 2)[0]));
            wp_redirect($result, 302, 'JalinWP'); exit;
        }
        if ($method !== 'GET') { wp_die('Only GET and POST are supported.', 'Invalid Method', ['response' => 405]); }
        $params = wp_unslash($_GET); $valid = self::authorization_request($params);
        if (is_wp_error($valid)) { wp_die(esc_html($valid->get_error_message()), 'Connection Failed', ['response' => 400]); }
        header('Content-Security-Policy: ' . self::consent_policy($valid['redirect_uri']));
        if (!is_user_logged_in()) {
            $login_return = add_query_arg(array_merge(['action' => 'fg_oauth_authorize'], array_intersect_key($params, array_flip(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'state', 'scope']))), admin_url('admin-post.php'));
            wp_safe_redirect(wp_login_url($login_return), 302, 'JalinWP'); exit;
        }
        $consent = self::begin_consent($params);
        if (is_wp_error($consent)) { wp_die(esc_html($consent->get_error_message()), 'Connection Failed', ['response' => 403]); }
        $user = wp_get_current_user(); $s = ['writes' => $consent['request']['allow_writes'], 'sensitive' => $consent['request']['allow_sensitive'], 'yolo' => $consent['request']['allow_yolo']];
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Approve Connection · JalinWP</title>
        <link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url(plugins_url('assets/brand/favicon-32.png', FG_FILE)); ?>">
        <style><?php
            // Trusted, bundled CSS keeps consent independent of external styles and fonts.
            foreach (['brand.css', 'oauth.css'] as $stylesheet) { readfile(dirname(__DIR__) . '/assets/' . $stylesheet); }
        ?></style></head><body class="jalinwp-oauth"><main aria-labelledby="jalinwp-consent-title">
        <header class="jalinwp-oauth-header"><img class="jalinwp-oauth-logo" src="<?php echo esc_url(plugins_url('assets/brand/jalinwp-logo-horizontal-white.png', FG_FILE)); ?>" alt="JalinWP" width="240" height="80"><p class="jalinwp-oauth-eyebrow">WordPress Connection</p><h1 id="jalinwp-consent-title">Connect Your WordPress Site</h1><p>Review the site, client, and access below before allowing this connection.</p></header>
        <dl class="jalinwp-oauth-details"><div><dt>Requesting Client</dt><dd><?php echo esc_html($consent['request']['client_name']); ?></dd></div><div><dt>WordPress Site</dt><dd><?php echo esc_html(home_url('/')); ?></dd></div><div><dt>Signed In As</dt><dd><?php echo esc_html($user->display_name); ?> <span>(<?php echo esc_html($user->user_login); ?>)</span></dd></div></dl>
        <section aria-labelledby="jalinwp-access-title"><h2 id="jalinwp-access-title">Connection Access</h2><ul><li>Read site data your WordPress account is permitted to access.</li>
        <?php if (!empty($s['sensitive'])) : ?><li>Read customer, order and sales information, including personal customer details.</li><?php endif; ?>
        <?php if (!empty($s['writes']) && !empty($s['yolo'])) : ?><li class="jalinwp-oauth-direct"><strong>Direct (YOLO) Mode:</strong> Create, edit and perform permitted changes immediately, without approval in the WordPress dashboard. Your WordPress permissions and enabled data access still apply.</li><?php elseif (!empty($s['writes'])) : ?><li>Propose changes. Every change still needs approval in WordPress before it can run.</li><?php else : ?><li>This connection has read-only access.</li><?php endif; ?>
        </ul></section><p class="jalinwp-oauth-expiry">The connection expires in 30 days. You can disconnect it at any time from the JalinWP settings.</p>
        <form class="jalinwp-oauth-actions" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="fg_oauth_authorize"><input type="hidden" name="request_id" value="<?php echo esc_attr($consent['id']); ?>"><?php wp_nonce_field('fg_oauth_consent_' . $consent['id']); ?><button class="allow" type="submit" name="decision" value="allow">Allow Connection</button><button type="submit" name="decision" value="deny">Cancel</button></form>
        <footer>Only allow a connection you just started in your MCP client. Your WordPress password stays on this website.</footer></main></body></html><?php
        exit;
    }
    private static function form(WP_REST_Request $request) {
        if (strlen($request->get_body()) > 16384 || stripos((string) $request->get_header('content-type'), 'application/x-www-form-urlencoded') !== 0) { return new WP_Error('invalid_request', 'Send an application/x-www-form-urlencoded body smaller than 16 KiB.'); }
        $values = []; $seen = [];
        foreach (explode('&', $request->get_body()) as $part) {
            if ($part === '') { continue; }
            $pair = explode('=', $part, 2); $key = urldecode($pair[0]); $value = urldecode($pair[1] ?? '');
            if (!preg_match('/\A[a-z_]+\z/D', $key) || isset($seen[$key])) { return new WP_Error('invalid_request', 'Duplicate or malformed form parameters are not supported.'); }
            $seen[$key] = true; $values[$key] = $value;
        }
        return $values;
    }
    private static function authenticate_client(WP_REST_Request $request, array $p) {
        $authorization = (string) $request->get_header('authorization'); $basic = false; $secret = ''; $id = $p['client_id'] ?? '';
        // Some Apache/PHP setups expose decoded Basic credentials without the original header.
        if ($authorization === '' && isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && is_string($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_PW'])) {
            $authorization = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        if ($authorization !== '') {
            if (stripos($authorization, 'Basic ') !== 0 || isset($p['client_secret'])) { return new WP_Error('invalid_client', 'Use one registered client authentication method.'); }
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded === false || strpos($decoded, ':') === false) { return new WP_Error('invalid_client', 'Client authentication failed.'); }
            [$basic_id, $encoded_secret] = explode(':', $decoded, 2); $basic_id = urldecode($basic_id); $secret = urldecode($encoded_secret);
            if ($id !== '' && $id !== $basic_id) { return new WP_Error('invalid_client', 'Client IDs do not match.'); }
            $id = $basic_id; $basic = true;
        }
        $client = self::client($id);
        if (!$client) { return new WP_Error('invalid_client', 'Client authentication failed. Re-create this connection.'); }
        $method = $client['auth_method'];
        if ($method === 'none') {
            if ($basic || isset($p['client_secret'])) { return new WP_Error('invalid_client', 'This client uses no client secret.'); }
        } else {
            if (($method === 'client_secret_basic') !== $basic) { return new WP_Error('invalid_client', 'Use the registered client authentication method.'); }
            if (!$basic) { $secret = $p['client_secret'] ?? ''; }
            if ($secret === '' || !hash_equals($client['secret_hash'], self::hash($secret))) { return new WP_Error('invalid_client', 'Client authentication failed.'); }
        }
        return $client;
    }
    private static function active_grant(string $id): ?array {
        global $wpdb;
        $g = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('grants') . " WHERE id=%s AND status='active' AND expires>%d", $id, time()), ARRAY_A);
        if (!$g || !self::grant_valid($g, self::client($g['client_id']))) { return null; }
        return $g;
    }
    /** Shared, read-only validity checks for authentication and admin lifecycle projection. */
    private static function grant_valid(array $g, ?array $client): bool {
        return $g['status'] === 'active' && (int) $g['expires'] > time() && $g['resource'] === self::resource() && $client &&
            ((int) $client['expires'] === 0 || (int) $client['expires'] > time()) &&
            self::user_allowed((int) $g['user_id']) && hash_equals($g['user_hash'], self::user_hash((int) $g['user_id']));
    }
    public static function token(WP_REST_Request $request): WP_REST_Response {
        if (!self::ready()) { return self::error('temporarily_unavailable', 'The connection service is disabled or requires HTTPS.', 503); }
        if (!self::limit('token', 120, 60) || !self::limit('token', 600, 60, true)) { return self::error('temporarily_unavailable', 'Too many token requests. Try later.', 429); }
        $p = self::form($request);
        if (is_wp_error($p)) { return self::error($p->get_error_code(), $p->get_error_message()); }
        $client = self::authenticate_client($request, $p);
        if (is_wp_error($client)) {
            $response = self::error('invalid_client', $client->get_error_message(), 401); $response->header('WWW-Authenticate', 'Basic realm="JalinWP OAuth"'); return $response;
        }
        if (($p['resource'] ?? '') !== self::resource()) { return self::error('invalid_target', 'The resource must exactly match the MCP endpoint URL.'); }
        if (isset($p['scope']) && $p['scope'] !== self::SCOPE) { return self::error('invalid_scope', 'The granted scope is mcp.'); }
        $type = $p['grant_type'] ?? '';
        if (!in_array($type, ['authorization_code', 'refresh_token'], true)) { return self::error('unsupported_grant_type', 'Use authorization_code or refresh_token.'); }
        global $wpdb;
        if ($type === 'authorization_code') {
            $raw = $p['code'] ?? ''; $verifier = $p['code_verifier'] ?? '';
            if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $raw) || !preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D', $verifier)) { return self::error('invalid_grant', 'The authorization code or PKCE verifier is invalid.'); }
            $table = self::table('codes'); $hash = self::hash($raw);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE hash=%s", $hash), ARRAY_A);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!$row || $row['client_id'] !== $client['id'] || $row['redirect_uri'] !== ($p['redirect_uri'] ?? '') || $row['resource'] !== $p['resource'] || !hash_equals($row['challenge'], $challenge)) { return self::error('invalid_grant', 'The authorization code, callback or PKCE verifier does not match.'); }
            if ((int) $row['consumed'] !== 0) { self::revoke($row['grant_id']); return self::error('invalid_grant', 'Authorization code replay detected. Connect again.'); }
            $g = self::active_grant($row['grant_id']);
            if (!$g || (int) $row['expires'] <= time()) { return self::error('invalid_grant', 'This authorization expired. Connect again.'); }
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET consumed=1 WHERE hash=%s AND consumed=0 AND expires>%d", $hash, time()));
        } else {
            $raw = $p['refresh_token'] ?? '';
            if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $raw)) { return self::error('invalid_grant', 'Invalid refresh token.'); }
            $table = self::table('tokens'); $hash = self::hash($raw);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE hash=%s AND kind='refresh'", $hash), ARRAY_A);
            $g = $row ? self::active_grant($row['grant_id']) : null;
            if (!$row || !$g || $g['client_id'] !== $client['id'] || (int) $row['expires'] <= time()) { return self::error('invalid_grant', 'This connection expired or was revoked. Connect again.'); }
            if ($row['status'] !== 'active') { self::revoke($g['id']); return self::error('invalid_grant', 'Refresh token replay detected. Connect again.'); }
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='used' WHERE hash=%s AND status='active' AND expires>%d", $hash, time()));
        }
        if ($claimed !== 1) {
            self::revoke($row['grant_id']); return self::error('invalid_grant', 'This credential was already used or could not be consumed. Connect again.');
        }
        return self::issue_tokens($g);
    }
    private static function issue_tokens(array $g): WP_REST_Response {
        global $wpdb;
        if (!self::room('tokens', 50000) || !self::active_grant($g['id'])) { self::revoke($g['id']); return self::error('temporarily_unavailable', 'Token storage is unavailable. Connect again.', 503); }
        $now = time(); $access = self::secret(); $refresh = self::secret(); $access_expiry = min($now + self::ACCESS_TTL, (int) $g['expires']);
        foreach ([['access', $access, $access_expiry], ['refresh', $refresh, (int) $g['expires']]] as [$kind, $raw, $expires]) {
            $ok = $wpdb->insert(self::table('tokens'), ['hash' => self::hash($raw), 'grant_id' => $g['id'], 'kind' => $kind, 'status' => 'active', 'expires' => $expires, 'created' => $now]);
            if ($ok === false) { self::revoke($g['id']); return self::error('temporarily_unavailable', 'Tokens could not be saved. Connect again.', 503); }
        }
        if (!self::active_grant($g['id'])) { return self::error('invalid_grant', 'This connection was revoked. Connect again.'); }
        // Preserve the first recorded issuance through token rotation. Missing legacy evidence stays unknown.
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('grants') . ' SET tokens_issued_at=%d WHERE id=%s AND tokens_issued_at IS NULL', $now, $g['id']));
        return self::response(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => max(0, $access_expiry - $now), 'refresh_token' => $refresh, 'scope' => self::SCOPE]);
    }
    public static function authenticate(string $raw_token) {
        if (!self::ready() || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $raw_token)) { return new WP_Error('fg_oauth_invalid', 'This MCP connection is unavailable or expired.', ['status' => 401]); }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT grant_id FROM ' . self::table('tokens') . " WHERE hash=%s AND kind='access' AND status='active' AND expires>%d", self::hash($raw_token), time()), ARRAY_A);
        $g = $row ? self::active_grant($row['grant_id']) : null;
        if (!$g) { return new WP_Error('fg_oauth_invalid', 'This MCP connection expired or was revoked. Connect again.', ['status' => 401]); }
        $client = self::client($g['client_id']);
        if (!$client) { return new WP_Error('fg_oauth_invalid', 'This client registration is unavailable. Connect again.', ['status'=>401]); }
        $s = FG_Core::fresh_settings();
        if (is_wp_error($s)) { return $s; }
        $now = time();
        // Only new grants have a known start of lifecycle observation. Older grants may have
        // authenticated before this migration, so do not invent their first-authentication time.
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('grants') . ' SET last_used=%d,first_authenticated_at=CASE WHEN consent_recorded_at IS NOT NULL THEN COALESCE(first_authenticated_at,%d) ELSE first_authenticated_at END WHERE id=%s', $now, $now, $g['id']));
        return ['user_id' => (int) $g['user_id'], 'credential_id' => $g['id'], 'writes' => !empty($s['writes']) && !empty($g['allow_writes']), 'yolo' => !empty($s['writes']) && !empty($g['allow_writes']) && !empty($g['allow_yolo']) && ($s['write_mode'] ?? 'reviewed') === 'yolo', 'sensitive' => !empty($s['sensitive']) && !empty($g['allow_sensitive'])] +
            FG_Core::normalize_audit_context(['auth_source'=>'oauth','oauth_client_id'=>$client['id'],'oauth_grant_id'=>$g['id'],'client_name'=>$client['name']]);
    }
    public static function revoke_token(WP_REST_Request $request): WP_REST_Response {
        if (!self::ready()) { return self::error('temporarily_unavailable', 'The connection service is unavailable.', 503); }
        if (!self::limit('revoke', 60, 60)) { return self::error('temporarily_unavailable', 'Try again later.', 429); }
        $p = self::form($request);
        if (is_wp_error($p)) { return self::error($p->get_error_code(), $p->get_error_message()); }
        $client = self::authenticate_client($request, $p);
        if (is_wp_error($client)) { $response = self::error('invalid_client', 'Client authentication failed.', 401); $response->header('WWW-Authenticate', 'Basic realm="JalinWP OAuth"'); return $response; }
        if (!isset($p['token']) || $p['token'] === '') { return self::error('invalid_request', 'A token is required.'); }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT g.id FROM ' . self::table('tokens') . ' t JOIN ' . self::table('grants') . ' g ON g.id=t.grant_id WHERE t.hash=%s AND g.client_id=%s', self::hash($p['token']), $client['id']), ARRAY_A);
        if ($row && !self::revoke($row['id'])) { return self::error('temporarily_unavailable', 'The connection could not be revoked.', 503); }
        return self::response([]);
    }
}
