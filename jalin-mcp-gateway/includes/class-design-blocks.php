<?php
/** Bounded core-block adapters. Saved markup is checked against the site's editor before approval. */
defined('ABSPATH') || exit;

final class FG_Design_Blocks {
    public const MAX_NODES = 100;
    public const MAX_DEPTH = 8;
    public const MAX_BYTES = 60000;

    /** Attribute contracts for creation; these are deliberately smaller than Gutenberg's full schemas. */
    public static function supported(): array {
        $text = ['type' => 'string', 'maxLength' => 5000, 'description' => 'Plain text only. HTML, shortcodes and block comments are not supported.'];
        $enum = static fn(array $values): array => ['type' => 'string', 'enum' => $values];
        $wide = $enum(['wide', 'full']);
        $align = $enum(['left', 'center', 'right']);
        $length = ['type' => 'string', 'pattern' => '^(?:0|[0-9]+(?:\\.[0-9]+)?(?:px|rem|em|vh|vw|%))$', 'maxLength' => 16];
        $integer = static fn(int $min, int $max): array => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max];
        $color = ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'];
        $bool = ['type' => 'boolean'];
        $layout = self::object(['type' => $enum(['default', 'constrained', 'flex']), 'orientation' => $enum(['horizontal', 'vertical']), 'justifyContent' => $enum(['left', 'center', 'right', 'space-between']), 'flexWrap' => $enum(['wrap', 'nowrap']), 'contentSize' => $length, 'wideSize' => $length], ['type']);
        $map = [
            'core/group' => ['tagName' => $enum(['div', 'section']), 'align' => $wide, 'layout' => $layout],
            'core/columns' => ['align' => $wide, 'verticalAlignment' => $enum(['top', 'center', 'bottom']), 'isStackedOnMobile' => $bool],
            'core/column' => ['width' => ['type' => 'string', 'pattern' => '^(?:[1-9][0-9]?(?:\\.[0-9]{1,2})?|100)%$'], 'verticalAlignment' => $enum(['top', 'center', 'bottom'])],
            'core/heading' => ['content' => $text, 'level' => $integer(1, 6), 'textAlign' => $align, 'align' => $wide],
            'core/paragraph' => ['content' => $text, 'align' => $align],
            'core/image' => ['id' => $integer(1, PHP_INT_MAX), 'alt' => $text, 'caption' => $text, 'sizeSlug' => $enum(['full']), 'align' => $enum(['left', 'center', 'right', 'wide', 'full'])],
            'core/cover' => ['id' => $integer(1, PHP_INT_MAX), 'alt' => $text, 'dimRatio' => $integer(0, 100), 'customOverlayColor' => $color, 'minHeight' => $integer(1, 2000), 'minHeightUnit' => $enum(['px', 'vh']), 'contentPosition' => $enum(['top left', 'top center', 'top right', 'center left', 'center center', 'center right', 'bottom left', 'bottom center', 'bottom right']), 'isDark' => $bool, 'align' => $wide],
            'core/buttons' => ['align' => $wide, 'layout' => $layout],
            'core/button' => ['text' => $text, 'url' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'An HTTP(S), mailto, tel, site-relative, or anchor link.'], 'linkTarget' => $enum(['_self', '_blank']), 'width' => ['type' => 'integer', 'enum' => [25, 50, 75, 100]], 'textAlign' => $align],
            'core/list' => ['ordered' => $bool, 'start' => $integer(1, 9999), 'reversed' => $bool],
            'core/list-item' => ['content' => $text],
            'core/separator' => ['align' => $enum(['center', 'wide', 'full'])],
            'core/spacer' => ['height' => $length],
        ];
        $required = ['core/heading' => ['content'], 'core/paragraph' => ['content'], 'core/image' => ['id'], 'core/button' => ['text', 'url'], 'core/list-item' => ['content']];
        foreach ($map as $name => $properties) {
            $properties['anchor'] = ['type' => 'string', 'pattern' => '^[A-Za-z][A-Za-z0-9_-]{0,79}$'];
            $properties['style'] = self::style_schema($name, $length, $color);
            $map[$name] = self::object($properties, $required[$name] ?? []);
        }
        return $map;
    }

    private static function object(array $properties, array $required = []): array {
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false, 'required' => $required];
    }
    private static function style_schema(string $name, array $length, array $color): array {
        $sides = static fn(array $keys): array => self::object(array_fill_keys($keys, $length));
        $all = ['top', 'right', 'bottom', 'left'];
        $spacing = [];
        if ($name !== 'core/column' && $name !== 'core/button') {
            $spacing['margin'] = $sides(in_array($name, ['core/group', 'core/columns', 'core/buttons', 'core/cover', 'core/separator', 'core/spacer'], true) ? ['top', 'bottom'] : $all);
        }
        if (!in_array($name, ['core/image', 'core/separator', 'core/spacer'], true)) { $spacing['padding'] = $sides($all); }
        if (in_array($name, ['core/group', 'core/columns', 'core/column', 'core/buttons', 'core/cover'], true)) { $spacing['blockGap'] = $length; }
        $properties = [];
        if (!in_array($name, ['core/image', 'core/spacer'], true)) {
            $colors = $name === 'core/separator' || $name === 'core/buttons' ? ['background' => $color] : ($name === 'core/cover' ? ['text' => $color] : ['text' => $color, 'background' => $color]);
            $properties['color'] = self::object($colors);
        }
        $properties['spacing'] = self::object($spacing);
        if (!in_array($name, ['core/image', 'core/separator', 'core/spacer'], true)) {
            $properties['typography'] = self::object(['fontSize' => $length, 'fontWeight' => ['type' => 'string', 'enum' => ['100', '200', '300', '400', '500', '600', '700', '800', '900']], 'letterSpacing' => $length, 'lineHeight' => ['type' => 'string', 'pattern' => '^(?:0\\.[89]|[12](?:\\.[0-9]{1,2})?|3(?:\\.0{1,2})?)$']]);
        }
        return self::object($properties);
    }

    public static function normalize(array $blocks): array {
        if (!array_is_list($blocks) || !$blocks) { self::fail('blocks', 'Provide a non-empty list of blocks.'); }
        $encoded = wp_json_encode($blocks);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES) { self::fail('blocks', 'The block input must be at most 60,000 bytes.'); }
        $count = 0; $anchors = []; $schemas = self::supported();
        return self::nodes($blocks, '', 1, 'blocks', $count, $anchors, $schemas);
    }
    private static function nodes(array $blocks, string $parent, int $depth, string $path, int &$count, array &$anchors, array $schemas): array {
        if ($depth > self::MAX_DEPTH) { self::fail($path, 'A design may be nested at most eight blocks deep.'); }
        $out = [];
        foreach ($blocks as $index => $block) {
            $at = $path . '[' . $index . ']';
            if (++$count > self::MAX_NODES) { self::fail($at, 'A design may contain at most 100 blocks.'); }
            if (!is_array($block) || array_is_list($block) || array_diff(array_keys($block), ['name', 'attributes', 'innerBlocks'])) { self::fail($at, 'Use only name, attributes and innerBlocks.'); }
            $name = $block['name'] ?? null;
            if (!is_string($name) || !isset($schemas[$name])) { self::fail($at . '.name', 'This block has no supported creation adapter.'); }
            $restricted = ['core/column' => 'core/columns', 'core/button' => 'core/buttons', 'core/list-item' => 'core/list'];
            if (isset($restricted[$name]) && $parent !== $restricted[$name]) { self::fail($at, $name . ' must be inside ' . $restricted[$name] . '.'); }
            $attributes = $block['attributes'] ?? [];
            if (!is_array($attributes) || ($attributes && array_is_list($attributes))) { self::fail($at . '.attributes', 'Attributes must be an object.'); }
            $a = self::attributes($attributes, $schemas[$name], $at . '.attributes');
            if (isset($a['anchor'])) {
                if (isset($anchors[$a['anchor']])) { self::fail($at . '.attributes.anchor', 'Anchors must be unique within the design.'); }
                $anchors[$a['anchor']] = true;
            }
            if (isset($a['layout'])) {
                $type = $a['layout']['type'];
                if ($name === 'core/buttons' && $type !== 'flex') { self::fail($at . '.attributes.layout', 'Buttons use a flex layout.'); }
                $allowed = $type === 'flex' ? ['type', 'orientation', 'justifyContent', 'flexWrap'] : ($type === 'constrained' ? ['type', 'contentSize', 'wideSize'] : ['type']);
                if (array_diff(array_keys($a['layout']), $allowed)) { self::fail($at . '.attributes.layout', 'These layout options do not belong to the selected layout type.'); }
            }
            if ($name === 'core/list' && empty($a['ordered']) && (isset($a['start']) || !empty($a['reversed']))) { self::fail($at . '.attributes', 'Start and reversed apply only to an ordered list.'); }
            if (isset($a['minHeightUnit']) && !isset($a['minHeight'])) { self::fail($at . '.attributes.minHeightUnit', 'Specify minHeight with its unit.'); }
            if (($a['minHeightUnit'] ?? 'px') === 'vh' && ($a['minHeight'] ?? 0) > 100) { self::fail($at . '.attributes.minHeight', 'Viewport heights must not exceed 100vh.'); }
            if (in_array($name, ['core/image', 'core/cover'], true) && isset($a['id'])) { self::media_url($a['id'], $at . '.attributes.id'); }
            if ($name === 'core/button') { self::safe_url($a['url'], $at . '.attributes.url'); }
            $children = $block['innerBlocks'] ?? [];
            if (!is_array($children) || !array_is_list($children)) { self::fail($at . '.innerBlocks', 'Child blocks must be a list.'); }
            $containers = ['core/group', 'core/columns', 'core/column', 'core/cover', 'core/buttons', 'core/list', 'core/list-item'];
            if ($children && !in_array($name, $containers, true)) { self::fail($at . '.innerBlocks', 'This block cannot contain child blocks.'); }
            $only = ['core/columns' => 'core/column', 'core/buttons' => 'core/button', 'core/list' => 'core/list-item', 'core/list-item' => 'core/list'];
            if (isset($only[$name])) {
                foreach ($children as $child) { if (!is_array($child) || ($child['name'] ?? '') !== $only[$name]) { self::fail($at . '.innerBlocks', 'This block may contain only ' . $only[$name] . '.'); } }
                if ($name !== 'core/list-item' && !$children) { self::fail($at . '.innerBlocks', 'At least one child block is required.'); }
            }
            if (in_array($name, ['core/columns', 'core/buttons'], true) && count($children) > 6) { self::fail($at . '.innerBlocks', 'Use at most six columns or buttons in one container.'); }
            if ($name === 'core/list-item' && count($children) > 1) { self::fail($at . '.innerBlocks', 'Use at most one nested list per item.'); }
            $out[] = ['name' => $name, 'attributes' => $a, 'innerBlocks' => $children ? self::nodes($children, $name, $depth + 1, $at . '.innerBlocks', $count, $anchors, $schemas) : []];
        }
        return $out;
    }
    private static function attributes(array $input, array $schema, string $path): array {
        if (array_diff(array_keys($input), array_keys($schema['properties']))) { self::fail($path, 'Unsupported attribute: ' . implode(', ', array_diff(array_keys($input), array_keys($schema['properties']))) . '.'); }
        foreach ($schema['required'] as $key) { if (!array_key_exists($key, $input)) { self::fail($path . '.' . $key, 'This attribute is required.'); } }
        $out = [];
        foreach ($schema['properties'] as $key => $rule) {
            if (!array_key_exists($key, $input)) { continue; }
            $value = $input[$key]; $at = $path . '.' . $key; $type = $rule['type'];
            if ($type === 'object') {
                if (!is_array($value) || ($value && array_is_list($value))) { self::fail($at, 'Provide an object.'); }
                $value = self::attributes($value, $rule, $at);
                if ($value !== []) { $out[$key] = $value; }
                continue;
            }
            if (($type === 'string' && !is_string($value)) || ($type === 'integer' && !is_int($value)) || ($type === 'boolean' && !is_bool($value))) { self::fail($at, 'Expected ' . $type . '.'); }
            if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) { self::fail($at, 'Use one of the documented values.'); }
            if ($type === 'string') {
                if (!preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || strlen($value) > ($rule['maxLength'] ?? 5000)) { self::fail($at, 'The string is too long or contains invalid characters.'); }
                if (isset($rule['pattern']) && !preg_match('~' . $rule['pattern'] . '~D', $value)) { self::fail($at, 'Use the documented format.'); }
                if (in_array($key, ['content', 'text', 'caption', 'alt'], true) && (str_contains($value, '<') || str_contains($value, '>') || preg_match('/\[[^\]\r\n]+\]/', $value))) { self::fail($at, 'Use plain text without HTML, shortcodes or block comments.'); }
                if ($key === 'alt' && self::contains_entity($value)) { self::fail($at, 'Use literal characters, such as &, instead of HTML character references in image alt text.'); }
                if (isset($rule['pattern']) && str_starts_with($rule['pattern'], '^#')) { $value = strtolower($value); }
                if (isset($rule['pattern']) && str_contains($rule['pattern'], '(?:px|rem|em|vh|vw|%)')) { self::length($value, $at); }
            }
            if ($type === 'integer' && (($value < ($rule['minimum'] ?? PHP_INT_MIN)) || ($value > ($rule['maximum'] ?? PHP_INT_MAX)))) { self::fail($at, 'The number is outside the documented range.'); }
            $out[$key] = $value;
        }
        return $out;
    }
    private static function length(string $value, string $path): void {
        if ($value === '0') { return; }
        preg_match('/^([0-9]+(?:\.[0-9]+)?)(px|rem|em|vh|vw|%)$/D', $value, $matches);
        $number = (float) ($matches[1] ?? -1); $unit = $matches[2] ?? '';
        $limit = $unit === 'px' ? 2000 : (in_array($unit, ['rem', 'em'], true) ? 100 : 100);
        if (str_contains($path, 'fontSize')) { $limit = $unit === 'px' ? 200 : (in_array($unit, ['rem', 'em'], true) ? 15 : 20); }
        if (str_contains($path, 'letterSpacing')) { $limit = $unit === 'px' ? 20 : 2; }
        if ($number < 0 || $number > $limit) { self::fail($path, 'Use a bounded, non-negative CSS length.'); }
    }
    private static function safe_url(string $url, string $path): void {
        if ($url === '' || preg_match('/[\s\\\\<>"\x00-\x1F\x7F]/', $url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) { self::fail($path, 'Use a safe link without control characters.'); }
        if (self::contains_entity($url)) { self::fail($path, 'Use a literal URL or percent-encoded URL values instead of HTML character references.'); }
        if (str_starts_with($url, '#')) {
            if (!preg_match('/^#[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $url)) { self::fail($path, 'Use a valid anchor link.'); }
            return;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) { return; }
        $parts = wp_parse_url($url); $scheme = strtolower($parts['scheme'] ?? '');
        if (!$parts || !in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) || isset($parts['user']) || isset($parts['pass']) || (in_array($scheme, ['http', 'https'], true) && empty($parts['host'])) || empty($parts['host']) && empty($parts['path'])) { self::fail($path, 'Use an HTTP(S), email, telephone, site-relative or anchor link.'); }
    }
    private static function media_url(int $id, string $path): string {
        $mime = get_post_mime_type($id);
        if (get_post_type($id) !== 'attachment' || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true) || !current_user_can('read_post', $id)) { self::fail($path, 'Choose an existing readable JPEG, PNG, GIF, WebP or AVIF attachment.'); }
        $url = wp_get_attachment_image_url($id, 'full');
        if (!is_string($url) || !preg_match('~^https?://~i', $url)) { self::fail($path, 'The attachment has no usable image URL.'); }
        self::safe_url($url, $path);
        return $url;
    }
    private static function fail(string $path, string $message): void { throw new FG_Failure('invalid_arguments', $path . ': ' . $message); }
    /** Gutenberg's attribute serializer cannot preserve literal entity spelling through a save/reopen. */
    private static function contains_entity(string $value): bool { return (bool) preg_match('/&(?:#[xX][0-9a-fA-F]+|#[0-9]+|[A-Za-z][A-Za-z0-9]+);/', $value); }
    /** Input text is literal, not an HTML string: preserve already-written entity names too. */
    private static function literal(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true); }

    public static function serialize(array $normalized): string {
        $blocks = self::normalize($normalized);
        $html = implode("\n\n", array_map([self::class, 'save'], $blocks));
        if (strlen($html) > self::MAX_BYTES) { self::fail('blocks', 'Saved block markup exceeds 60,000 bytes. Shorten the design.'); }
        return $html;
    }
    /** Gutenberg's generic support styles omit layout/gap CSS from saved HTML; WP adds those on render. */
    private static function support(array $a): array {
        $s = $a['style'] ?? []; $classes = []; $css = [];
        foreach (['text' => 'color', 'background' => 'background-color'] as $key => $property) {
            if (isset($s['color'][$key])) { $classes[] = $key === 'text' ? 'has-text-color' : 'has-background'; $css[$property] = $s['color'][$key]; }
        }
        foreach (['margin', 'padding'] as $kind) { foreach (['top', 'right', 'bottom', 'left'] as $side) { if (isset($s['spacing'][$kind][$side])) { $css[$kind . '-' . $side] = $s['spacing'][$kind][$side]; } } }
        foreach (['fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'letterSpacing' => 'letter-spacing', 'lineHeight' => 'line-height'] as $key => $property) { if (isset($s['typography'][$key])) { $css[$property] = $s['typography'][$key]; } }
        return [$classes, $css];
    }
    private static function props(array $classes, array $css = [], ?string $anchor = null): string {
        $out = $classes ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '';
        if ($anchor !== null) { $out .= ' id="' . esc_attr($anchor) . '"'; }
        if ($css) { $pairs = []; foreach ($css as $property => $value) { $pairs[] = $property . ':' . $value; } $out .= ' style="' . esc_attr(implode(';', $pairs)) . '"'; }
        return $out;
    }
    private static function save(array $node): string {
        $name = $node['name']; $a = $node['attributes']; $comment = $a;
        $children = implode("\n\n", array_map([self::class, 'save'], $node['innerBlocks']));
        [$colors, $css] = self::support($a);
        $base = ['core/paragraph', 'core/list-item'];
        $classes = in_array($name, $base, true) ? [] : ['wp-block-' . substr($name, 5)];
        if (isset($a['align']) && $name !== 'core/paragraph') { $classes[] = 'align' . $a['align']; }
        $anchor = $a['anchor'] ?? null;
        unset($comment['anchor']);
        if ($name === 'core/heading' || $name === 'core/paragraph') {
            $alignment = $a[$name === 'core/heading' ? 'textAlign' : 'align'] ?? '';
            if ($alignment !== '') { $classes[] = 'has-text-align-' . $alignment; }
            $tag = $name === 'core/heading' ? 'h' . ($a['level'] ?? 2) : 'p';
            $html = '<' . $tag . self::props(array_merge($classes, $colors), $css, $anchor) . '>' . self::literal($a['content']) . '</' . $tag . '>';
            unset($comment['content']);
            if (($comment['level'] ?? null) === 2) { unset($comment['level']); }
        } elseif ($name === 'core/image') {
            $url = self::media_url($a['id'], 'image.id');
            if (isset($a['sizeSlug'])) { $classes[] = 'size-' . $a['sizeSlug']; }
            $html = '<figure' . self::props($classes, $css, $anchor) . '><img src="' . self::literal($url) . '" alt="' . self::literal($a['alt'] ?? '') . '" class="wp-image-' . $a['id'] . '"/>';
            if (($a['caption'] ?? '') !== '') { $html .= '<figcaption class="wp-element-caption">' . self::literal($a['caption']) . '</figcaption>'; }
            $html .= '</figure>'; unset($comment['alt'], $comment['caption']);
        } elseif ($name === 'core/button') {
            if (isset($a['width'])) { $classes[] = 'has-custom-width'; $classes[] = 'wp-block-button__width-' . $a['width']; }
            $link_classes = array_merge(['wp-block-button__link'], $colors);
            if (isset($a['textAlign'])) { $link_classes[] = 'has-text-align-' . $a['textAlign']; }
            if (isset($a['style']['typography']['fontSize'])) { $link_classes[] = 'has-custom-font-size'; }
            $link_classes[] = 'wp-element-button';
            $html = '<div' . self::props($classes, [], $anchor) . '><a' . self::props($link_classes, $css) . ' href="' . self::literal($a['url']) . '"';
            if (isset($a['linkTarget'])) { $html .= ' target="' . esc_attr($a['linkTarget']) . '"'; }
            if (($a['linkTarget'] ?? '') === '_blank') { $html .= ' rel="noopener noreferrer"'; }
            $html .= '>' . self::literal($a['text']) . '</a></div>'; unset($comment['text'], $comment['url'], $comment['linkTarget']);
        } elseif ($name === 'core/cover') {
            if (isset($a['isDark']) && !$a['isDark']) { $classes[] = 'is-light'; }
            if (isset($a['contentPosition']) && $a['contentPosition'] !== 'center center') { $classes[] = 'has-custom-content-position'; $classes[] = 'is-position-' . str_replace(' ', '-', $a['contentPosition']); }
            if (isset($a['minHeight'])) { $css['min-height'] = $a['minHeight'] . ($a['minHeightUnit'] ?? 'px'); }
            $html = '<div' . self::props(array_merge($classes, $colors), $css, $anchor) . '>';
            if (isset($a['id'])) {
                $url = self::media_url($a['id'], 'cover.id'); $comment = array_merge(['url' => $url], $comment);
                $html .= '<img class="wp-block-cover__image-background wp-image-' . $a['id'] . '" alt="' . self::literal($a['alt'] ?? '') . '" src="' . self::literal($url) . '" data-object-fit="cover"/>';
            }
            $ratio = isset($a['dimRatio']) ? (int) (round($a['dimRatio'] / 10) * 10) : 100;
            $html .= '<span aria-hidden="true"' . self::props(['wp-block-cover__background', 'has-background-dim-' . $ratio, 'has-background-dim'], isset($a['customOverlayColor']) ? ['background-color' => $a['customOverlayColor']] : []) . '></span><div class="wp-block-cover__inner-container">' . $children . '</div></div>';
            if (($comment['dimRatio'] ?? null) === 100) { unset($comment['dimRatio']); }
            if (($comment['isDark'] ?? null) === true) { unset($comment['isDark']); }
            if (($comment['alt'] ?? null) === '') { unset($comment['alt']); }
        } elseif ($name === 'core/list' || $name === 'core/list-item') {
            if ($name === 'core/list-item') {
                $html = '<li' . self::props($colors, $css, $anchor) . '>' . self::literal($a['content']) . $children . '</li>'; unset($comment['content']);
            } else {
                $tag = !empty($a['ordered']) ? 'ol' : 'ul';
                $html = '<' . $tag . self::props(array_merge($classes, $colors), $css, $anchor);
                if (!empty($a['reversed'])) { $html .= ' reversed'; }
                if (isset($a['start'])) { $html .= ' start="' . $a['start'] . '"'; }
                $html .= '>' . $children . '</' . $tag . '>';
                if (($comment['ordered'] ?? null) === false) { unset($comment['ordered']); }
            }
        } elseif ($name === 'core/separator') {
            $classes[] = 'has-alpha-channel-opacity';
            if (isset($a['style']['color']['background'])) {
                $classes[] = 'has-text-color'; $classes[] = 'has-background';
                $css['color'] = $a['style']['color']['background'];
            }
            $html = '<hr' . self::props($classes, $css, $anchor) . '/>';
        } elseif ($name === 'core/spacer') {
            $css['height'] = $a['height'] ?? '100px';
            $html = '<div' . self::props($classes, $css, $anchor) . ' aria-hidden="true"></div>';
            if (($comment['height'] ?? null) === '100px') { unset($comment['height']); }
        } else {
            $tag = $name === 'core/group' ? ($a['tagName'] ?? 'div') : 'div';
            if (isset($a['verticalAlignment'])) { $classes[] = ($name === 'core/columns' ? 'are-vertically-aligned-' : 'is-vertically-aligned-') . $a['verticalAlignment']; }
            if ($name === 'core/columns' && isset($a['isStackedOnMobile']) && !$a['isStackedOnMobile']) { $classes[] = 'is-not-stacked-on-mobile'; }
            if ($name === 'core/column' && isset($a['width'])) { $css['flex-basis'] = $a['width']; }
            if ($name === 'core/buttons' && isset($a['style']['typography']['fontSize'])) { $classes[] = 'has-custom-font-size'; }
            $html = '<' . $tag . self::props(array_merge($classes, $colors), $css, $anchor) . '>' . $children . '</' . $tag . '>';
            if (($comment['tagName'] ?? null) === 'div') { unset($comment['tagName']); }
            if (($comment['isStackedOnMobile'] ?? null) === true) { unset($comment['isStackedOnMobile']); }
        }
        return get_comment_delimited_block_content($name, $comment, $html);
    }
}
