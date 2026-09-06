<?php
defined('ABSPATH') || exit;

final class FG_Failure extends RuntimeException {
    public string $reason;
    public function __construct(string $reason, string $message) {
        $this->reason = $reason;
        parent::__construct($message);
    }
}

final class FG_Core {
    private const DB_VERSION = '0.3.0';
    public static function settings(): array {
        return wp_parse_args(get_option('fg_settings', []), self::defaults());
    }
    private static function defaults(): array {
        return [
            'enabled' => false, 'oauth_enabled' => false, 'writes' => false, 'sensitive' => false,
            'write_mode' => 'reviewed', 'users' => [], 'origins' => [], 'finance_mappings' => [],
        ];
    }
    /** Authorization decisions must observe mode changes made by another request immediately. */
    public static function fresh_settings(): array|WP_Error {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'fg_settings'));
        if ($wpdb->last_error !== '' || !is_string($raw)) { return new WP_Error('fg_settings_storage', 'Gateway settings could not be read. Retry after resolving the storage error.', ['status'=>503]); }
        $settings = maybe_unserialize($raw);
        if (!is_array($settings)) { return new WP_Error('fg_settings_storage', 'Gateway settings could not be read. Retry after resolving the storage error.', ['status'=>503]); }
        return wp_parse_args($settings, self::defaults());
    }
    public static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'fg_' . $suffix;
    }
    public static function maybe_upgrade(): void {
        if (get_option('fg_db_version') !== self::DB_VERSION) { self::activate(); }
        if (class_exists('FG_OAuth')) { FG_OAuth::maybe_upgrade(); }
    }
    public static function activate(bool $network_wide = false): void {
        if ($network_wide) {
            wp_die(esc_html__('Activate JalinWP separately on each site. Network activation is not supported.', 'fames-mcp-gateway'));
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $changes = self::table('changes');
        $audit = self::table('audit');
        $limits = self::table('limits');
        dbDelta("CREATE TABLE $changes (
            id char(36) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            credential_id char(36) NOT NULL,
            tool varchar(64) NOT NULL,
            arguments longtext NOT NULL,
            digest char(64) NOT NULL,
            status varchar(16) NOT NULL,
            created bigint(20) unsigned NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            server_context longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status,created),
            KEY user_created (user_id,created)
        ) $charset;");
        dbDelta("CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            tool varchar(64) NOT NULL,
            outcome varchar(40) NOT NULL,
            change_id varchar(36) NOT NULL DEFAULT '',
            auth_source varchar(24) NOT NULL DEFAULT '',
            oauth_client_id varchar(36) NOT NULL DEFAULT '',
            oauth_grant_id varchar(36) NOT NULL DEFAULT '',
            client_name varchar(120) NOT NULL DEFAULT '',
            created bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY created (created),
            KEY client_created (oauth_client_id,created)
        ) $charset;");
        dbDelta("CREATE TABLE $limits (
            bucket varchar(80) NOT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 1,
            expires bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (bucket),
            KEY expires (expires)
        ) $charset;");
        if (!wp_next_scheduled('fg_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'fg_daily_cleanup');
        }
        add_option('fg_settings', self::defaults(), '', false);
        if (class_exists('FG_OAuth')) { FG_OAuth::install(); }
        // Verify the actual additive migration, not just that older tables still exist.
        $ready = self::schema_ready($changes, ['id','user_id','credential_id','tool','arguments','digest','status','created','expires','reviewer_id','server_context'], ['PRIMARY'=>['id'],'status_created'=>['status','created'],'user_created'=>['user_id','created']]) &&
            self::schema_ready($audit, ['id','user_id','tool','outcome','change_id','auth_source','oauth_client_id','oauth_grant_id','client_name','created'], ['PRIMARY'=>['id'],'created'=>['created'],'client_created'=>['oauth_client_id','created']]) &&
            self::schema_ready($limits, ['bucket','hits','expires'], ['PRIMARY'=>['bucket'],'expires'=>['expires']]);
        // Older proposals lack the reviewed policy/context snapshot required by this release.
        if ($ready && get_option('fg_db_version') !== self::DB_VERSION) {
            $ready = false !== $wpdb->query("UPDATE $changes SET status='revoked' WHERE server_context IS NULL AND status IN ('pending','reviewing','approved')");
        }
        if ($ready) { update_option('fg_db_version', self::DB_VERSION, false); }
    }
    /** Check expected columns and ordered indexes before advancing a migration marker. */
    public static function schema_ready(string $table, array $columns, array $indexes): bool {
        global $wpdb;
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) { return false; }
        $fields = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($fields) || array_diff($columns, array_column($fields, 'Field'))) { return false; }
        $rows = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) { return false; }
        $found = [];
        foreach ($rows as $row) { $found[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name']; }
        foreach ($indexes as $name => $expected) {
            if (!isset($found[$name])) { return false; }
            ksort($found[$name]);
            if (array_values($found[$name]) !== $expected) { return false; }
        }
        return true;
    }
    public static function deactivate(): void {
        // Use the same atomic, fail-closed transition as the dashboard button. A failed
        // cleanup must leave its pending marker so reactivation cannot revive old tokens.
        if (!class_exists('FG_Settings')) { require_once __DIR__ . '/class-settings.php'; }
        FG_Settings::disable(FG_Settings::fingerprint('connection'));
        $snapshot = FG_Settings::snapshot('connection');
        if (is_wp_error($snapshot) || !empty($snapshot['settings']['enabled'])) {
            wp_die('MCP access could not be disabled in storage. Resolve the settings storage error and retry deactivating the plugin.', 'Deactivation Could Not Be Completed', ['response'=>503]);
        }
        wp_clear_scheduled_hook('fg_daily_cleanup');
    }
    public static function cleanup(): void {
        global $wpdb;
        $now = time();
        // Proposed content is short lived; logs contain metadata only.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('changes') . ' WHERE expires < %d', $now - DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('audit') . ' WHERE created < %d', $now - 30 * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('limits') . ' WHERE expires < %d', $now));
        if (class_exists('FG_OAuth')) { FG_OAuth::cleanup(); }
    }
    /** Explicit context is required for admin decisions made outside an MCP request. */
    public static function audit(string $tool, string $outcome, string $change_id = '', ?array $context = null): bool {
        global $wpdb;
        $context = self::normalize_audit_context($context ?? (class_exists('FG_Auth') ? FG_Auth::audit_context() : []));
        return false !== $wpdb->insert(self::table('audit'), [
            'user_id' => get_current_user_id(), 'tool' => substr($tool, 0, 64),
            'outcome' => substr($outcome, 0, 40), 'change_id' => substr($change_id, 0, 36), 'created' => time(),
        ] + $context, ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);
    }
    public static function normalize_audit_context(array $context): array {
        $source = in_array($context['auth_source'] ?? '', ['oauth','wordpress_admin','application_password'], true) ? $context['auth_source'] : '';
        $client = is_string($context['oauth_client_id'] ?? null) && wp_is_uuid($context['oauth_client_id']) ? $context['oauth_client_id'] : '';
        $grant = is_string($context['oauth_grant_id'] ?? null) && wp_is_uuid($context['oauth_grant_id']) ? $context['oauth_grant_id'] : '';
        if ($source === 'oauth' && ($client === '' || $grant === '')) { $source = ''; }
        $name = sanitize_text_field(is_string($context['client_name'] ?? null) ? $context['client_name'] : '');
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : wp_check_invalid_utf8(substr($name, 0, 120), true);
        return ['auth_source'=>$source, 'oauth_client_id'=>$source === 'oauth' ? $client : '', 'oauth_grant_id'=>$source === 'oauth' ? $grant : '',
            'client_name'=>$source === 'oauth' ? ($name ?: 'MCP Client') : ($source === 'wordpress_admin' ? 'WordPress Admin' : ($source === 'application_password' ? 'Application Password' : ''))];
    }
    public static function rate_limit(): bool {
        global $wpdb;
        $bucket = get_current_user_id() . ':' . intdiv(time(), 60);
        $table = self::table('limits');
        $ok = $wpdb->query($wpdb->prepare("INSERT INTO $table (bucket,hits,expires) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1", $bucket, time() + 120));
        if (false === $ok) { return false; }
        $hits = $wpdb->get_var($wpdb->prepare("SELECT hits FROM $table WHERE bucket=%s", $bucket));
        return is_numeric($hits) && (int) $hits >= 1 && (int) $hits <= 60;
    }
}
