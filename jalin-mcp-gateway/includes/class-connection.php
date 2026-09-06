<?php
defined('ABSPATH') || exit;

/** Administrator-only setup checks. No tokens, account data or arbitrary URLs are accepted. */
final class FG_Connection {
    public static function diagnostics(): array {
        $checks = [];
        $add = static function (string $label, string $status, string $detail) use (&$checks): void {
            $checks[] = compact('label', 'status', 'detail');
        };
        if (!current_user_can('manage_options')) {
            $add('Administrator access', 'fail', 'Sign in as a site administrator to run connection checks.');
            return ['checks' => $checks, 'checked_at' => gmdate('c')];
        }
        $settings = FG_Core::settings();
        $resource = FG_OAuth::resource();
        $issuer = FG_OAuth::issuer();
        $https = is_ssl() && wp_parse_url($resource, PHP_URL_SCHEME) === 'https' && wp_parse_url($issuer, PHP_URL_SCHEME) === 'https';
        $add('HTTPS', $https ? 'pass' : 'fail', $https ? 'WordPress recognizes HTTPS and publishes HTTPS connection URLs.' : 'Set both WordPress URLs to HTTPS and configure trusted proxy HTTPS detection on your host.');
        $enabled = !empty($settings['enabled']) && !empty($settings['oauth_enabled']);
        $add('OAuth enabled', $enabled ? 'pass' : 'fail', $enabled ? 'The gateway and WordPress OAuth sign-in are enabled.' : 'Use Enable for My Account to enable the gateway and OAuth sign-in.');
        $allowed = array_values(array_filter(array_map('intval', $settings['users']), static fn($id) => user_can($id, 'read') && (!is_multisite() || is_user_member_of_blog($id))));
        $add('Allowed accounts', $allowed ? 'pass' : 'fail', $allowed ? count($allowed) . ' account(s) can sign in, subject to their WordPress permissions.' : 'Use Enable for My Account to permit your administrator account.');
        $pretty = get_option('permalink_structure') !== '' && wp_parse_url($resource, PHP_URL_QUERY) === null;
        $add('REST URLs', $pretty ? 'pass' : 'fail', $pretty ? 'WordPress publishes a path-based MCP endpoint.' : 'Choose a non-Plain format in Settings → Permalinks, save, then copy the updated endpoint.');
        global $wpdb;
        $storage = true;
        foreach (['changes', 'audit', 'limits'] as $suffix) {
            $table = FG_Core::table($suffix);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { $storage = false; }
        }
        $add('Gateway storage', $storage ? 'pass' : 'fail', $storage ? 'The review, activity and rate-limit tables exist.' : 'Plugin table setup is incomplete. Check database CREATE/ALTER permissions, then reload the plugin settings.');
        if (!$https || !$pretty) {
            $add('Public discovery', 'warning', 'Remote checks were skipped until HTTPS and path-based REST URLs are configured.');
            return ['checks' => $checks, 'checked_at' => gmdate('c')];
        }
        $publication = $enabled && class_exists('FG_Discovery') ? FG_Discovery::publish() : null;
        $options = ['timeout' => 5, 'redirection' => 0, 'limit_response_size' => 32768, 'headers' => ['Accept' => 'application/json', 'Cache-Control' => 'no-cache']];
        // Verify the actual RFC 8414 discovery URL a client derives from the issuer.
        // A working raw REST metadata URL is not sufficient for OAuth discovery.
        // At most five loopback requests, each limited to five seconds.
        $metadata_url = FG_OAuth::authorization_metadata_urls()[0] ?? '';
        $discovered = false;
        $discovery_detail = 'WordPress could not build a valid OAuth discovery URL. Check the HTTPS site URL and permalink settings.';
        if ($metadata_url !== '') {
            $authorization = wp_safe_remote_get($metadata_url, $options);
            $metadata = self::json($authorization);
            $discovered = $metadata !== null && self::authorization_metadata_valid($metadata, $issuer);
            if ($discovered) {
                $discovery_detail = 'The standard OAuth discovery document is reachable and matches this gateway, including its sign-in and automatic registration endpoints.';
            } elseif (!is_wp_error($authorization) && wp_remote_retrieve_response_code($authorization) === 200) {
                $discovery_detail = 'OAuth metadata at ' . $metadata_url . ' did not return application/json matching this gateway\'s issuer, sign-in endpoints and required security capabilities. Clear cached discovery responses and retry. Recreate the ChatGPT or Claude connector after updating the plugin.';
            } else {
                $discovery_detail = 'The standard OAuth discovery URL is not reachable: ' . $metadata_url . '. ' . self::status($authorization) . ' Clear site/CDN caches, then retry.';
            }
        }
        if (!$discovered && $publication) {
            $discovery_detail .= ' ' . $publication['message'] . ' Connection cannot complete until the standard discovery URL returns this gateway\'s metadata.';
        }
        $add('OAuth discovery', $discovered ? 'pass' : 'fail', $discovery_detail);
        $protected = wp_safe_remote_get(FG_OAuth::resource_metadata_url(), $options);
        $metadata = self::json($protected);
        $resource_ok = $metadata !== null && self::resource_metadata_valid($metadata, $resource, $issuer);
        $add('MCP resource discovery', $resource_ok ? 'pass' : 'fail', $resource_ok ? 'The resource document matches this site and MCP endpoint.' : 'The resource metadata could not be read or did not match this site. Clear endpoint caches and allow the plugin REST routes. ' . self::status($protected));

        // Some clients discover OAuth before requesting MCP, without seeing its 401 header.
        // Verify their derived RFC 9728 path independently from the header-linked REST URL.
        $proactive_url = class_exists('FG_Discovery') ? FG_Discovery::protected_resource_url() : '';
        $proactive_status = 'fail';
        $proactive_detail = 'WordPress could not build a valid standard MCP resource discovery URL. Check the HTTPS site URL and permalink settings.';
        if ($proactive_url !== '') {
            $proactive = wp_safe_remote_get($proactive_url, $options);
            $metadata = self::json($proactive, false);
            if ($metadata !== null && self::resource_metadata_valid($metadata, $resource, $issuer)) {
                if (self::json_content_type($proactive)) {
                    $proactive_status = 'pass';
                    $proactive_detail = 'The standard MCP resource document is reachable and matches this gateway before a client requests its sign-in challenge.';
                } else {
                    $proactive_status = 'warning';
                    $proactive_detail = 'The standard MCP resource URL returns valid JSON matching this gateway, but its Content-Type is not application/json. The tested MCP SDK parses this response; stricter clients may reject it. Complete the actual client connection to verify compatibility. URL: ' . $proactive_url . '.';
                }
            } elseif (!is_wp_error($proactive) && wp_remote_retrieve_response_code($proactive) === 200) {
                $proactive_detail = 'The standard MCP resource URL returned HTTP 200 but not valid JSON matching this gateway\'s MCP resource and OAuth issuer: ' . $proactive_url . '. Clear cached discovery responses and retry.';
            } else {
                $proactive_detail = 'Clients that discover OAuth before requesting MCP cannot reach the standard resource URL: ' . $proactive_url . '. ' . self::status($proactive) . ' Clear site/CDN caches, then retry.';
            }
        }
        if ($proactive_status === 'fail' && $publication) {
            $proactive_detail .= ' ' . ($publication['documents']['protected_resource']['message'] ?? $publication['message']) . ' A working REST metadata URL alone does not verify this standard discovery path.';
        }
        $add('Proactive MCP resource discovery', $proactive_status, $proactive_detail);
        $probe = wp_safe_remote_post($resource, array_replace($options, [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Cache-Control' => 'no-cache'],
            'body' => wp_json_encode(['jsonrpc' => '2.0', 'id' => 'connection-check', 'method' => 'initialize', 'params' => ['protocolVersion' => FG_Server::VERSIONS[0], 'capabilities' => (object) [], 'clientInfo' => ['name' => 'jalin-connection-check', 'version' => FG_VERSION]]]),
        ]));
        $challenge_ok = self::challenge_valid($probe);
        $add('Sign-in challenge', $challenge_ok ? 'pass' : 'fail', $challenge_ok ? 'An unauthenticated MCP POST correctly asks the client to start OAuth.' : 'The MCP endpoint did not return the expected OAuth sign-in challenge for POST. Confirm OAuth is enabled and allow Authorization / WWW-Authenticate headers through the host and firewall. ' . self::status($probe));
        $get_probe = wp_safe_remote_get($resource, array_replace($options, [
            'headers' => ['Accept' => 'application/json, text/event-stream', 'Cache-Control' => 'no-cache'],
        ]));
        $get_challenge_ok = self::challenge_valid($get_probe);
        $add('GET sign-in challenge', $get_challenge_ok ? 'pass' : 'fail', $get_challenge_ok ? 'An unauthenticated MCP GET also returns the expected OAuth sign-in challenge.' : 'The MCP endpoint did not return the expected OAuth sign-in challenge for GET. Check cached GET responses and host/firewall handling of WWW-Authenticate. ' . self::status($get_probe));
        $add('Final client test', 'warning', 'These are site-side and loopback checks. Complete Connect in ChatGPT or Claude to verify external reachability, WordPress sign-in, registration, token exchange and tool access.');
        return ['checks' => $checks, 'checked_at' => gmdate('c')];
    }

    private static function json($response, bool $require_json_type = true): ?array {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return null; }
        if ($require_json_type && !self::json_content_type($response)) { return null; }
        $body = json_decode(wp_remote_retrieve_body($response), true, 32);
        return is_array($body) ? $body : null;
    }

    private static function json_content_type($response): bool {
        return strtolower(trim(explode(';', (string) wp_remote_retrieve_header($response, 'content-type'), 2)[0])) === 'application/json';
    }

    private static function resource_metadata_valid(array $metadata, string $resource, string $issuer): bool {
        return is_array($metadata['authorization_servers'] ?? null) && ($metadata['resource'] ?? null) === $resource && in_array($issuer, $metadata['authorization_servers'], true);
    }

    private static function challenge_valid($response): bool {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 401) { return false; }
        $challenge = (string) wp_remote_retrieve_header($response, 'www-authenticate');
        return str_contains($challenge, 'Bearer ') && str_contains($challenge, FG_OAuth::resource_metadata_url());
    }

    private static function authorization_metadata_valid(array $metadata, string $issuer): bool {
        $endpoints = [
            'issuer' => $issuer,
            'authorization_endpoint' => add_query_arg('action', 'fg_oauth_authorize', admin_url('admin-post.php')),
            'token_endpoint' => rest_url('jalin-mcp/v1/oauth/token'),
            'registration_endpoint' => rest_url('jalin-mcp/v1/oauth/register'),
        ];
        foreach ($endpoints as $key => $expected) {
            if (($metadata[$key] ?? null) !== $expected || wp_parse_url($expected, PHP_URL_SCHEME) !== 'https') { return false; }
        }
        foreach (['code_challenge_methods_supported' => 'S256', 'response_types_supported' => 'code', 'grant_types_supported' => 'authorization_code'] as $key => $required) {
            if (!is_array($metadata[$key] ?? null) || !in_array($required, $metadata[$key], true)) { return false; }
        }
        return true;
    }

    private static function status($response): string {
        // Do not echo arbitrary upstream response bodies, transport errors or credentials.
        return is_wp_error($response) ? 'The host could not complete the loopback request.' : 'HTTP status: ' . wp_remote_retrieve_response_code($response) . '.';
    }
}
