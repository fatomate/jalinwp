<?php
// Run inside a disposable WordPress test site. No production content or external requests.
defined('ABSPATH') || exit;
foreach (['core', 'design-blocks'] as $part) {
    if (!class_exists($part === 'core' ? 'FG_Core' : 'FG_Design_Blocks')) { require WP_PLUGIN_DIR . '/jalin-mcp-gateway/includes/class-' . $part . '.php'; }
}
wp_set_current_user(1);
$media_id = wp_insert_attachment(['post_title' => 'Design Adapter Fixture', 'post_status' => 'inherit', 'post_mime_type' => 'image/png'], false);
update_post_meta($media_id, '_wp_attached_file', '2026/09/design-adapter-fixture.png');
$media_url = wp_get_attachment_image_url($media_id, 'full');
if (!is_string($media_url)) { throw new RuntimeException('Fixture media URL unavailable'); }
$node = static fn(string $name, array $attributes = [], array $children = []): array => ['name' => $name, 'attributes' => $attributes, 'innerBlocks' => $children];
$p = $node('core/paragraph', ['content' => 'Plain copy & punctuation "quoted" and café.']);
$style = ['color' => ['text' => '#102030', 'background' => '#eeeeee'], 'spacing' => ['margin' => ['top' => '10px', 'bottom' => '5px'], 'padding' => ['top' => '20px', 'right' => '2rem', 'bottom' => '20px', 'left' => '2rem']], 'typography' => ['fontSize' => '22px', 'fontWeight' => '700', 'lineHeight' => '1.5', 'letterSpacing' => '1px']];
$fixtures = [
    'heading' => [$node('core/heading', ['content' => 'Hero & benefits', 'level' => 1, 'textAlign' => 'center', 'align' => 'wide', 'anchor' => 'heading', 'style' => $style])],
    'paragraph' => [$node('core/paragraph', ['content' => 'Body "copy", café & detail.', 'align' => 'right', 'style' => $style])],
    'literal-entities' => [$node('core/paragraph', ['content' => 'Literal &amp; and &lt; are plain text.']), $node('core/image', ['id' => $media_id, 'alt' => 'Literal & character', 'caption' => 'Literal &amp;']), $node('core/buttons', [], [$node('core/button', ['text' => 'Literal &amp;', 'url' => 'https://example.com/?literal=%26amp%3B'])])],
    'group' => [$node('core/group', ['tagName' => 'section', 'align' => 'full', 'anchor' => 'hero', 'layout' => ['type' => 'constrained', 'contentSize' => '1000px'], 'style' => $style], [$p])],
    'stack' => [$node('core/group', ['layout' => ['type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => 'center'], 'style' => ['spacing' => ['blockGap' => '1rem']]], [$p])],
    'row' => [$node('core/group', ['layout' => ['type' => 'flex', 'orientation' => 'horizontal', 'justifyContent' => 'space-between', 'flexWrap' => 'wrap']], [$p, $p])],
    'columns' => [$node('core/columns', ['verticalAlignment' => 'center', 'isStackedOnMobile' => false, 'style' => $style], [$node('core/column', ['width' => '40%', 'verticalAlignment' => 'bottom', 'style' => ['spacing' => ['padding' => ['top' => '20px']], 'typography' => ['fontSize' => '22px']]], [$p]), $node('core/column', ['width' => '60%'], [$p])])],
    'image' => [$node('core/image', ['id' => $media_id, 'alt' => 'A & B', 'caption' => 'Caption & more', 'sizeSlug' => 'full', 'align' => 'center', 'anchor' => 'photo', 'style' => ['spacing' => ['margin' => ['top' => '20px', 'bottom' => '10px']]]])],
    'cover' => [$node('core/cover', ['id' => $media_id, 'alt' => 'Cover & more', 'dimRatio' => 55, 'customOverlayColor' => '#102030', 'minHeight' => 400, 'minHeightUnit' => 'px', 'contentPosition' => 'bottom right', 'align' => 'full', 'anchor' => 'cover', 'style' => ['color' => ['text' => '#ffffff'], 'spacing' => ['padding' => ['top' => '40px', 'bottom' => '40px']]]], [$p])],
    'cover-solid' => [$node('core/cover', ['dimRatio' => 0, 'customOverlayColor' => '#ffffff', 'minHeight' => 70, 'minHeightUnit' => 'vh', 'contentPosition' => 'top left', 'isDark' => false], [$p])],
    'buttons' => [$node('core/buttons', ['layout' => ['type' => 'flex', 'justifyContent' => 'center'], 'style' => ['color' => ['background' => '#ffffff'], 'typography' => ['fontSize' => '20px']]], [$node('core/button', ['text' => 'Buy & save', 'url' => 'https://example.com/?x=1&y=2', 'linkTarget' => '_blank', 'width' => 100, 'textAlign' => 'center', 'anchor' => 'buy', 'style' => ['color' => $style['color'], 'spacing' => ['padding' => $style['spacing']['padding']], 'typography' => $style['typography']]])])],
    'list' => [$node('core/list', ['ordered' => true, 'start' => 3, 'reversed' => true, 'anchor' => 'list', 'style' => $style], [$node('core/list-item', ['content' => 'One & more', 'style' => $style], [$node('core/list', [], [$node('core/list-item', ['content' => 'Nested'])])])])],
    'separator' => [$node('core/separator', ['align' => 'wide', 'anchor' => 'divider', 'style' => ['color' => ['background' => '#123456'], 'spacing' => ['margin' => ['top' => '20px', 'bottom' => '20px']]]])],
    'spacer' => [$node('core/spacer', ['height' => '30px', 'anchor' => 'gap', 'style' => ['spacing' => ['margin' => ['top' => '20px', 'bottom' => '20px']]]])],
    'defaults' => [$node('core/heading', ['content' => 'Heading', 'level' => 2]), $p, $node('core/image', ['id' => $media_id]), $node('core/cover', ['dimRatio' => 100, 'isDark' => true, 'alt' => ''], [$p]), $node('core/separator'), $node('core/spacer', ['height' => '100px'])],
    'landing-page' => [
        $node('core/cover', ['id' => $media_id, 'alt' => 'Training workspace', 'dimRatio' => 80, 'customOverlayColor' => '#102030', 'minHeight' => 500, 'align' => 'full'], [
            $node('core/heading', ['content' => 'Grow With Better Conversations', 'level' => 1, 'textAlign' => 'center', 'style' => ['color' => ['text' => '#ffffff'], 'typography' => ['fontSize' => '48px']]]),
            $node('core/paragraph', ['content' => 'Build an approach your team can use every day.', 'align' => 'center', 'style' => ['color' => ['text' => '#ffffff']]]),
            $node('core/buttons', ['layout' => ['type' => 'flex', 'justifyContent' => 'center']], [$node('core/button', ['text' => 'Explore the Class', 'url' => '#class-details', 'style' => ['color' => ['background' => '#2860f5', 'text' => '#ffffff']]])]),
        ]),
        $node('core/group', ['tagName' => 'section', 'anchor' => 'class-details', 'layout' => ['type' => 'constrained', 'contentSize' => '1000px'], 'style' => ['spacing' => ['padding' => ['top' => '60px', 'bottom' => '60px']]]], [
            $node('core/heading', ['content' => 'Practical Skills for Your Team']),
            $node('core/columns', [], [$node('core/column', ['width' => '50%'], [$node('core/heading', ['content' => 'Reply With Confidence', 'level' => 3]), $p]), $node('core/column', ['width' => '50%'], [$node('core/heading', ['content' => 'Follow Up Consistently', 'level' => 3]), $p])]),
            $node('core/list', [], [$node('core/list-item', ['content' => 'Hands-on practice']), $node('core/list-item', ['content' => 'Reusable examples'])]),
            $node('core/separator'),
            $node('core/heading', ['content' => 'Get Started at RM97', 'textAlign' => 'center']),
            $node('core/buttons', ['layout' => ['type' => 'flex', 'justifyContent' => 'center']], [$node('core/button', ['text' => 'Ask About the Class', 'url' => 'mailto:training@example.com'])]),
        ]),
    ],
];
$at_limit = $p; for ($i = 0; $i < 7; $i++) { $at_limit = $node('core/group', [], [$at_limit]); } $fixtures['maximum-depth'] = [$at_limit];
$results = []; $export = [];
foreach ($fixtures as $name => $blocks) {
    $normalized = FG_Design_Blocks::normalize($blocks);
    if ($normalized !== FG_Design_Blocks::normalize($normalized)) { throw new RuntimeException('Normalization is not idempotent: ' . $name); }
    $markup = FG_Design_Blocks::serialize($normalized);
    if (!parse_blocks($markup)) { throw new RuntimeException('No PHP block structure: ' . $name); }
    $export[] = ['name' => $name, 'blocks' => $normalized, 'markup' => $markup];
    $results[] = ['name' => 'Normalize and serialize ' . $name, 'pass' => true];
}
$rejects = [
    'raw HTML content' => [$node('core/paragraph', ['content' => '<script>alert(1)</script>'])],
    'shortcode text' => [$node('core/paragraph', ['content' => '[dangerous-shortcode]'])],
    'unknown block' => [$node('vendor/unknown')],
    'unknown attribute' => [$node('core/paragraph', ['content' => 'Text', 'onClick' => 'alert(1)'])],
    'unknown style property' => [$node('core/paragraph', ['content' => 'Text', 'style' => ['position' => 'absolute']])],
    'unsafe CSS value' => [$node('core/paragraph', ['content' => 'Text', 'style' => ['color' => ['text' => 'url(javascript:alert(1))']]])],
    'CSS expression length' => [$node('core/spacer', ['height' => 'calc(100vh)'])],
    'missing attachment' => [$node('core/image', ['id' => 999999999])],
    'raw image URL' => [$node('core/image', ['id' => $media_id, 'url' => 'https://example.com/other.png'])],
    'HTML entity alt text' => [$node('core/image', ['id' => $media_id, 'alt' => 'Literal &amp;'])],
    'HTML entity URL' => [$node('core/buttons', [], [$node('core/button', ['text' => 'Link', 'url' => 'https://example.com/?literal=&amp;'])])],
    'orphan column' => [$node('core/column', [], [$p])],
    'wrong column child' => [$node('core/columns', [], [$p])],
    'unsafe link' => [$node('core/buttons', [], [$node('core/button', ['text' => 'Bad', 'url' => 'javascript:alert(1)'])])],
    'protocol-relative link' => [$node('core/buttons', [], [$node('core/button', ['text' => 'Bad', 'url' => '//example.com'])])],
    'duplicate anchor' => [$node('core/paragraph', ['content' => 'One', 'anchor' => 'same']), $node('core/paragraph', ['content' => 'Two', 'anchor' => 'same'])],
    'invalid layout mix' => [$node('core/group', ['layout' => ['type' => 'flex', 'contentSize' => '1000px']], [$p])],
    'string boolean' => [$node('core/list', ['ordered' => 'true'], [$node('core/list-item', ['content' => 'One'])])],
    'node overflow' => array_fill(0, 101, $p),
    'saved markup expansion' => array_fill(0, 3, $node('core/paragraph', ['content' => str_repeat('&', 5000)])),
];
$deep = $p; for ($i = 0; $i < 8; $i++) { $deep = $node('core/group', [], [$deep]); } $rejects['depth overflow'] = [$deep];
foreach ($rejects as $name => $blocks) {
    try { FG_Design_Blocks::serialize($blocks); throw new RuntimeException('Accepted ' . $name); }
    catch (FG_Failure $e) { if ($e->reason !== 'invalid_arguments' || !str_contains($e->getMessage(), 'blocks')) { throw $e; } }
    $results[] = ['name' => 'Reject ' . $name, 'pass' => true];
}
file_put_contents('/test-results/design-blocks-fixtures.json', wp_json_encode(['media' => [$media_id => $media_url], 'fixtures' => $export], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode(['passed' => count($results), 'total' => count($results), 'cases' => $results]);
