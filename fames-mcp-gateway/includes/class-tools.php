<?php
defined('ABSPATH') || exit;

final class FG_Tools {
    private static array $tools = [];
    public static function register(string $name, string $description, array $schema, callable $handler, bool $mutating = false, string $capability = 'edit_posts', bool $sensitive = false): void {
        self::$tools[$name] = compact('name', 'description', 'schema', 'handler', 'mutating', 'capability', 'sensitive');
    }
    public static function schema(array $properties = [], array $required = []): array {
        return ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false];
    }
    public static function id(): array { return ['type' => 'integer', 'minimum' => 1]; }
    public static function pagination(): array {
        return ['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            'search' => ['type' => 'string', 'maxLength' => 200]];
    }
    public static function definition(string $name): array {
        if (!isset(self::$tools[$name])) { throw new FG_Failure('unknown_tool', 'Unknown or unavailable tool.'); }
        return self::$tools[$name];
    }
    /** Effective execution mode for this authenticated connection. */
    public static function write_mode(): string {
        if (method_exists('FG_Auth', 'write_mode')) { return FG_Auth::write_mode(); }
        // Standalone legacy module fixtures may not load the authentication module.
        return !empty(FG_Core::settings()['writes']) ? 'reviewed' : 'read_only';
    }
    public static function permitted(array $tool): bool {
        $settings = FG_Core::settings();
        return current_user_can($tool['capability']) && (!$tool['sensitive'] || $settings['sensitive']) &&
            (!class_exists('FG_Auth') || FG_Auth::allows_tool($tool));
    }
    public static function validate(string $name, array $args): array {
        $tool = self::definition($name);
        if (!self::permitted($tool)) { throw new FG_Failure('forbidden', 'Your role or the gateway privacy settings do not allow this operation.'); }
        if (isset($args['kind']) && in_array($args['kind'], ['posts', 'pages'], true) && !current_user_can($args['kind'] === 'pages' ? 'edit_pages' : 'edit_posts')) {
            throw new FG_Failure('forbidden', 'Your WordPress role cannot manage this content type.');
        }
        $valid = rest_validate_value_from_schema($args, $tool['schema'], 'arguments');
        if (is_wp_error($valid)) { throw new FG_Failure('invalid_arguments', $valid->get_error_message()); }
        return $tool;
    }
    public static function catalog(): array {
        $list = [];
        $mode = self::write_mode();
        foreach (self::$tools as $tool) {
            if (!self::permitted($tool)) { continue; }
            if ($tool['mutating'] && $mode === 'read_only') { continue; }
            $description = $tool['description'];
            if ($tool['mutating']) {
                $description = ($mode === 'yolo'
                    ? 'YOLO Mode: this call applies the change immediately without WordPress dashboard approval. Never retry an uncertain write; inspect its change_id and the target first. '
                    : 'Reviewed Changes: this call only proposes a change. Approve it in WordPress, then call gateway_apply_change once. ') . $description;
            }
            $list[] = ['name' => $tool['name'], 'description' => $description, 'inputSchema' => $tool['schema'],
                'annotations' => ['readOnlyHint' => !$tool['mutating'], 'destructiveHint' => $tool['mutating'],
                    'idempotentHint' => !$tool['mutating'], 'openWorldHint' => false]];
        }
        return $list;
    }
    public static function call(string $name, array $args) {
        $tool = self::validate($name, $args);
        if ($tool['mutating']) {
            $mode = self::write_mode();
            if ($mode === 'read_only') { throw new FG_Failure('read_only', 'Changes are disabled for this connection.'); }
            return $mode === 'yolo' ? FG_Approvals::execute_direct($name, $args) : FG_Approvals::propose($name, $args);
        }
        if (!FG_Core::audit($name, 'read')) { throw new FG_Failure('audit_unavailable', 'Audit storage is unavailable.'); }
        return self::checked_result(($tool['handler'])($args));
    }
    public static function execute_approved(string $name, array $args) {
        $tool = self::validate($name, $args);
        if (!$tool['mutating']) { throw new FG_Failure('invalid_change', 'Only registered write operations can be approved.'); }
        return self::checked_result(($tool['handler'])($args));
    }
    private static function checked_result($result) {
        if (is_wp_error($result)) { throw new FG_Failure('wordpress_error', $result->get_error_message()); }
        return $result;
    }
    public static function rest(string $method, string $path, array $params = []): array {
        // Paths are constructed only in typed handlers; no path is accepted from MCP input.
        $request = new WP_REST_Request($method, $path);
        if ($method === 'GET') { $request->set_query_params($params); }
        else { $request->set_body_params($params); }
        $response = rest_do_request($request);
        if (is_wp_error($response)) { throw new FG_Failure('wordpress_error', $response->get_error_message()); }
        // Internal dispatch does not run the outer REST server's post-dispatch field filter.
        $response = rest_filter_response_fields($response, rest_get_server(), $request);
        $data = $response->get_data();
        if ($response->get_status() >= 400) {
            $message = is_array($data) && isset($data['message']) ? wp_strip_all_tags($data['message']) : 'The site rejected this operation.';
            throw new FG_Failure('wordpress_error', substr($message, 0, 500));
        }
        $result = ['data' => $data];
        $headers = array_change_key_case($response->get_headers(), CASE_LOWER);
        if (isset($headers['x-wp-total'])) {
            $result['pagination'] = ['total' => (int) $headers['x-wp-total'], 'total_pages' => (int) ($headers['x-wp-totalpages'] ?? 1),
                'page' => (int) ($params['page'] ?? 1), 'per_page' => (int) ($params['per_page'] ?? 10)];
        }
        return $result;
    }
}
