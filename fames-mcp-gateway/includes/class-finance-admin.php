<?php
defined('ABSPATH') || exit;

/** Site-admin finance configuration. Never enumerates or dumps order metadata. */
final class FG_Finance_Admin {
    private const KEYS = ['fee_key', 'net_key', 'currency_key', 'affiliate_id_key', 'commission_key', 'commission_currency_key'];
    private const DIVISORS = ['fee_divisor', 'net_divisor', 'commission_divisor'];

    public static function boot(): void {
        add_action('admin_post_fg_finance_save', [self::class, 'save']);
        add_action('admin_post_fg_finance_remove', [self::class, 'remove']);
        add_action('wp_ajax_fg_finance_preview', [self::class, 'preview_ajax']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook !== 'settings_page_fames-mcp-gateway' || ($_GET['tab'] ?? '') !== 'finance') { return; }
            wp_enqueue_style('fames-mcp-finance', plugins_url('assets/finance.css', FG_FILE), ['fames-mcp'], FG_VERSION);
            wp_enqueue_script('fames-mcp-finance', plugins_url('assets/finance.js', FG_FILE), [], FG_VERSION, true);
            wp_localize_script('fames-mcp-finance', 'fgFinance', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('fg_finance_preview')]);
        });
    }

    private static function url(): string { return admin_url('options-general.php?page=fames-mcp-gateway&tab=finance'); }

    /** Configuration needs admin + WC permissions; reading a sample additionally needs sensitive access. */
    public static function permission(bool $sample = false, int $order_id = 0) {
        if (!current_user_can('manage_options') || !current_user_can('manage_woocommerce')) {
            return new WP_Error('finance_forbidden', 'Administrator and WooCommerce management permissions are required.');
        }
        if (!function_exists('WC') || !function_exists('wc_get_order') || !function_exists('wc_rest_check_post_permissions')) {
            return new WP_Error('woocommerce_inactive', 'Activate WooCommerce to configure or test finance mappings.');
        }
        if (!wc_rest_check_post_permissions('shop_order', 'read', $order_id)) {
            return new WP_Error('order_forbidden', 'Your WordPress account cannot read this order data.');
        }
        if ($sample && empty(FG_Core::settings()['sensitive'])) {
            return new WP_Error('sensitive_access_disabled', 'Enable Order, Customer, Comment, and Financial Data Access in Access Controls before testing an order.');
        }
        return true;
    }

    private static function guard(string $nonce): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('POST required.', '', ['response' => 405]); }
        if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
        check_admin_referer($nonce);
        $permission = self::permission();
        if (is_wp_error($permission)) { wp_die(esc_html($permission->get_error_message()), 'Finance Access Unavailable', ['response' => 403, 'back_link' => true]); }
    }

    /** Preserve exact case, custom gateway IDs and supported divisors. Reject rather than coerce errors. */
    public static function validate_mapping($gateway, $input, bool $allow_empty = false) {
        if (!is_string($gateway) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $gateway)) {
            return new WP_Error('invalid_gateway', 'Gateway ID must contain 1–100 letters, numbers, underscores or hyphens.');
        }
        if (!is_array($input) || count($input) > 9 || array_diff(array_keys($input), array_merge(self::KEYS, self::DIVISORS))) {
            return new WP_Error('invalid_mapping', 'Only the displayed finance mapping fields are supported.');
        }
        $map = [];
        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value)) { return new WP_Error('invalid_metadata_key', 'Metadata keys must be text.'); }
            $value = trim($value);
            if ($value !== '' && (!preg_match('/^[a-zA-Z0-9_.:-]{1,150}$/D', $value) || preg_match('/(?:password|secret|token|api.?key|authorization|card.?number|cvv|cvc|private.?key|client.?secret|access.?key|credit.?card)/i', $value))) {
                return new WP_Error('invalid_metadata_key', 'Use exact finance metadata keys of up to 150 characters. Credential, card and secret fields cannot be exposed.');
            }
            $map[$key] = $value;
        }
        foreach (self::DIVISORS as $key) {
            $value = $input[$key] ?? 1;
            if ((!is_int($value) && !is_string($value)) || !in_array((string) $value, ['1', '100', '1000'], true)) {
                return new WP_Error('invalid_divisor', 'Choose a supported amount unit: major units (1), hundredths (100), or thousandths (1000).');
            }
            $map[$key] = (int) $value;
        }
        if (!$allow_empty && !array_filter(array_intersect_key($map, array_flip(['fee_key', 'net_key', 'affiliate_id_key', 'commission_key'])))) {
            return new WP_Error('empty_mapping', 'Enter a Processing Fee, Gateway Net Amount, Affiliate ID or Commission metadata key. Use Remove Mapping to delete an existing mapping.');
        }
        return $map;
    }

    /** Lists actual registered gateways, including disabled gateways and every saved legacy/custom ID. */
    public static function gateways(array $saved): array {
        $rows = [];
        if (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
            foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
                $id = (string) $gateway->id;
                $label = wp_strip_all_tags((string) $gateway->get_title());
                if ($label === '') { $label = wp_strip_all_tags((string) $gateway->get_method_title()); }
                $rows[$id] = ['name' => $label ?: $id, 'registered' => true, 'enabled' => $gateway->enabled === 'yes'];
            }
        }
        foreach ($saved as $id => $map) {
            if (!isset($rows[$id])) { $rows[$id] = ['name' => (string) $id, 'registered' => false, 'enabled' => false]; }
        }
        return $rows;
    }

    /** One explicit upsert/delete at a time. A section fingerprint prevents stale finance writes. */
    public static function persist(string $gateway, array $map, string $expected, string $intent) {
        if (!in_array($intent, ['create', 'edit', 'remove'], true)) { return new WP_Error('invalid_intent', 'Choose a valid mapping action.'); }
        return FG_Settings::mutate_finance($gateway, $map, $intent, $expected);
    }

    public static function save(): void {
        self::guard('fg_finance_save');
        $gateway = is_string($_POST['gateway'] ?? null) ? trim(wp_unslash($_POST['gateway'])) : '';
        $input = isset($_POST['mapping']) && is_array($_POST['mapping']) ? wp_unslash($_POST['mapping']) : [];
        $expected = is_string($_POST['finance_version'] ?? null) ? wp_unslash($_POST['finance_version']) : '';
        $intent = is_string($_POST['intent'] ?? null) ? $_POST['intent'] : '';
        $result = self::validate_mapping($gateway, $input);
        if (!is_wp_error($result)) { $result = self::persist($gateway, $result, $expected, $intent); }
        if (is_wp_error($result)) { self::return_error($result, $gateway, $input, $intent, $expected); }
        wp_safe_redirect(add_query_arg('finance_saved', !empty($result['changed']) ? 'changed' : 'unchanged', self::url()));
        exit;
    }

    public static function remove(): void {
        self::guard('fg_finance_remove');
        $gateway = is_string($_POST['gateway'] ?? null) ? wp_unslash($_POST['gateway']) : '';
        $expected = is_string($_POST['finance_version'] ?? null) ? wp_unslash($_POST['finance_version']) : '';
        // Removal may also clean up an old invalid gateway ID. It can only target an existing exact key.
        $result = self::persist($gateway, [], $expected, 'remove');
        if (is_wp_error($result)) { self::return_error($result); }
        wp_safe_redirect(add_query_arg('finance_saved', 'removed', self::url()));
        exit;
    }

    private static function return_error(WP_Error $error, string $gateway = '', array $input = [], string $intent = '', string $expected = ''): void {
        $draft = [];
        foreach (array_merge(self::KEYS, self::DIVISORS) as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) { $draft[$key] = substr((string) $input[$key], 0, 150); }
        }
        $id = wp_generate_uuid4();
        $data = $error->get_error_data();
        set_transient('fg_finance_error_' . get_current_user_id() . '_' . $id, ['message' => $error->get_error_message(), 'code' => $error->get_error_code(), 'saved' => is_array($data) && !empty($data['saved']), 'gateway' => substr($gateway, 0, 100), 'input' => $draft, 'intent' => in_array($intent, ['create', 'edit', 'remove'], true) ? $intent : '', 'version' => substr($expected, 0, 64)], 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('finance_error', $id, self::url()));
        exit;
    }

    public static function preview_ajax(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(['message' => 'POST required.'], 405); }
        if (!current_user_can('manage_options') || !check_ajax_referer('fg_finance_preview', 'nonce', false)) {
            wp_send_json_error(['message' => 'Administrator access and a valid request are required.'], 403);
        }
        $id = $_POST['order_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/D', $id)) { wp_send_json_error(['message' => 'Enter a valid Order ID.'], 400); }
        $gateway = is_string($_POST['gateway'] ?? null) ? trim(wp_unslash($_POST['gateway'])) : '';
        $input = isset($_POST['mapping']) && is_array($_POST['mapping']) ? wp_unslash($_POST['mapping']) : [];
        $result = self::preview((int) $id, $gateway, $input);
        if (is_wp_error($result)) {
            $forbidden = in_array($result->get_error_code(), ['finance_forbidden', 'order_forbidden', 'sensitive_access_disabled'], true);
            wp_send_json_error(['message' => $result->get_error_message()], $forbidden ? 403 : 400);
        }
        nocache_headers();
        wp_send_json_success($result);
    }

    /** Reads only selected fields; never swaps options, saves orders, or records a misleading global test stamp. */
    public static function preview(int $order_id, string $gateway, array $input) {
        $permission = self::permission(true, $order_id);
        if (is_wp_error($permission)) { return $permission; }
        $map = self::validate_mapping($gateway, $input, true);
        if (is_wp_error($map)) { return $map; }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_type() !== 'shop_order') { return new WP_Error('order_not_found', 'A standard WooCommerce order was not found.'); }
        try { $values = FG_Analytics::mapped_finance($order, $map); }
        catch (Throwable $error) { return new WP_Error('finance_read_failed', 'The selected finance fields could not be read. Check the mapping and any site affiliate adapter.'); }
        $rows = [];
        foreach (['fee' => 'Processing Fee', 'net' => 'Gateway Net Amount'] as $field => $label) {
            $rows[] = self::amount_row($label, $values['gateway_finance'][$field]);
        }
        $rows[] = self::currency_row($order, 'Settlement Currency', $map['currency_key']);
        $affiliate = $values['affiliate'];
        // Adapter output has no stored-meta divisor; a caller-controlled source label is not evidence.
        $adapted = !array_key_exists('stored_amount_divisor', $affiliate['commission']);
        $id_value = $affiliate['affiliate_id'];
        $id_status = $id_value !== null ? 'available' : (($adapted || $map['affiliate_id_key'] !== '') ? 'missing' : 'not_configured');
        if (!$adapted && $map['affiliate_id_key'] !== '' && $id_value === null) {
            $raw = $order->get_meta($map['affiliate_id_key'], true);
            if ($raw !== '' && $raw !== null) { $id_status = 'invalid_value'; }
        }
        $rows[] = ['field' => 'Affiliate ID', 'value' => $id_value, 'status' => $id_status, 'source' => $adapted ? 'Site Affiliate Adapter' : ($map['affiliate_id_key'] ?: null), 'divisor' => null, 'currency' => null, 'currency_status' => null];
        $commission = self::amount_row('Affiliate Commission', $affiliate['commission']);
        if ($adapted) { $commission['source'] = 'Site Affiliate Adapter'; }
        $rows[] = $commission;
        $rows[] = $adapted
            ? ['field' => 'Commission Currency', 'value' => $affiliate['commission']['currency'], 'status' => $affiliate['commission']['currency'] !== null ? 'available' : 'invalid_value', 'source' => 'Site Affiliate Adapter', 'divisor' => null, 'currency' => null, 'currency_status' => null]
            : self::currency_row($order, 'Commission Currency', $map['commission_currency_key']);
        return ['order_id' => $order_id, 'selected_gateway' => $gateway, 'order_gateway' => (string) $order->get_payment_method(), 'gateway_matches' => $gateway === (string) $order->get_payment_method(), 'rows' => $rows, 'affiliate_adapter' => $adapted, 'saved' => false, 'note' => 'This tests the current unsaved fields on one order. Amounts use major currency units and preserve signs. Missing values are unknown, not zero. It does not verify gateway statements or historical coverage.'];
    }

    private static function amount_row(string $label, array $value): array {
        $status = $value['availability'];
        if (in_array($status, ['invalid_numeric_value', 'missing_or_invalid'], true)) { $status = 'invalid_value'; }
        return ['field' => $label, 'value' => $value['amount'], 'status' => $status, 'source' => $value['source_meta_key'], 'divisor' => $value['stored_amount_divisor'] ?? 1, 'currency' => $value['currency'], 'currency_status' => $value['currency_availability']];
    }

    private static function currency_row($order, string $label, string $key): array {
        $raw = $key !== '' ? $order->get_meta($key, true) : $order->get_currency();
        $currency = is_string($raw) && preg_match('/^[A-Za-z]{3}$/D', $raw) ? strtoupper($raw) : null;
        $status = $currency !== null ? ($key === '' ? 'assumed_order_currency' : 'available') : (($raw === '' || $raw === null) ? 'missing' : 'invalid_value');
        return ['field' => $label, 'value' => $currency, 'status' => $status, 'source' => $key ?: 'Order Currency', 'divisor' => null, 'currency' => null, 'currency_status' => null];
    }

    public static function render(array $settings): void {
        $snapshot = FG_Settings::snapshot('finance');
        if (is_wp_error($snapshot)) {
            echo '<section class="fg-card"><h2>Finance Setup Could Not Be Loaded</h2><p>' . esc_html($snapshot->get_error_message()) . '</p></section>';
            return;
        }
        $settings = $snapshot['settings'];
        $version = $snapshot['version'];
        $saved = is_array($settings['finance_mappings'] ?? null) ? $settings['finance_mappings'] : [];
        $error = null;
        $error_id = is_string($_GET['finance_error'] ?? null) ? $_GET['finance_error'] : '';
        if (preg_match('/^[a-f0-9-]{36}$/D', $error_id)) {
            $error = get_transient('fg_finance_error_' . get_current_user_id() . '_' . $error_id);
        }
        ?>
        <section class="fg-card fg-finance-intro">
            <h2>Finance Setup</h2>
            <p>WooCommerce already provides most of your sales data. Configure optional mappings only for additional costs recorded by your payment or affiliate plugins.</p>
            <div class="fg-finance-included-heading"><h3>Included With WooCommerce</h3><span class="fg-finance-included-label">No Mapping Needed</span></div>
            <p class="fg-finance-included-fields">Order totals, tax breakdown, payment methods, fees charged to customers, discounts, shipping, and recorded refunds.</p>
            <p class="description">Reports require financial data access in <a href="<?php echo esc_url(admin_url('options-general.php?page=fames-mcp-gateway&tab=access')); ?>">Access Controls</a> and your account's WooCommerce permissions.</p>
        </section>
        <?php
        $permission = self::permission();
        if (is_wp_error($permission)) { echo '<section class="fg-card"><h2>Finance Setup Unavailable</h2><p>' . esc_html($permission->get_error_message()) . '</p></section>'; return; }
        if (is_array($error)) {
            $help = !empty($error['saved']) ? ' The submitted mapping was saved. Reload Finance Setup before making another change.' : ' Your submitted fields are preserved below.';
            if (($error['code'] ?? '') === 'fg_settings_stale') { $help .= ' Copy your changes, reload Finance Setup and apply them to the latest mapping.'; }
            echo '<div class="notice notice-error" role="alert"><p>' . esc_html($error['message'] . $help) . '</p></div>';
        }
        if (isset($_GET['finance_saved']) && is_string($_GET['finance_saved'])) {
            $messages = ['changed' => 'Finance mapping saved. Unfinished change approvals were invalidated.', 'unchanged' => 'No changes to save. Existing approvals were preserved.', 'removed' => 'Finance mapping removed.'];
            if (isset($messages[$_GET['finance_saved']])) { echo '<div class="notice notice-success" role="status"><p>' . esc_html($messages[$_GET['finance_saved']]) . '</p></div>'; }
        }
        ?>
        <section class="fg-card">
            <h2>Payment Processing Fees &amp; Affiliate Commissions</h2>
            <p>Processing fees are merchant costs. Fees charged to customers are recorded on the order and included automatically. A stored gateway net amount is not profit or a verified bank payout.</p>
            <p>Choose a gateway to configure its exact order metadata keys, then test a known order before saving. No provider defaults are assumed. Affiliate systems using their own tables need a site adapter.</p>
            <?php if (empty($settings['sensitive'])) : ?><p class="fg-finance-notice">Order tests are unavailable until you enable financial data access in <a href="<?php echo esc_url(admin_url('options-general.php?page=fames-mcp-gateway&tab=access')); ?>">Access Controls</a>. You can still configure mappings.</p><?php endif; ?>
            <div class="fg-finance-gateways">
                <?php $gateways = self::gateways($saved); foreach ($gateways as $gateway => $row) :
                    $configured = array_key_exists($gateway, $saved);
                    $map = $configured && is_array($saved[$gateway]) ? $saved[$gateway] : [];
                    $needs_attention = $configured && (is_wp_error(self::validate_mapping((string) $gateway, $map)) || !$row['registered']);
                    $is_error = is_array($error) && ($error['gateway'] ?? '') === (string) $gateway && (($error['intent'] ?? '') !== 'create' || !$configured);
                    ?>
                    <details class="fg-finance-gateway" <?php echo $is_error ? 'open' : ''; ?>>
                        <summary><span><strong><?php echo esc_html($row['name']); ?></strong><small><?php echo esc_html($gateway); ?> · <?php echo $row['registered'] ? ($row['enabled'] ? 'Enabled in WooCommerce' : 'Disabled in WooCommerce') : 'Gateway Not Currently Registered'; ?></small></span><span class="fg-finance-status"><?php echo $needs_attention ? 'Needs Attention' : ($configured ? 'Configured' : 'Not Configured'); ?></span><span class="fg-finance-action"><?php echo $configured ? 'Edit' : 'Configure'; ?></span></summary>
                        <?php self::mapping_form((string) $gateway, $is_error ? $error['input'] : $map, $configured, false, $is_error ? $error['version'] : $version, !empty($settings['sensitive'])); ?>
                        <?php if ($configured) : ?>
                            <form class="fg-finance-remove" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="fg_finance_remove"><input type="hidden" name="gateway" value="<?php echo esc_attr($gateway); ?>"><input type="hidden" name="finance_version" value="<?php echo esc_attr($version); ?>"><?php wp_nonce_field('fg_finance_remove'); ?>
                                <p>Remove only this optional mapping. Previously recorded activity remains unchanged.</p><button class="button" type="submit">Remove Mapping</button>
                            </form>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
            <?php $new_error = is_array($error) && ($error['intent'] ?? '') === 'create' && (!isset($gateways[$error['gateway']]) || array_key_exists($error['gateway'], $saved)); ?>
            <details class="fg-finance-custom" <?php echo $new_error ? 'open' : ''; ?>>
                <summary>Add a Mapping for Another Gateway ID</summary>
                <p>Use an exact payment method ID from a historical order when its gateway is no longer installed. Existing saved mappings remain listed above.</p>
                <?php self::mapping_form($new_error ? $error['gateway'] : '', $new_error ? $error['input'] : [], false, true, $new_error ? $error['version'] : $version, !empty($settings['sensitive'])); ?>
            </details>
        </section>
        <?php
    }

    private static function mapping_form(string $gateway, array $map, bool $configured, bool $custom, string $version, bool $can_test): void {
        $uid = 'fg-fin-' . substr(hash('sha256', ($custom ? 'custom-' : 'registered-') . $gateway), 0, 12);
        ?>
        <form class="fg-finance-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="fg_finance_save"><input type="hidden" name="intent" value="<?php echo $configured ? 'edit' : 'create'; ?>"><input type="hidden" name="finance_version" value="<?php echo esc_attr($version); ?>"><?php wp_nonce_field('fg_finance_save'); ?>
            <?php if ($custom) : ?><label class="fg-finance-field" for="<?php echo esc_attr($uid); ?>-gateway">Gateway ID<input id="<?php echo esc_attr($uid); ?>-gateway" type="text" name="gateway" maxlength="100" pattern="[A-Za-z0-9_-]+" required value="<?php echo esc_attr($gateway); ?>"></label><?php else : ?><input type="hidden" name="gateway" value="<?php echo esc_attr($gateway); ?>"><?php endif; ?>
            <details class="fg-finance-advanced" open>
                <summary>Advanced Mapping — Exact Metadata Keys</summary>
                <p>Enter metadata key names from your payment plugin's documentation or a known order. Do not enter amounts, API keys or credentials.</p>
                <div class="fg-finance-groups">
                    <fieldset><legend>Payment Processing Fees</legend>
                        <?php self::key_field($uid, $map, 'fee_key', 'Processing Fee Key', 'The scalar order metadata key containing the merchant processing fee.'); self::units_field($uid, $map, 'fee_divisor', 'Processing Fee Units'); ?>
                        <?php self::key_field($uid, $map, 'net_key', 'Gateway Net Amount Key', 'Optional stored net amount; this is not calculated profit or a verified payout.'); self::units_field($uid, $map, 'net_divisor', 'Gateway Net Amount Units'); ?>
                        <?php self::key_field($uid, $map, 'currency_key', 'Settlement Currency Key', 'Optional three-letter currency metadata, such as MYR. Blank assumes the order currency for fee and net amounts.'); ?>
                    </fieldset>
                    <fieldset><legend>Affiliate Details</legend>
                        <?php self::key_field($uid, $map, 'affiliate_id_key', 'Affiliate ID Key', 'Optional scalar order metadata identifying the referring affiliate.'); self::key_field($uid, $map, 'commission_key', 'Commission Amount Key', 'Optional commission recorded on the order. A site affiliate adapter takes precedence over these affiliate fields.'); self::units_field($uid, $map, 'commission_divisor', 'Commission Units'); self::key_field($uid, $map, 'commission_currency_key', 'Commission Currency Key', 'Optional three-letter commission currency. Blank assumes the order currency unless a site adapter supplies its own value.'); ?>
                    </fieldset>
                </div>
            </details>
            <section class="fg-finance-test" aria-labelledby="<?php echo esc_attr($uid); ?>-test-title">
                <h3 id="<?php echo esc_attr($uid); ?>-test-title">Test With an Order</h3>
                <p>Enter a known WooCommerce order's numeric ID. Only the requested finance fields are shown. This tests unsaved fields and does not save a mapping.</p>
                <label for="<?php echo esc_attr($uid); ?>-order">Order ID</label>
                <div class="fg-finance-test-actions"><input id="<?php echo esc_attr($uid); ?>-order" name="order_id" type="text" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="18" autocomplete="off"><button class="button fg-finance-preview" type="button" <?php disabled(!$can_test); ?>>Test With This Order</button></div>
                <noscript><p>Enable JavaScript to test an unsaved mapping. Saving does not require JavaScript.</p></noscript>
                <div class="fg-finance-results" role="status" aria-live="polite" aria-atomic="true"></div>
            </section>
            <p class="fg-finance-save"><button type="submit" class="button button-primary">Save Mapping</button><span>Saving a changed mapping invalidates unfinished change approvals.</span></p>
        </form>
        <?php
    }

    private static function key_field(string $uid, array $map, string $key, string $label, string $help): void {
        $id = $uid . '-' . $key;
        ?><label class="fg-finance-field" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?><input id="<?php echo esc_attr($id); ?>" type="text" name="mapping[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(is_scalar($map[$key] ?? null) ? (string) $map[$key] : ''); ?>" maxlength="150" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr($id); ?>-help"></label><p class="description" id="<?php echo esc_attr($id); ?>-help"><?php echo esc_html($help); ?></p><?php
    }

    private static function units_field(string $uid, array $map, string $key, string $label): void {
        $id = $uid . '-' . $key;
        ?><label class="fg-finance-field" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?><select id="<?php echo esc_attr($id); ?>" name="mapping[<?php echo esc_attr($key); ?>]">
            <?php foreach ([1 => 'Major Units: 12.50 → 12.50', 100 => 'Hundredths: 1250 → 12.50', 1000 => 'Thousandths: 12500 → 12.50'] as $divisor => $text) : ?><option value="<?php echo (int) $divisor; ?>" <?php selected((string) ($map[$key] ?? 1), (string) $divisor); ?>><?php echo esc_html($text); ?></option><?php endforeach; ?>
        </select></label><?php
    }
}
