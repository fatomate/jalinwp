<?php
defined('ABSPATH') || exit;

/** Read-only proposal presentation and installed Gutenberg compatibility checks. */
final class FG_Design_Review {
    public static function boot(): void {
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook !== 'settings_page_jalin-mcp-gateway') { return; }
            wp_enqueue_script('jalin-mcp-design-validation', plugins_url('assets/design-validation.js', FG_FILE), ['wp-blocks', 'wp-block-library'], FG_VERSION, true);
            wp_enqueue_script('jalin-mcp-design-review', plugins_url('assets/design-review.js', FG_FILE), ['jalin-mcp-design-validation', 'wp-dom-ready'], FG_VERSION, true);
        });
    }
    public static function render(array $row): void {
        if (!FG_Approvals::is_design((string) ($row['tool'] ?? ''))) { return; }
        try {
            $context = FG_Approvals::context($row);
            if (!hash_equals((string) $row['digest'], hash('sha256', $row['arguments'] . '|' . $row['server_context']))) {
                throw new FG_Failure('invalid_change', 'Stored design integrity check failed. Submit a new proposal.');
            }
            $design = $context['design'] ?? [];
            if (!is_array($design)) { throw new FG_Failure('invalid_change', 'This design needs a new proposal.'); }
        } catch (FG_Failure $error) {
            echo '<p class="notice notice-error">' . esc_html($error->getMessage()) . '</p>';
            return;
        }
        $args = json_decode($row['arguments'], true);
        $source = $design['content'] ?? null;
        $requester = (string) ($context['request_context']['client_name'] ?? '');
        $needs_review = ($context['execution_mode'] ?? 'reviewed') !== 'yolo' && ($row['status'] ?? '') === 'pending' && (int) ($row['expires'] ?? 0) > time();
        echo '<div class="fg-design-review"' . ($needs_review ? ' data-fg-design-review data-content-digest="' . esc_attr((string) ($design['content_sha256'] ?? '')) . '"' : '') . '>';
        echo '<h3>' . ($needs_review ? 'Page Design Review' : 'Page Design Record') . '</h3>';
        if ($requester !== '') { echo '<p><strong>Requested Via:</strong> ' . esc_html($requester) . '</p>'; }
        echo '<p><strong>Layout:</strong> ' . esc_html((string) ($args['layout'] ?? $design['layout'] ?? 'Unchanged')) . '</p>';
        if (isset($args['status'])) { echo '<p><strong>Page Status:</strong> ' . esc_html(ucfirst($args['status'])) . '</p>'; }
        if (isset($args['title'])) { echo '<p><strong>Page Title:</strong> ' . esc_html($args['title']) . '</p>'; }
        if (is_array($design['before_fields'] ?? null) && array_intersect_key($design['before_fields'], $args)) {
            echo '<div class="fg-table-wrap"><table class="widefat striped"><thead><tr><th>Field</th><th>Value Before Request</th><th>Requested Value</th></tr></thead><tbody>';
            foreach ($design['before_fields'] as $field => $before) {
                if ($field === 'id' || !array_key_exists($field, $args)) { continue; }
                echo '<tr><th>' . esc_html(ucwords(str_replace('_', ' ', $field))) . '</th><td>' . esc_html((string) $before) . '</td><td>' . esc_html((string) $args[$field]) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if (!is_string($source)) {
            echo '<p>This request contains only page fields or layout. It does not replace page content.</p></div>';
            return;
        }
        echo '<textarea data-fg-design-source hidden>' . esc_textarea($source) . '</textarea>';
        if ($needs_review) { echo '<p data-fg-design-status role="status" aria-live="polite">Waiting for Gutenberg Validation…</p>'; }
        elseif (($context['execution_mode'] ?? '') === 'yolo') { echo '<p>YOLO Mode used server validation without dashboard approval or the installed-editor validation step.</p>'; }
        if (is_array($design['outline'] ?? null)) {
            echo '<details><summary>Block Outline</summary>';
            $count = 0;
            self::outline($design['outline'], $count);
            echo '</details>';
        }
        echo '<details open><summary>Design Preview</summary>';
        echo '<p class="description">This isolated preview shows the blocks stored with this request. Check the request status above for its outcome and open the page to see its current content. Theme styles may affect the final appearance.</p>';
        $preview = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src https: http: data:; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">'
            . '<style>body{margin:0;padding:24px;font:16px/1.6 system-ui,sans-serif;color:#172033}img{max-width:100%;height:auto}.wp-block-columns{display:flex;gap:24px}.wp-block-column{flex:1;min-width:0}.wp-block-buttons,.is-layout-flex{display:flex;gap:12px;flex-wrap:wrap}.is-vertical{flex-direction:column}.wp-block-button__link{display:inline-block;padding:10px 20px;background:#2458d8;color:white;border-radius:6px;text-decoration:none}.wp-block-cover{position:relative;min-height:250px;padding:24px;display:flex;align-items:center;justify-content:center;background:#e9eef7}.wp-block-cover__image-background{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.wp-block-cover__background{position:absolute;inset:0;background:inherit}.wp-block-cover__inner-container{position:relative}.has-text-align-center{text-align:center}.has-text-align-right{text-align:right}@media(max-width:600px){.wp-block-columns{display:block}.wp-block-column{margin-bottom:20px}}</style></head><body><main>'
            . $source . '</main></body></html>';
        echo '<iframe title="Proposed Page Design" sandbox="" referrerpolicy="no-referrer" style="width:100%;height:420px;border:1px solid #dce2ea;border-radius:6px;background:white" srcdoc="' . esc_attr($preview) . '"></iframe></details>';
        echo '</div>';
    }
    private static function outline(array $nodes, int &$count, int $depth = 1): void {
        if (!$nodes || $depth > 8 || $count >= 100) { return; }
        echo '<ol>';
        foreach ($nodes as $node) {
            if (!is_array($node) || $count >= 100) { break; }
            $count++;
            $name = (string) ($node['name'] ?? 'Unknown Block');
            echo '<li>' . esc_html(ucwords(str_replace(['core/', '-'], ['', ' '], $name)));
            if (is_array($node['innerBlocks'] ?? null)) { self::outline($node['innerBlocks'], $count, $depth + 1); }
            echo '</li>';
        }
        echo '</ol>';
    }
}
