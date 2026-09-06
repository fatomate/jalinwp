<?php
defined('ABSPATH') || exit;

/** Section-scoped, optimistic settings writes. All gateway admin saves use this boundary. */
final class FG_Settings {
    private const OPTION = 'fg_settings';
    private const LOCK = 'fg_settings_save_lock';
    private const CONNECTION = ['enabled', 'oauth_enabled', 'writes', 'write_mode', 'sensitive', 'users', 'origins'];

    private static function fresh(): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", self::OPTION));
        if ($wpdb->last_error !== '' || !is_string($raw)) { throw new RuntimeException('Settings storage is unavailable.'); }
        $stored = maybe_unserialize($raw);
        if (!is_array($stored)) { throw new RuntimeException('Settings storage is invalid.'); }
        return [$raw, wp_parse_args($stored, ['enabled'=>false, 'oauth_enabled'=>false, 'writes'=>false, 'write_mode'=>'reviewed', 'sensitive'=>false, 'users'=>[], 'origins'=>[], 'finance_mappings'=>[]])];
    }
    private static function digest(string $section, array $settings): string {
        $keys = $section === 'finance' ? ['finance_mappings'] : self::CONNECTION;
        return hash('sha256', maybe_serialize(array_intersect_key($settings, array_flip($keys))));
    }
    public static function fingerprint(string $section): string {
        if (!in_array($section, ['connection', 'finance'], true)) { return ''; }
        try { [, $settings] = self::fresh(); return self::digest($section, $settings); }
        catch (RuntimeException $error) { return ''; }
    }
    /** Values and their version must come from the same read when rendering an editable form. */
    public static function snapshot(string $section) {
        if (!in_array($section, ['connection', 'finance'], true)) { return new WP_Error('fg_settings_section', 'Unknown settings section.'); }
        try { [, $settings] = self::fresh(); return ['settings'=>$settings, 'version'=>self::digest($section, $settings)]; }
        catch (RuntimeException $error) { return new WP_Error('fg_settings_storage', 'Settings could not be loaded. Reload this page to retry.', ['status'=>503]); }
    }
    public static function version_for(string $section, array $settings): string {
        return in_array($section, ['connection', 'finance'], true) ? self::digest($section, $settings) : '';
    }
    public static function policy_revision(): string {
        try { [, $settings] = self::fresh(); return is_string($settings['_policy_revision'] ?? null) ? $settings['_policy_revision'] : 'legacy'; }
        catch (RuntimeException $error) { return ''; }
    }
    private static function clear_cache(): void {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
    private static function compare_write(string $raw, array $settings) {
        global $wpdb;
        // A binary comparison matters: the options table normally has case-insensitive collation.
        $written = $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s AND BINARY option_value=%s", maybe_serialize($settings), self::OPTION, $raw));
        self::clear_cache();
        return $written;
    }
    private static function lock() {
        global $wpdb;
        $token = wp_generate_uuid4() . ':' . (time() + 120);
        // add_option() can use an upsert after a cached existence check; a lock must never replace another owner.
        $insert = static fn() => $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::LOCK, $token));
        if ($insert() === 1) { wp_cache_delete(self::LOCK, 'options'); return $token; }
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", self::LOCK));
        if (is_string($old) && preg_match('/:(\d+)$/D', $old, $match) && (int) $match[1] < time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND BINARY option_value=%s", self::LOCK, $old));
            wp_cache_delete(self::LOCK, 'options');
            if ($insert() === 1) { wp_cache_delete(self::LOCK, 'options'); return $token; }
        }
        return new WP_Error('fg_settings_busy', 'Another settings change is being saved. Wait a moment, reload this page, and try again.', ['status'=>409]);
    }
    private static function unlock(string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND BINARY option_value=%s", self::LOCK, $token));
        wp_cache_delete(self::LOCK, 'options');
    }
    public static function save_section(string $section, array $values, string $expected) {
        $keys = $section === 'finance' ? ['finance_mappings'] : ['oauth_enabled', 'writes', 'write_mode', 'sensitive', 'origins'];
        if (!in_array($section, ['connection', 'finance'], true) || array_diff(array_keys($values), $keys)) {
            return new WP_Error('fg_settings_section', 'This form cannot change settings in another section.', ['status'=>400]);
        }
        if (array_key_exists('write_mode', $values) && !in_array($values['write_mode'], ['reviewed', 'yolo'], true)) {
            return new WP_Error('fg_settings_mode', 'Choose Read Only, Reviewed Changes, or YOLO Mode.', ['status'=>400]);
        }
        return self::mutate($section, $expected, static fn($current) => array_replace($current, $values));
    }
    /** Apply one validated mapping delta to the fresh mapping collection, never a cached copy. */
    public static function mutate_finance(string $gateway, array $map, string $intent, string $expected) {
        if (!in_array($intent, ['create', 'edit', 'remove'], true)) { return new WP_Error('invalid_intent', 'Choose a valid mapping action.'); }
        return self::mutate('finance', $expected, static function ($current) use ($gateway, $map, $intent) {
            $mappings = $current['finance_mappings'];
            if (!is_array($mappings)) { return new WP_Error('invalid_saved_mappings', 'Saved finance mappings could not be read. Reload and retry.'); }
            if ($intent === 'create' && array_key_exists($gateway, $mappings)) { return new WP_Error('duplicate_gateway', 'This gateway already has a mapping. Open its Edit section to change it.'); }
            if ($intent !== 'create' && !array_key_exists($gateway, $mappings)) { return new WP_Error('mapping_missing', 'This mapping no longer exists. Reload Finance Setup.'); }
            if ($intent === 'remove') { unset($mappings[$gateway]); } else { $mappings[$gateway] = $map; }
            if (count($mappings) > 20) { return new WP_Error('mapping_limit', 'This version supports up to 20 configured gateways. Remove an unused mapping first.'); }
            $current['finance_mappings'] = $mappings;
            return $current;
        });
    }
    public static function enable_for_user(int $user_id, string $expected) {
        if (!$user_id || !user_can($user_id, 'manage_options') || !user_can($user_id, 'read') || (is_multisite() && !is_user_member_of_blog($user_id))) {
            return new WP_Error('fg_settings_account', 'Your administrator account must belong to this site before enabling MCP.', ['status'=>403]);
        }
        return self::mutate('connection', $expected, static function ($current) use ($user_id) {
            $current['enabled'] = true;
            $current['oauth_enabled'] = true;
            $current['users'] = array_values(array_unique(array_merge(array_map('intval', $current['users']), [$user_id])));
            return $current;
        });
    }
    public static function disable(string $expected) {
        return self::mutate('connection', $expected, static function ($current) { $current['enabled'] = false; return $current; });
    }
    public static function remove_account(int $user_id, string $expected) {
        if ($user_id < 1) { return new WP_Error('fg_settings_account', 'Choose an existing account to remove.', ['status'=>400]); }
        return self::mutate('connection', $expected, static function ($current) use ($user_id) {
            $current['users'] = array_values(array_filter(array_map('intval', $current['users']), static fn($id) => $id !== $user_id));
            return $current;
        });
    }
    private static function mutate(string $section, string $expected, callable $change) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) { return new WP_Error('fg_settings_stale', 'Reload this page before saving. Its settings version is missing or invalid.', ['status'=>409]); }
        $lock = self::lock();
        if (is_wp_error($lock)) { return $lock; }
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                [$raw, $current] = self::fresh();
                if (!hash_equals(self::digest($section, $current), $expected)) {
                    return new WP_Error('fg_settings_stale', 'These settings changed in another tab. Your input is preserved below. Review it against the current settings before saving again.', ['status'=>409]);
                }
                $next = $change($current);
                if (is_wp_error($next)) { return $next; }
                $pending = !empty($current['_oauth_revoke_pending']);
                if ($next === $current && !$pending) { return ['changed'=>false, 'settings'=>$current]; }

                // A prior incomplete disable can never be undone before old credentials are invalidated.
                if ($pending && !empty($next['enabled']) && !empty($next['oauth_enabled'])) {
                    if (!FG_OAuth::revoke_all()) {
                        return new WP_Error('fg_settings_revocation', 'Access has not been re-enabled. Existing OAuth access could not be revoked. Resolve the storage error and retry enabling MCP.', ['status'=>503]);
                    }
                    unset($next['_oauth_revoke_pending']);
                }
                $disabling = (!empty($current['enabled']) && empty($next['enabled'])) || (!empty($current['oauth_enabled']) && empty($next['oauth_enabled']));
                if ($disabling || $pending && (empty($next['enabled']) || empty($next['oauth_enabled']))) { $next['_oauth_revoke_pending'] = true; }
                $next['_policy_revision'] = wp_generate_uuid4();
                $written = self::compare_write($raw, $next);
                if ($written === false) { throw new RuntimeException('Settings could not be saved.'); }
                if ($written !== 1) { continue; }

                global $wpdb;
                $approvals = $wpdb->query('UPDATE ' . FG_Core::table('changes') . " SET status='revoked' WHERE status IN ('pending','reviewing','approved','ready')");
                $revoked = empty($next['_oauth_revoke_pending']) || FG_OAuth::revoke_all();
                if (!$revoked || $approvals === false) {
                    return new WP_Error('fg_settings_cleanup', 'Settings were saved, but cleanup could not be completed. Older change requests are invalid. If you turned MCP or OAuth off, access remains disabled. Resolve the storage error and retry.', ['status'=>503, 'saved'=>true]);
                }
                if (!empty($next['_oauth_revoke_pending'])) {
                    [$cleanup_raw, $cleanup] = self::fresh();
                    if (($cleanup['_policy_revision'] ?? '') !== $next['_policy_revision']) { throw new RuntimeException('Settings changed while finishing cleanup.'); }
                    unset($cleanup['_oauth_revoke_pending']);
                    if (self::compare_write($cleanup_raw, $cleanup) !== 1) { throw new RuntimeException('Settings cleanup could not be confirmed.'); }
                    $next = $cleanup;
                }
                FG_Core::audit($section === 'finance' ? 'gateway_finance_settings' : 'gateway_settings', 'updated', '', ['auth_source'=>'wordpress_admin']);
                return ['changed'=>true, 'settings'=>$next];
            }
            return new WP_Error('fg_settings_stale', 'Settings changed while saving. Reload this page and try again.', ['status'=>409]);
        } catch (RuntimeException $error) {
            return new WP_Error('fg_settings_storage', 'The settings change could not be confirmed. Reload the page and check the connection status before retrying.', ['status'=>503]);
        } finally { self::unlock($lock); }
    }
}
