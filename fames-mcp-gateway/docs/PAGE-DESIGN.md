# Page Layouts and Gutenberg Design — 0.3.1

> Branding update: version 0.3.2 uses the JalinWP name and logo. Historical version numbers below describe the existing feature implementation. See [Branding and Upgrade Notes](BRANDING.md) and [Current Validation](VALIDATION.md).

These tools create editable WordPress pages and change a page's template without editing theme files. Choose **JalinWP Canvas** to omit the theme header, footer, sidebar and automatic page title. WordPress head/body/footer hooks and normal assets still run, so theme/global styles or other plugins can affect appearance. The canvas is page-scoped; it does not change every page or switch the active theme.

## Tools and Workflow

| Tool | Purpose |
| --- | --- |
| `wp_page_layouts_list` | Return Theme Default, JalinWP Canvas and templates available to the page. Use exact returned IDs. |
| `wp_page_design_get` | Read a page, current layout, bounded source/structure and `expected_state`. |
| `wp_page_design_validate` | Normalize blocks and produce saved markup/outline without writing a page. |
| `wp_page_design_create` | Create a page under the effective change mode; title and blocks required, draft by default. |
| `wp_page_design_update` | Update supplied fields only; requires page ID and a fresh `expected_state`. Omit blocks to preserve content exactly. |

An AI client first discovers layouts and block capabilities. For an existing page it reads the required source windows and obtains `expected_state`. It can call `wp_page_design_validate` to inspect compiled markup without saving.

| Effective Mode | Design Execution |
| --- | --- |
| Read Only | Inspection and validation are available; creation and updates are denied. |
| Reviewed Changes | The write call stores a proposal. Review Changes shows field differences and an isolated preview. Installed Gutenberg packages validate the markup before approval; the same connection then calls `gateway_apply_change`. |
| YOLO Mode | The write call validates, freezes and executes the design immediately without a dashboard visit or follow-up apply call. Strict PHP block adapters run; the installed editor's JavaScript validation gate is skipped. |

The server stores normalized arguments, compiled markup and digest, layout identity, current fields, policy revision and execution mode in the change record. Before execution it rechecks the page and template state, permissions and policy. A conflicting saved edit invalidates the request. Read the current page again before submitting another change.

YOLO requires explicit site enablement and fresh OAuth consent for each client. An existing reviewed grant continues to use the reviewed flow until reconnected with YOLO consent. This is separate from WordPress capabilities and data access. See [YOLO-MODE.md](YOLO-MODE.md).

The PHP adapters accept a bounded supported subset and the write verifies exact saved markup. This does not establish compatibility with every installed Gutenberg version, editor extension or theme. In YOLO, the native editor may flag a block issue when the saved page is opened later. Validation returns `validation_method`, `write_mode` and an `installed_editor_validation` statement describing which check applies. Calling the validation tool itself never saves or publishes; a YOLO write does save, with draft as the default unless another permitted status is supplied.

## Supported Blocks

Creation supports `core/group`, `core/columns`, `core/column`, `core/heading`, `core/paragraph`, `core/image`, `core/cover`, `core/buttons`, `core/button`, `core/list`, `core/list-item`, `core/separator` and `core/spacer`. Group layouts support constrained sections, rows and stacks. Allowed attributes include relevant alignment, spacing, hex colors and typography; query the gateway's block inventory for each adapter's exact schema.

Each node uses `name`, `attributes` and optional `innerBlocks`. Text fields are plain text, not arbitrary HTML, shortcodes or block comments. Images use existing, readable WordPress image attachment IDs. New image upload, arbitrary CSS/JavaScript/PHP, dynamic blocks, template parts, reusable blocks and third-party builder adapters are outside this release. Unknown block types are rejected when generating a design. A layout-only update preserves every byte of existing content, including unsupported blocks.

Limits: 100 blocks in total, eight nesting levels and 60,000 bytes for block input and compiled markup. Source reads use windows up to 20,000 characters; structure inspection is bounded and reports truncation. Image alt text and URL attributes must use literal characters rather than HTML entity spellings such as `&amp;`; use percent encoding where needed in URLs. The installed WordPress serializer cannot preserve those literal entity spellings through attribute save/reopen, so unsupported input receives an explicit error.

Example Create Arguments:

```json
{
  "title": "Campaign Landing Page",
  "status": "draft",
  "layout": "fames-mcp-canvas",
  "blocks": [
    {
      "name": "core/group",
      "attributes": {"tagName": "section", "align": "full", "layout": {"type": "constrained"}},
      "innerBlocks": [
        {"name": "core/heading", "attributes": {"level": 1, "content": "Build Something Useful", "textAlign": "center"}},
        {"name": "core/paragraph", "attributes": {"content": "A page you can keep editing in Gutenberg.", "align": "center"}},
        {"name": "core/buttons", "attributes": {"layout": {"type": "flex", "justifyContent": "center"}}, "innerBlocks": [
          {"name": "core/button", "attributes": {"text": "Get Started", "url": "/contact/"}}
        ]}
      ]
    }
  ]
}
```

## Templates and Recovery

Classic themes use the plugin's PHP canvas template. Block themes use a registered block template when supported; WordPress 6.6 uses the PHP fallback. WordPress introduced `register_block_template()` in 6.7. Existing theme or Site Editor overrides are reported and are not presented as a guaranteed blank canvas. See the [WordPress template API](https://developer.wordpress.org/reference/functions/register_block_template/).

Existing-page design updates require at least two retained revisions and transactional storage. Native storage requires InnoDB for both posts and postmeta. The write locks the page/meta, rechecks its state, records a paired content/layout revision, performs the native REST update, verifies exact saved content and template, then commits. The result includes `recovery_revision_id`; restore that revision using WordPress Revisions to recover content and layout together while the plugin is active. The plugin registers template metadata with the [revision meta key hook](https://developer.wordpress.org/reference/hooks/wp_post_revision_meta_keys/).

A failed save rolls back this page transaction and makes the request non-retriable. WordPress hooks can have effects outside the transaction, such as external requests or emails. Inspect the page before making another write. Repeating the original write-tool call creates another record and can duplicate changes; it is not an idempotent retry. These safeguards apply to the dedicated design tools; they do not introduce transaction recovery for orders or the legacy content tools.

In Reviewed Changes, the preview is sandboxed, contains no executable proposal scripts and uses restrictive content security policy. It is approximate: saved block layout attributes and the site's frontend style pipeline can produce differences. It does not replace opening the draft in WordPress. Installed-editor checks use WordPress's [block validation API](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-blocks/#validateblock).

## Where to Make Changes

- `includes/class-page-layouts.php`: layout discovery, identity, canvas registration and template selection.
- `templates/canvas.php`, `templates/canvas.html`, `assets/canvas.css`: canvas markup and page-scoped styling. Retain WordPress hooks.
- `includes/class-page-design.php`: tool schemas, page permissions, state checks, transactions and revision recovery.
- `includes/class-design-blocks.php`: explicit block/attribute adapters and canonical serialization. Add PHP fixtures and actual installed-editor round-trip assertions for each addition.
- `includes/class-design-review.php`, `assets/design-review.js`, `assets/design-validation.js`: preview and approval validation. Keep server-side approval requirements for reviewed records even if the UI changes; YOLO bypasses the review UI through its separate execution path.
- `includes/class-approvals.php`: frozen change context, reviewed/direct mode separation, fresh policy checks and one-time execution. Do not expose server context as caller-supplied tool arguments.

See `VALIDATION.md` for exercised versions and pending native database/browser checks.
