<?php
defined('ABSPATH') || exit;

final class FG_Auth {
    private static int $user_id = 0;
    private static string $credential = '';
    private static ?array $oauth = null;
    private static bool $application_password_pending = false;
    private static ?WP_REST_Request $authenticated_request = null;
    private static ?WeakMap $completed_requests = null;
    public static function boot(): void {
        add_action('application_password_did_authenticate', static function ($user, $item) {
            self::reset_context(false);
            self::$user_id = (int) $user->ID;
            self::$credential = (string) ($item['uuid'] ?? '');
            self::$application_password_pending = true;
        }, 10, 2);
        // Nested WordPress REST operations must retain the outer request's attribution.
        add_filter('rest_request_after_callbacks', static function ($response, $handler, $request) {
            if ($request->get_route() === '/fames-mcp/v1/mcp') {
                self::$completed_requests ??= new WeakMap();
                self::$completed_requests[$request] = self::$authenticated_request === $request;
                self::reset_context();
            }
            return $response;
        }, PHP_INT_MAX, 3);
        add_action('shutdown', [self::class, 'reset_context']);
    }
    public static function reset_context(bool $clear_oauth_user = true): void {
        if ($clear_oauth_user && self::$oauth !== null && get_current_user_id() === self::$user_id) { wp_set_current_user(0); }
        self::$oauth = null; self::$user_id = 0; self::$credential = ''; self::$application_password_pending = false; self::$authenticated_request = null;
    }
    /** Request-scoped identity only; registration labels never identify a verified provider. */
    public static function audit_context(): array {
        if (!self::$user_id || get_current_user_id() !== self::$user_id || self::$credential === '') { return FG_Core::normalize_audit_context([]); }
        return FG_Core::normalize_audit_context(self::$oauth ?? ['auth_source'=>'application_password']);
    }
    public static function credential_id(): string { return self::$credential; }
    /** Site policy intersects this authenticated connection's original OAuth consent. */
    public static function write_mode(): string {
        if (self::$authenticated_request === null || self::$user_id !== get_current_user_id() || self::$credential === '') { return 'read_only'; }
        $settings = FG_Core::fresh_settings();
        if (is_wp_error($settings) || empty($settings['enabled']) || empty($settings['writes']) ||
            !in_array(self::$user_id, array_map('intval', $settings['users']), true) || !is_user_member_of_blog(self::$user_id) || !current_user_can('read')) { return 'read_only'; }
        if (self::$oauth !== null && (empty($settings['oauth_enabled']) || empty(self::$oauth['writes']))) { return 'read_only'; }
        // A newly enabled site mode never widens a connection that approved reviewed writes.
        return ($settings['write_mode'] ?? 'reviewed') === 'yolo' && (self::$oauth === null || !empty(self::$oauth['yolo'])) ? 'yolo' : 'reviewed';
    }
    /** OAuth consent cannot be widened by a later site setting. Native capabilities still apply. */
    public static function allows_tool(array $tool): bool {
        return self::$oauth === null ||
            ((!$tool['mutating'] || !empty(self::$oauth['writes'])) && (!$tool['sensitive'] || !empty(self::$oauth['sensitive'])));
    }
    public static function challenge(): string {
        if (class_exists('FG_OAuth') && !empty(FG_Core::settings()['oauth_enabled'])) {
            return 'Bearer resource_metadata="' . FG_OAuth::resource_metadata_url() . '", scope="mcp"';
        }
        return 'Basic realm="JalinWP", charset="UTF-8"';
    }
    public static function origin(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) { return ''; }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }
    public static function permission(WP_REST_Request $request) {
        // Core probes this callback again for each Allow-header method after the handler.
        // Reuse only that completed decision during response-header filtering, without
        // restoring identity, authenticating tokens again, or charging another rate slot.
        if (doing_filter('rest_post_dispatch') && self::$completed_requests !== null && isset(self::$completed_requests[$request])) {
            return self::$completed_requests[$request];
        }
        // Drop the previous request's identity. WP's current authentication hook supplies a fresh
        // Application Password candidate before this callback; cookie-only requests cannot reuse it.
        if (self::$oauth !== null || !self::$application_password_pending) { self::reset_context(); }
        self::$application_password_pending = false;
        // Bearer credentials authenticate only this outer gateway route, never arbitrary WP REST.
        if ($request->get_route() !== '/fames-mcp/v1/mcp') {
            self::reset_context();
            return new WP_Error('fg_route', 'Gateway authentication is restricted to the MCP endpoint.', ['status' => 403]);
        }
        $origin = (string) $request->get_header('origin');
        $settings = FG_Core::fresh_settings();
        if (is_wp_error($settings)) { return $settings; }
        if ($origin !== '') {
            $parts = wp_parse_url($origin);
            $allowed = array_map([self::class, 'origin'], array_merge([home_url(), site_url()], $settings['origins']));
            if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
                (isset($parts['path']) && $parts['path'] !== '') || !in_array(strtolower($parts['scheme'] ?? ''), ['https'], true) ||
                !in_array(self::origin($origin), $allowed, true)) {
                return new WP_Error('fg_origin', 'Origin is not allowed.', ['status' => 403]);
            }
        }
        if (!is_ssl()) { return new WP_Error('fg_https', 'HTTPS is required. Configure trusted proxy HTTPS detection in WordPress if applicable.', ['status' => 403]); }
        if (!$settings['enabled']) { return new WP_Error('fg_disabled', 'The MCP gateway is disabled.', ['status' => 403]); }
        $authorization = (string) $request->get_header('authorization');
        if (preg_match('/^Bearer(?:\s|$)/i', $authorization)) {
            self::$user_id = 0;
            self::$credential = '';
            wp_set_current_user(0);
            if (!class_exists('FG_OAuth') || empty($settings['oauth_enabled']) || !preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $authorization, $match)) {
                return new WP_Error('fg_auth', 'OAuth access is unavailable or the token is invalid. Reconnect from your AI client.', ['status' => 401]);
            }
            $identity = FG_OAuth::authenticate($match[1]);
            if (is_wp_error($identity)) { return $identity; }
            self::$oauth = $identity;
            self::$user_id = (int) $identity['user_id'];
            self::$credential = (string) $identity['credential_id'];
            wp_set_current_user(self::$user_id);
        }
        if (!get_current_user_id() || self::$user_id !== get_current_user_id() || self::$credential === '') {
            return new WP_Error('fg_auth', 'Connect using OAuth, or use a WordPress Application Password with the local bridge. Cookie-only access is not accepted.', ['status' => 401]);
        }
        if (!in_array(get_current_user_id(), array_map('intval', $settings['users']), true) || !is_user_member_of_blog(get_current_user_id()) || !current_user_can('read')) {
            return new WP_Error('fg_forbidden', 'This WordPress user is not enabled for the gateway.', ['status' => 403]);
        }
        if (!FG_Core::rate_limit()) { return new WP_Error('fg_rate_limit', 'Request limit reached or rate-limit storage unavailable. Wait before retrying reads; inspect writes before retrying.', ['status' => 429]); }
        self::$authenticated_request = $request;
        return true;
    }
}
