<?php
defined('ABSPATH') || exit;

final class FG_Approvals {
    private static function policy_revision(): string {
        if (class_exists('FG_Settings')) { return FG_Settings::policy_revision(); }
        $settings = FG_Core::settings();
        return is_string($settings['_policy_revision'] ?? null) ? $settings['_policy_revision'] : 'legacy';
    }
    public static function is_design(string $name): bool {
        return in_array($name, ['wp_page_design_create', 'wp_page_design_update'], true);
    }
    public static function context(array $row): array {
        $raw = $row['server_context'] ?? '';
        $context = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($context) || ($context['version'] ?? 0) !== 1 || !is_string($context['policy_revision'] ?? null) || $context['policy_revision'] === '') {
            throw new FG_Failure('invalid_context', 'This request predates the current change protections. Submit a new request.');
        }
        return $context;
    }
    private static function assert_current_policy(array $context): void {
        $current = self::policy_revision();
        if ($current === '' || !hash_equals($context['policy_revision'], $current)) {
            throw new FG_Failure('policy_changed', 'Gateway settings changed after this request. Submit a new request.');
        }
    }
    private static function assert_integrity(array $row): array {
        $context = self::context($row);
        if (!hash_equals($row['digest'], hash('sha256', $row['arguments'] . '|' . $row['server_context']))) {
            throw new FG_Failure('invalid_change', 'Stored change integrity check failed.');
        }
        self::assert_current_policy($context);
        return $context;
    }
    public static function propose(string $name, array $args): array {
        $row = self::store($name, $args, 'reviewed');
        return ['change_id' => $row['id'], 'status' => 'pending', 'execution_mode'=>'reviewed', 'expires_at' => gmdate('c', $row['expires']),
            'review_url' => admin_url('options-general.php?page=jalin-mcp-gateway&tab=changes'),
            'proposed_arguments' => json_decode($row['arguments'], true),
            'next_step' => 'A site administrator must review and approve these exact values in WordPress, then call gateway_apply_change with change_id. No content or order has been changed yet.'];
    }
    public static function execute_direct(string $name, array $args): array {
        $row = self::store($name, $args, 'yolo');
        try { return self::execute($row['id'], true); }
        catch (Throwable $error) {
            global $wpdb;
            // A pre-claim failure must not leave an executable direct request behind.
            $marked = $wpdb->update(FG_Core::table('changes'), ['status'=>'failed'], ['id'=>$row['id'], 'status'=>'ready']);
            if ($marked === 1) { FG_Core::audit($name, 'yolo_failed_check_site', $row['id']); }
            throw new FG_Failure($error instanceof FG_Failure ? $error->reason : 'execution_failed', $error->getMessage() . ' Change ID: ' . $row['id'] . '. Check gateway_change_status and the target before making another write.');
        }
    }
    /** Capture mode and policy generation from one fresh settings read. */
    private static function execution_snapshot(): array {
        if (class_exists('FG_Settings')) {
            $snapshot = FG_Settings::snapshot('connection');
            if (is_wp_error($snapshot)) { throw new FG_Failure('storage_error', 'Current access settings could not be read.'); }
            return $snapshot['settings'];
        }
        return FG_Core::settings();
    }
    private static function assert_direct_access(array $context): void {
        $settings = self::execution_snapshot();
        $policy = is_string($settings['_policy_revision'] ?? null) ? $settings['_policy_revision'] : 'legacy';
        if (!hash_equals($context['policy_revision'], $policy) || empty($settings['enabled']) || empty($settings['writes']) ||
            ($settings['write_mode'] ?? 'reviewed') !== 'yolo' || !in_array(get_current_user_id(), array_map('intval', $settings['users']), true) ||
            FG_Tools::write_mode() !== 'yolo' || FG_Auth::credential_id() === '') {
            throw new FG_Failure('policy_changed', 'YOLO access or gateway settings changed. Read the current mode before making another request.');
        }
    }
    private static function store(string $name, array $args, string $mode): array {
        $tool = FG_Tools::validate($name, $args);
        if (!$tool['mutating']) { throw new FG_Failure('invalid_change', 'Only registered write operations can create a change.'); }
        $settings = self::execution_snapshot();
        if (empty($settings['writes'])) { throw new FG_Failure('read_only', 'Write requests are disabled in Settings > JalinWP.'); }
        $policy = is_string($settings['_policy_revision'] ?? null) ? $settings['_policy_revision'] : 'legacy';
        if ($policy === '') { throw new FG_Failure('storage_error', 'Current access policy could not be read. Nothing was changed.'); }
        $context = ['version'=>1, 'policy_revision'=>$policy, 'execution_mode'=>$mode,
            'request_context'=>method_exists('FG_Auth', 'audit_context') ? FG_Auth::audit_context() : []];
        if ($mode === 'yolo') {
            // The mode and generation in the captured snapshot must agree; a later fresh mode alone is insufficient.
            if (($settings['write_mode'] ?? 'reviewed') !== 'yolo') { throw new FG_Failure('policy_changed', 'YOLO mode is no longer enabled.'); }
            self::assert_direct_access($context);
        }
        if (self::is_design($name)) {
            if (!class_exists('FG_Page_Design')) { throw new FG_Failure('unavailable', 'Page design tools are unavailable.'); }
            $args = FG_Page_Design::prepare($name, $args);
            FG_Tools::validate($name, $args);
            $context['design'] = FG_Page_Design::proposal_context($name, $args);
        }
        global $wpdb;
        $id = wp_generate_uuid4();
        $encoded = wp_json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $server_context = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded || false === $server_context || strlen($encoded) > 200000 || strlen($encoded) + strlen($server_context) > 400000) { throw new FG_Failure('too_large', 'This change exceeds the request size limit. Use a smaller design.'); }
        $row = [
            'id' => $id, 'user_id' => get_current_user_id(), 'credential_id' => FG_Auth::credential_id(),
            'tool' => $name, 'arguments' => $encoded, 'server_context'=>$server_context,
            'digest' => hash('sha256', $encoded . '|' . $server_context),
            'status' => $mode === 'yolo' ? 'ready' : 'pending', 'created' => time(), 'expires' => time() + 15 * MINUTE_IN_SECONDS,
        ];
        if (false === $wpdb->insert(FG_Core::table('changes'), $row)) { throw new FG_Failure('storage_error', 'Could not store this request; nothing was changed.'); }
        if (!FG_Core::audit($name, $mode === 'yolo' ? 'yolo_requested' : 'proposed', $id)) {
            $wpdb->delete(FG_Core::table('changes'), ['id' => $id]);
            throw new FG_Failure('audit_unavailable', 'Audit storage is unavailable; nothing was changed.');
        }
        return $row;
    }
    public static function get_owned(string $id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FG_Core::table('changes') . ' WHERE id=%s AND user_id=%d AND credential_id=%s',
            $id, get_current_user_id(), FG_Auth::credential_id()), ARRAY_A);
        if (!$row) { throw new FG_Failure('not_found', 'This change does not exist or belongs to a different connection.'); }
        return $row;
    }
    public static function status(string $id): array {
        $row = self::get_owned($id);
        $context = json_decode((string) ($row['server_context'] ?? ''), true);
        return ['change_id' => $id, 'tool' => $row['tool'], 'execution_mode'=>$context['execution_mode'] ?? 'reviewed', 'status' => time() >= (int) $row['expires'] && in_array($row['status'], ['pending', 'approved', 'ready'], true) ? 'expired' : $row['status'],
            'expires_at' => gmdate('c', (int) $row['expires'])];
    }
    public static function apply(string $id) {
        return self::execute($id, false);
    }
    private static function execute(string $id, bool $direct) {
        if (!FG_Core::settings()['writes']) { throw new FG_Failure('read_only', 'Write requests are disabled.'); }
        global $wpdb;
        $row = self::get_owned($id);
        if (time() >= (int) $row['expires']) { throw new FG_Failure('expired', 'This change expired. Submit a new request.'); }
        $expected_status = $direct ? 'ready' : 'approved';
        if ($row['status'] !== $expected_status) { throw new FG_Failure('not_approved', 'Current status: ' . $row['status'] . '. This change cannot execute again.'); }
        $context = self::assert_integrity($row);
        if (($context['execution_mode'] ?? 'reviewed') !== ($direct ? 'yolo' : 'reviewed')) { throw new FG_Failure('invalid_change', 'This change belongs to a different execution mode.'); }
        if ($direct) { self::assert_direct_access($context); }
        $args = json_decode($row['arguments'], true, 64, JSON_THROW_ON_ERROR);
        FG_Tools::validate($row['tool'], $args);
        if (self::is_design($row['tool'])) { FG_Page_Design::check_preconditions($row['tool'], $args, $context['design'] ?? []); }
        // Compare-and-swap prevents concurrent requests and retries from executing twice.
        $claimed = $wpdb->query($wpdb->prepare('UPDATE ' . FG_Core::table('changes') . " SET status='executing' WHERE id=%s AND user_id=%d AND credential_id=%s AND status=%s AND expires>%d",
            $id, get_current_user_id(), FG_Auth::credential_id(), $expected_status, time()));
        if ($claimed !== 1) { throw new FG_Failure('already_claimed', 'This change was already claimed, revoked, or expired. Inspect the order/content before submitting another request.'); }
        if (!FG_Core::audit($row['tool'], $direct ? 'yolo_execution_started' : 'execution_started', $id)) {
            $wpdb->update(FG_Core::table('changes'), ['status' => 'failed'], ['id' => $id]);
            throw new FG_Failure('audit_unavailable', 'Audit storage is unavailable; the change was not executed.');
        }
        try {
            self::assert_current_policy($context);
            if ($direct) { self::assert_direct_access($context); }
            $result = self::is_design($row['tool'])
                ? FG_Page_Design::execute_prepared($row['tool'], $args, $context['design'])
                : FG_Tools::execute_approved($row['tool'], $args);
            if (is_wp_error($result)) { throw new FG_Failure('wordpress_error', $result->get_error_message()); }
            $saved = $wpdb->update(FG_Core::table('changes'), ['status' => 'applied'], ['id' => $id]);
            $logged = FG_Core::audit($row['tool'], $direct ? 'yolo_applied' : 'applied', $id);
            return ['change_id' => $id, 'status' => 'applied', 'execution_mode'=>$direct ? 'yolo' : 'reviewed', 'result' => $result,
                'audit_status' => false !== $saved && $logged ? 'recorded' : 'recording_failed_do_not_retry'];
        } catch (Throwable $error) {
            // WP/WC hooks can fail after a partial side effect. Never make this retriable.
            $wpdb->update(FG_Core::table('changes'), ['status' => 'failed'], ['id' => $id]);
            FG_Core::audit($row['tool'], $direct ? 'yolo_failed_check_site' : 'failed_check_site', $id);
            throw new FG_Failure('execution_failed', 'Execution failed and may have partially changed the site. Inspect the target in WordPress before creating a new request.');
        }
    }
    public static function review(string $id, string $decision, string $design_validation = ''): void {
        if (!current_user_can('manage_options') || !in_array($decision, ['approved', 'rejected'], true)) { throw new FG_Failure('forbidden', 'Administrator review is required.'); }
        global $wpdb;
        if ($decision === 'approved') {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FG_Core::table('changes') . ' WHERE id=%s', $id), ARRAY_A);
            if (!$row) { throw new FG_Failure('not_found', 'This change request was not found.'); }
            $context = self::assert_integrity($row);
            if (self::is_design($row['tool'])) {
                $args = json_decode($row['arguments'], true, 64, JSON_THROW_ON_ERROR);
                FG_Page_Design::check_preconditions($row['tool'], $args, $context['design'] ?? []);
                if (array_key_exists('blocks', $args) && ($design_validation === '' || !hash_equals((string) ($context['design']['content_sha256'] ?? ''), $design_validation))) {
                    throw new FG_Failure('design_validation_required', 'Open this design in Changes & Review and wait for the installed Gutenberg editor to validate it before approving.');
                }
            }
        }
        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . FG_Core::table('changes') . ' SET status=%s,reviewer_id=%d WHERE id=%s AND status=%s AND expires>%d',
            'reviewing', get_current_user_id(), $id, 'pending', time()));
        if ($changed !== 1) { throw new FG_Failure('expired', 'This request was already reviewed or has expired.'); }
        if (!FG_Core::audit('gateway_review', $decision, $id, ['auth_source'=>'wordpress_admin'])) {
            $wpdb->update(FG_Core::table('changes'), ['status' => 'rejected'], ['id' => $id, 'status' => 'reviewing']);
            throw new FG_Failure('audit_unavailable', 'Audit recording failed. The request was rejected.');
        }
        $finalized = $wpdb->update(FG_Core::table('changes'), ['status' => $decision], ['id' => $id, 'status' => 'reviewing']);
        if ($finalized !== 1) { throw new FG_Failure('storage_error', 'Could not finalize review. This change is not executable.'); }
    }
}
