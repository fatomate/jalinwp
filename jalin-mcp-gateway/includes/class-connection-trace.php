<?php
defined('ABSPATH') || exit;

/** Temporary, deliberately lossy support trace. Never records request or response payloads. */
final class FG_Connection_Trace {
    private const OPTION = 'fg_connection_trace';
    private const RATE_OPTION = 'fg_connection_trace_slot';
    private const CHECKS_OPTION = 'fg_connection_trace_checks';
    private const TTL = 900;
    private const RETENTION = 86400;
    private const MAX_EVENTS = 50;
    private const ROUTES = [
        '/jalin-mcp/v1/mcp' => 'mcp',
        '/jalin-mcp/v1/oauth/register' => 'registration',
        '/jalin-mcp/v1/oauth/token' => 'token',
        '/jalin-mcp/v1/oauth/revoke' => 'revocation',
        '/jalin-mcp/v1/oauth/authorization-server' => 'authorization_metadata',
        '/jalin-mcp/v1/oauth/protected-resource' => 'resource_metadata',
    ];
    private const ERRORS = ['none', 'other', 'invalid_request', 'invalid_client', 'invalid_client_metadata', 'invalid_redirect_uri', 'invalid_grant', 'invalid_scope', 'invalid_target', 'unsupported_grant_type', 'temporarily_unavailable', 'access_denied', 'fg_oauth_invalid', 'fg_origin', 'fg_https', 'fg_disabled', 'fg_auth', 'fg_forbidden', 'fg_rate_limit', 'fg_route', 'rest_no_route', 'rest_forbidden', 'rest_not_logged_in', 'rest_cookie_invalid_nonce'];
    private const CHECK_LABELS = ['HTTPS', 'OAuth enabled', 'Allowed accounts', 'REST URLs', 'Gateway storage', 'OAuth storage', 'Public discovery', 'OAuth discovery', 'MCP resource discovery', 'Proactive MCP resource discovery', 'Sign-in challenge', 'GET sign-in challenge', 'Final client test', 'Registration endpoint', 'OAuth registration', 'MCP GET challenge', 'MCP POST challenge', 'MCP discovery', 'Standard resource discovery'];

    public static function boot(): void {
        add_filter('rest_post_dispatch', [self::class, 'capture'], PHP_INT_MAX, 3);
        add_action('fg_connection_trace_cleanup', [self::class, 'cleanup']);
    }
    private static function require_admin(): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
    }
    /** Call only after the admin POST handler has verified its action nonce. */
    public static function start(): bool {
        self::require_admin();
        $now = time();
        $state = ['started' => $now, 'expires' => $now + self::TTL, 'events' => []];
        if (!update_option(self::RATE_OPTION, '0', false) && get_option(self::RATE_OPTION) !== '0') { return false; }
        if (!update_option(self::OPTION, $state, false) && get_option(self::OPTION) !== $state) { return false; }
        self::schedule_cleanup($now + self::RETENTION + 1);
        return true;
    }
    public static function state(): array {
        self::require_admin();
        $state = get_option(self::OPTION, []);
        if (!is_array($state) || (int) ($state['started'] ?? 0) < time() - self::RETENTION) { return ['active' => false, 'expires' => 0, 'events' => 0]; }
        return ['active' => (int) ($state['expires'] ?? 0) > time(), 'expires' => (int) ($state['expires'] ?? 0), 'events' => min(self::MAX_EVENTS, count((array) ($state['events'] ?? [])))];
    }
    public static function cleanup(): void {
        $remaining = [];
        $state = get_option(self::OPTION, []);
        if (!is_array($state) || (int) ($state['started'] ?? 0) <= time() - self::RETENTION) {
            delete_option(self::OPTION); delete_option(self::RATE_OPTION);
        } else { $remaining[] = (int) $state['started'] + self::RETENTION + 1; }
        $checks = get_option(self::CHECKS_OPTION, []);
        if (!is_array($checks) || (int) ($checks['recorded'] ?? 0) <= time() - self::RETENTION) { delete_option(self::CHECKS_OPTION); }
        else { $remaining[] = (int) $checks['recorded'] + self::RETENTION + 1; }
        if ($remaining) { self::schedule_cleanup(min($remaining)); }
    }
    private static function schedule_cleanup(int $when): void {
        $next = wp_next_scheduled('fg_connection_trace_cleanup');
        if (!$next || $next <= time() || $next > $when) {
            if ($next) { wp_clear_scheduled_hook('fg_connection_trace_cleanup'); }
            wp_schedule_single_event($when, 'fg_connection_trace_cleanup');
        }
    }
    /** Only fixed labels/statuses are retained, never upstream messages or account counts. */
    public static function remember_checks(array $report): void {
        self::require_admin();
        update_option(self::CHECKS_OPTION, ['recorded' => time(), 'checks' => self::clean_checks($report)], false);
        self::schedule_cleanup(time() + self::RETENTION + 1);
    }
    /** Fixed public configuration only; no identifiers from registered clients or user accounts. */
    public static function manual_settings(): array {
        $metadata = FG_OAuth::authorization_metadata();
        return ['server_url' => FG_OAuth::resource(), 'issuer' => FG_OAuth::issuer(),
            'authorization_endpoint' => $metadata['authorization_endpoint'], 'token_endpoint' => $metadata['token_endpoint'],
            'registration_endpoint' => $metadata['registration_endpoint'], 'scope' => 'mcp'];
    }
    public static function report(): array {
        self::require_admin();
        self::cleanup();
        $state = get_option(self::OPTION, []);
        $checks = get_option(self::CHECKS_OPTION, []);
        $events = [];
        foreach (array_slice((array) ($state['events'] ?? []), -self::MAX_EVENTS) as $event) {
            // Re-project on export as defense against another option writer adding sensitive fields.
            if (is_array($event) && in_array($event['stage'] ?? null, array_values(self::ROUTES), true)) {
                $events[] = self::clean_event($event);
            }
        }
        return ['plugin' => 'JalinWP', 'version' => FG_VERSION, 'generated_at_utc' => gmdate('c'),
            'configuration' => self::manual_settings(), 'authorization_metadata_urls' => FG_OAuth::authorization_metadata_urls(),
            'resource_metadata_url' => FG_OAuth::resource_metadata_url(),
            'proactive_resource_metadata_url' => class_exists('FG_Discovery') ? FG_Discovery::protected_resource_url() : null,
            'last_checks' => is_array($checks) && isset($checks['recorded']) ? ['recorded' => (int) $checks['recorded'], 'checks' => self::clean_checks($checks)] : [],
            'trace' => ['active' => (int) ($state['expires'] ?? 0) > time(), 'started_at_utc' => empty($state['started']) ? null : gmdate('c', (int) $state['started']),
                'expires_at_utc' => empty($state['expires']) ? null : gmdate('c', (int) $state['expires']), 'events' => $events],
            'limits' => ['capture_minutes' => 15, 'maximum_events' => self::MAX_EVENTS, 'maximum_events_per_second' => 4, 'retention_hours' => 24],
            'notes' => ['Only gateway REST requests reaching WordPress during the trace are visible. Static discovery files, browser sign-in/consent and requests blocked before PHP are not captured.',
                'Host loopback connection checks can appear alongside client requests. Callback family is a classification of submitted URLs, not verified client identity.',
                'The trace is bounded and may omit rapid or concurrent requests; an empty trace alone does not prove the client sent nothing.',
                'No tokens, secrets, request/response bodies, authorization codes, state, cookies, IP addresses, user agents, account data or registered client IDs are included. Public site URLs are included.']];
    }
    public static function capture($response, $server, $request) {
        try {
            if (!$request instanceof WP_REST_Request || !$response instanceof WP_REST_Response) { return $response; }
            $stage = self::ROUTES[$request->get_route()] ?? null;
            if ($stage === null) { return $response; }
            $state = get_option(self::OPTION, []);
            if (!is_array($state) || (int) ($state['expires'] ?? 0) <= time()) { return $response; }
            global $wpdb;
            // A single fixed row bounds accepted writes; no per-address or per-request keys.
            $slot = time() * 4;
            $accepted = $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=CASE WHEN CAST(option_value AS UNSIGNED)<%d THEN %d ELSE CAST(option_value AS UNSIGNED)+1 END WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d", $slot, $slot + 1, self::RATE_OPTION, $slot + 4));
            if ($accepted !== 1) { return $response; }
            wp_cache_delete(self::RATE_OPTION, 'options');
            $data = $response->get_data();
            $error = is_array($data) ? ($data['error'] ?? $data['code'] ?? '') : '';
            $authorization = (string) $request->get_header('authorization');
            $scheme = $authorization === '' ? (isset($_SERVER['PHP_AUTH_USER']) ? 'basic' : 'none') : (preg_match('/\A(Basic|Bearer)\s/i', $authorization, $match) ? strtolower($match[1]) : 'other');
            $event = self::clean_event(['time' => time(), 'stage' => $stage, 'method' => $request->get_method(), 'status' => $response->get_status(),
                'error' => is_string($error) ? $error : '', 'auth_scheme' => $scheme, 'callback_family' => self::callback_family($request)]);
            $new = $state;
            $new['events'] = array_slice(array_merge((array) ($state['events'] ?? []), [$event]), -self::MAX_EVENTS);
            // Compare-and-swap avoids overwriting a concurrent fresh trace or another recorder.
            $saved = $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize($new), self::OPTION, maybe_serialize($state)));
            if ($saved === 1) { wp_cache_delete(self::OPTION, 'options'); }
        } catch (Throwable $ignored) { /* Diagnostics must never alter OAuth availability. */ }
        return $response;
    }
    private static function clean_event(array $event): array {
        return ['time' => max(0, (int) ($event['time'] ?? 0)), 'stage' => $event['stage'],
            'method' => in_array($event['method'] ?? null, ['GET', 'HEAD', 'POST', 'OPTIONS', 'DELETE', 'PUT', 'PATCH'], true) ? $event['method'] : 'other',
            'status' => min(599, max(100, (int) ($event['status'] ?? 500))),
            'error' => in_array($event['error'] ?? null, self::ERRORS, true) ? $event['error'] : (($event['error'] ?? '') === '' ? 'none' : 'other'),
            'auth_scheme' => in_array($event['auth_scheme'] ?? null, ['none', 'basic', 'bearer'], true) ? $event['auth_scheme'] : 'other',
            'callback_family' => in_array($event['callback_family'] ?? null, ['none', 'chatgpt', 'claude', 'mixed'], true) ? $event['callback_family'] : 'unsupported'];
    }
    private static function clean_checks(array $report): array {
        $checks = [];
        foreach (array_slice((array) ($report['checks'] ?? []), 0, 30) as $check) {
            if (!is_array($check) || !in_array($check['label'] ?? null, self::CHECK_LABELS, true)) { continue; }
            $checks[] = ['label' => $check['label'], 'status' => in_array($check['status'] ?? null, ['pass', 'fail', 'warning'], true) ? $check['status'] : 'warning'];
        }
        return $checks;
    }
    private static function callback_family(WP_REST_Request $request): string {
        $redirects = $request->get_param('redirect_uris');
        if ($redirects === null) { $one = $request->get_param('redirect_uri'); $redirects = $one === null ? [] : [$one]; }
        if ($redirects === []) { return 'none'; }
        if (!is_array($redirects) || count($redirects) > 5) { return 'unsupported'; }
        $families = [];
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !FG_OAuth::valid_redirect($uri)) { return 'unsupported'; }
            $families[] = $uri === 'https://claude.ai/api/mcp/auth_callback' ? 'claude' : 'chatgpt';
        }
        return count(array_unique($families)) > 1 ? 'mixed' : $families[0];
    }
}
