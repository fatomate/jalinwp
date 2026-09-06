<?php
/** Typed, bounded page authoring through the gateway's reviewed or YOLO execution boundary. */
defined( 'ABSPATH' ) || exit;

final class FG_Page_Design {
	private const WRITE_TOOLS = array( 'wp_page_design_create', 'wp_page_design_update' );
	private const PAGE_FIELDS = array( 'title', 'excerpt', 'slug', 'status', 'parent', 'menu_order', 'featured_media' );

	public static function boot(): void {
		// Core restores revisioned template metadata with the page's content (WordPress 6.4+).
		add_filter( 'wp_post_revision_meta_keys', static function ( array $keys, string $post_type ): array {
			if ( 'page' === $post_type && ! in_array( '_wp_page_template', $keys, true ) ) { $keys[] = '_wp_page_template'; }
			return $keys;
		}, 10, 2 );
	}

	public static function register(): void {
		FG_Tools::register( 'wp_page_layouts_list', 'Discover page-scoped layouts: Theme Default, JalinWP Canvas without theme wrappers, and eligible existing templates. Use the exact returned identifier. Does not edit theme files.', FG_Tools::schema( array( 'id' => FG_Tools::id() ) ), array( self::class, 'layouts_list' ), false, 'edit_pages' );
		FG_Tools::register( 'wp_page_design_get', 'Read a page, its effective layout, bounded Gutenberg source/structure, edit links, and expected_state. Unknown blocks are preserved by layout-only updates. Retrieve all source windows before replacing content.', FG_Tools::schema( array( 'id' => FG_Tools::id(), 'content_offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000000 ), 'content_length' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20000 ) ), array( 'id' ) ), array( self::class, 'get' ), false, 'edit_pages' );
		$fields = self::fields_schema();
		FG_Tools::register( 'wp_page_design_validate', 'Validate a structured page design without saving using strict PHP block adapters. Returns canonical markup and a block outline. Unsupported blocks/attributes are rejected. Existing-page validation requires id and expected_state. Reviewed Changes also requires installed Gutenberg editor validation during dashboard approval; YOLO Mode uses PHP validation without that browser check.', self::design_schema( $fields + array( 'id' => FG_Tools::id(), 'expected_state' => self::state_schema() ) ), array( self::class, 'validate' ), false, 'edit_pages' );
		FG_Tools::register( 'wp_page_design_create', 'Create an editable Gutenberg page. Defaults to draft and Theme Default. Use JalinWP Canvas for a page without theme header/footer/sidebar. Validates and freezes the exact supported blocks and layout before execution.', self::design_schema( $fields, array( 'title', 'blocks' ) ), array( self::class, 'unprepared_write' ), true, 'edit_pages' );
		FG_Tools::register( 'wp_page_design_update', 'Update only supplied page fields. Requires expected_state from wp_page_design_get. Omit blocks for a layout-only change that preserves all content exactly. Existing-page updates require recoverable revisions and transactional storage.', self::design_schema( $fields + array( 'id' => FG_Tools::id(), 'expected_state' => self::state_schema() ), array( 'id', 'expected_state' ) ), array( self::class, 'unprepared_write' ), true, 'edit_pages' );
	}

	private static function state_schema(): array { return array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'minLength' => 64, 'maxLength' => 64 ); }

	private static function fields_schema(): array {
		return array(
			'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
			'excerpt' => array( 'type' => 'string', 'maxLength' => 10000 ),
			'slug' => array( 'type' => 'string', 'maxLength' => 200 ),
			'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ),
			'layout' => array( 'type' => 'string', 'maxLength' => 240, 'description' => 'Exact identifier from wp_page_layouts_list; default selects Theme Default.' ),
			'parent' => array( 'type' => 'integer', 'minimum' => 0 ),
			'menu_order' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ),
			'featured_media' => array( 'type' => 'integer', 'minimum' => 0 ),
			'blocks' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => array( 'type' => 'object', '$ref' => '#/$defs/designBlock' ), 'description' => 'Complete replacement block tree, up to 100 total blocks and eight levels. Text is plain text. Use existing image attachment IDs. Omit when changing only page fields/layout.' ),
		);
	}

	private static function design_schema( array $fields, array $required = array() ): array {
		$schema = FG_Tools::schema( $fields, $required );
		$schema['$defs'] = array( 'designBlock' => self::node_schema() );
		return $schema;
	}

	/** A recursive JSON Schema reference keeps the MCP catalog small. WordPress does
	 * not resolve $ref; normalize() independently enforces every nested field and limit. */
	private static function node_schema(): array {
		$properties = array();
		foreach ( FG_Design_Blocks::supported() as $schema ) {
			foreach ( $schema['properties'] ?? array() as $name => $definition ) {
				// Exact per-block validation is also performed by the adapter. A union advertises the bounded fields.
				if ( isset( $properties[ $name ] ) ) { $properties[ $name ] = self::merge_attribute_schema( $properties[ $name ], $definition ); }
				else { $properties[ $name ] = $definition; }
			}
		}
		$node = array( 'name' => array( 'type' => 'string', 'enum' => array_keys( FG_Design_Blocks::supported() ) ), 'attributes' => array( 'type' => 'object', 'properties' => $properties ?: (object) array(), 'additionalProperties' => false ) );
		$node['innerBlocks'] = array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'object', '$ref' => '#/$defs/designBlock' ) );
		return FG_Tools::schema( $node, array( 'name' ) );
	}

	private static function merge_attribute_schema( array $left, array $right ): array {
		if ( $left === $right ) { return $left; }
		if ( ( $left['type'] ?? null ) !== ( $right['type'] ?? null ) ) { return array( 'anyOf' => array( $left, $right ) ); }
		if ( 'object' === ( $left['type'] ?? null ) ) {
			foreach ( $right['properties'] ?? array() as $key => $value ) { $left['properties'][ $key ] = isset( $left['properties'][ $key ] ) ? self::merge_attribute_schema( $left['properties'][ $key ], $value ) : $value; }
			$left['required'] = array_values( array_intersect( $left['required'] ?? array(), $right['required'] ?? array() ) );
			return $left;
		}
		if ( isset( $left['enum'], $right['enum'] ) ) { $left['enum'] = array_values( array_unique( array_merge( $left['enum'], $right['enum'] ) ) ); }
		foreach ( array( 'minimum', 'minLength' ) as $key ) { if ( isset( $left[ $key ], $right[ $key ] ) ) { $left[ $key ] = min( $left[ $key ], $right[ $key ] ); } }
		foreach ( array( 'maximum', 'maxLength' ) as $key ) { if ( isset( $left[ $key ], $right[ $key ] ) ) { $left[ $key ] = max( $left[ $key ], $right[ $key ] ); } }
		return $left;
	}

	public static function layouts_list( array $args ): array {
		$id = (int) ( $args['id'] ?? 0 );
		if ( $id ) { self::page( $id ); }
		return array( 'data' => FG_Page_Layouts::choices( $id ), 'theme' => array( 'name' => wp_get_theme()->get( 'Name' ), 'block_theme' => wp_is_block_theme(), 'canvas_mode' => wp_is_block_theme() && function_exists( 'register_block_template' ) ? 'block_template' : 'php_template' ), 'limit' => 100 );
	}

	private static function page( int $id ): WP_Post {
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) { throw new FG_Failure( 'forbidden', 'The page is unavailable or your WordPress account cannot edit it.' ); }
		return $post;
	}

	public static function get( array $args ): array {
		$post = self::page( (int) $args['id'] );
		$content = $post->post_content;
		if ( ! preg_match( '//u', $content ) ) { throw new FG_Failure( 'invalid_content', 'The saved page contains invalid UTF-8 content.' ); }
		$offset = (int) ( $args['content_offset'] ?? 0 );
		$limit = (int) ( $args['content_length'] ?? 20000 );
		$total = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : (int) preg_match_all( '/./us', $content );
		$window = function_exists( 'mb_substr' ) ? mb_substr( $content, $offset, $limit, 'UTF-8' ) : implode( '', array_slice( preg_split( '//u', $content, -1, PREG_SPLIT_NO_EMPTY ), $offset, $limit ) );
		$count = 0;
		$truncated = false;
		// Parse only bounded source. Large pages remain accessible through source windows.
		$structure = strlen( $content ) <= 200000 ? self::structure( parse_blocks( $content ), $count, $truncated ) : array();
		return array( 'data' => array(
			'id' => $post->ID, 'title' => substr( $post->post_title, 0, 500 ), 'status' => $post->post_status, 'slug' => $post->post_name,
			'layout' => FG_Page_Layouts::current( $post->ID ), 'expected_state' => self::state( $post->ID ),
			'content' => $window,
			'content_window' => array( 'offset' => $offset, 'total_characters' => $total, 'complete' => 0 === $offset && $total <= $limit, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null ),
			'blocks' => $structure, 'blocks_truncated' => $truncated || strlen( $content ) > 200000,
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ), 'view_url' => get_permalink( $post->ID ),
			'recovery_available' => wp_revisions_enabled( $post ) && ( -1 === wp_revisions_to_keep( $post ) || wp_revisions_to_keep( $post ) >= 2 ),
			'note' => 'Returned attributes are the saved block attributes, not a promise that every installed block is supported for creation. Source windows contain the exact saved markup. Layout-only updates preserve it.',
		) );
	}

	private static function structure( array $blocks, int &$count, bool &$truncated, int $depth = 1 ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( $count >= 100 || $depth > 8 ) { $truncated = true; break; }
			if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) { continue; }
			++$count;
			$attrs = wp_json_encode( $block['attrs'] );
			$result[] = array( 'name' => $block['blockName'] ?: 'core/freeform', 'attributes' => strlen( $attrs ) <= 3000 ? (object) $block['attrs'] : (object) array(), 'attributes_truncated' => strlen( $attrs ) > 3000, 'creation_supported' => isset( FG_Design_Blocks::supported()[ $block['blockName'] ?? '' ] ), 'innerBlocks' => self::structure( $block['innerBlocks'], $count, $truncated, $depth + 1 ) );
		}
		return $result;
	}

	/** Fresh SQL reads avoid an object-cache hit hiding edits between proposal and application. */
	public static function state( int $id ): string {
		global $wpdb;
		self::page( $id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_wp_page_template','_thumbnail_id') ORDER BY meta_key,meta_id", $id ), ARRAY_A );
		if ( ! is_array( $row ) || ! is_array( $meta ) || $wpdb->last_error ) { throw new FG_Failure( 'storage_unavailable', 'The page state could not be read safely.' ); }
		// Native editor locks/autosaves are intentionally excluded; they do not change saved page content.
		$layout = 'default';
		foreach ( $meta as $item ) { if ( '_wp_page_template' === $item['meta_key'] && $item['meta_value'] ) { $layout = $item['meta_value']; } }
		return hash( 'sha256', wp_json_encode( array( 'post' => $row, 'meta' => $meta, 'layout_identity' => FG_Page_Layouts::identity( $layout, $id ) ) ) );
	}

	public static function prepare( string $tool, array $args ): array {
		if ( ! in_array( $tool, self::WRITE_TOOLS, true ) ) { return $args; }
		if ( ! current_user_can( 'edit_pages' ) ) { throw new FG_Failure( 'forbidden', 'Your WordPress account cannot create or edit pages.' ); }
		$is_update = 'wp_page_design_update' === $tool;
		$id = $is_update ? (int) ( $args['id'] ?? 0 ) : 0;
		$post = $is_update ? self::page( $id ) : null;
		if ( $post ) {
			if ( ! isset( $args['expected_state'] ) || ! hash_equals( self::state( $id ), (string) $args['expected_state'] ) ) { throw new FG_Failure( 'page_changed', 'The page changed. Read the current page with wp_page_design_get before submitting a new change.' ); }
			if ( ! wp_revisions_enabled( $post ) || ( -1 !== wp_revisions_to_keep( $post ) && wp_revisions_to_keep( $post ) < 2 ) ) { throw new FG_Failure( 'recovery_unavailable', 'Updating this page requires at least two retained WordPress revisions so content and layout can be recovered together. Enable revisions or create a draft copy.' ); }
		}
		if ( ! $is_update ) { $args += array( 'status' => 'draft', 'layout' => 'default' ); }
		if ( ! array_intersect( array_keys( $args ), array_merge( self::PAGE_FIELDS, array( 'blocks', 'layout' ) ) ) ) { throw new FG_Failure( 'invalid_arguments', 'Supply a page field, layout, or block design to change.' ); }
		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_pages' ) ) { throw new FG_Failure( 'forbidden', 'Publishing pages requires the WordPress publish_pages capability.' ); }
		foreach ( array( 'title', 'excerpt' ) as $field ) { if ( isset( $args[ $field ] ) && wp_strip_all_tags( $args[ $field ] ) !== $args[ $field ] ) { throw new FG_Failure( 'invalid_arguments', $field . ' must be plain text. Put the page design in blocks.' ); } }
		if ( isset( $args['slug'] ) ) { $args['slug'] = sanitize_title( $args['slug'] ); }
		if ( isset( $args['layout'] ) ) { $args['layout'] = FG_Page_Layouts::validate( $args['layout'], $id ); }
		if ( isset( $args['parent'] ) && $args['parent'] ) { self::page( (int) $args['parent'] ); }
		if ( isset( $args['featured_media'] ) && $args['featured_media'] && ( 'attachment' !== get_post_type( $args['featured_media'] ) || ! current_user_can( 'read_post', $args['featured_media'] ) ) ) { throw new FG_Failure( 'forbidden', 'The featured media item is not available to your account.' ); }
		if ( array_key_exists( 'blocks', $args ) ) { $args['blocks'] = FG_Design_Blocks::normalize( $args['blocks'] ); }
		if ( strlen( wp_json_encode( $args ) ) > 100000 ) { throw new FG_Failure( 'too_large', 'The structured design exceeds 100 KB. Use a smaller design.' ); }
		return $args;
	}

	public static function validate( array $args ): array {
		$tool = isset( $args['id'] ) ? 'wp_page_design_update' : 'wp_page_design_create';
		$valid = rest_validate_value_from_schema( $args, FG_Tools::definition( $tool )['schema'], 'arguments' );
		if ( is_wp_error( $valid ) ) { throw new FG_Failure( 'invalid_arguments', $valid->get_error_message() ); }
		$args = self::prepare( $tool, $args );
		$context = self::proposal_context( $tool, $args );
		$mode = FG_Tools::write_mode();
		$editor_validation = 'yolo' === $mode ? 'Not Run in YOLO Mode; PHP Validation Only' : ( 'reviewed' === $mode ? 'Required During Administrator Review' : 'Not Run; This Connection Is Read Only' );
		return array( 'data' => array( 'valid' => true, 'normalized' => $args, 'content' => $context['content'], 'content_sha256' => $context['content_sha256'], 'outline' => $context['outline'], 'layout' => $context['layout'], 'validation_method' => 'Strict PHP Block Adapters', 'write_mode' => $mode, 'installed_editor_validation' => $editor_validation, 'writes_performed' => false ) );
	}

	public static function proposal_context( string $tool, array $args ): array {
		$id = (int) ( $args['id'] ?? 0 );
		$before = array();
		if ( $id ) {
			$post = self::page( $id );
			$before = array( 'id' => $id, 'title' => substr( $post->post_title, 0, 500 ), 'status' => $post->post_status, 'slug' => $post->post_name, 'excerpt' => substr( $post->post_excerpt, 0, 1000 ), 'parent' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order, 'featured_media' => (int) get_post_thumbnail_id( $id ), 'layout' => get_page_template_slug( $id ) ?: 'default' );
		}
		$layout = $args['layout'] ?? ( $id ? ( get_page_template_slug( $id ) ?: 'default' ) : 'default' );
		$content = array_key_exists( 'blocks', $args ) ? FG_Design_Blocks::serialize( $args['blocks'] ) : null;
		if ( null !== $content && strlen( $content ) > 60000 ) { throw new FG_Failure( 'too_large', 'The generated page exceeds 60 KB. Use a smaller design.' ); }
		$count = 0; $truncated = false;
		return array( 'version' => 1, 'content' => $content, 'content_sha256' => null !== $content ? hash( 'sha256', $content ) : null, 'outline' => null !== $content ? self::structure( parse_blocks( $content ), $count, $truncated ) : array(), 'expected_state' => $args['expected_state'] ?? null, 'layout_identity' => FG_Page_Layouts::identity( $layout, $id ), 'layout' => $layout, 'fields' => array_intersect_key( $args, array_fill_keys( self::PAGE_FIELDS, true ) ), 'before_fields' => $before, 'layout_only' => ! array_key_exists( 'blocks', $args ), 'recovery' => $id ? 'Paired Content and Layout Revision' : 'New Page' );
	}

	public static function review( string $tool, array $args ): array { return self::proposal_context( $tool, $args ); }

	public static function check_preconditions( string $tool, array $args, array $context ): void {
		self::prepare( $tool, $args );
		$current = self::proposal_context( $tool, $args );
		foreach ( array( 'content_sha256', 'layout_identity', 'layout', 'expected_state' ) as $key ) {
			if ( ! array_key_exists( $key, $context ) || $current[ $key ] !== $context[ $key ] ) { throw new FG_Failure( 'page_changed', 'The page or design resources changed. Read the current page and submit a new change.' ); }
		}
		if ( $current['content'] !== ( $context['content'] ?? null ) ) { throw new FG_Failure( 'invalid_change', 'The frozen design content does not match the validated change.' ); }
	}

	public static function unprepared_write( array $args ): never {
		throw new FG_Failure( 'review_required', 'Page designs must use the gateway execution path with the frozen, validated change context.' );
	}

	/** Transactions serialize the page row and its metadata across native core/editor writes. */
	private static function begin_transaction( int $page_id ): void {
		global $wpdb;
		$is_sqlite = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE || false !== stripos( get_class( $wpdb ), 'sqlite' );
		if ( ! $is_sqlite ) {
			$engines = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s)', $wpdb->posts, $wpdb->postmeta ), ARRAY_A );
			if ( count( (array) $engines ) !== 2 ) { throw new FG_Failure( 'transaction_unavailable', 'Could not verify transactional page storage. Create the page through WordPress or ask the host to confirm InnoDB storage.' ); }
			foreach ( $engines as $engine ) { if ( 'innodb' !== strtolower( (string) $engine['ENGINE'] ) ) { throw new FG_Failure( 'transaction_unavailable', 'Page designs require InnoDB posts and post metadata tables for coordinated writes.' ); } }
		}
		// WordPress' SQLite integration parses MySQL transaction syntax. A conflicting
		// SQLite writer causes a busy/snapshot error and rollback, never an unlocked retry.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new FG_Failure( 'transaction_unavailable', 'Page write coordination could not be started.' ); }
		if ( $page_id ) {
			$suffix = $is_sqlite ? '' : ' FOR UPDATE';
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d" . $suffix, $page_id ) );
			if ( ! $row || $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); throw new FG_Failure( 'transaction_unavailable', 'The page could not be locked for this update.' ); }
			$wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d" . $suffix, $page_id ) );
			if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); throw new FG_Failure( 'transaction_unavailable', 'The page layout could not be locked for this update.' ); }
			clean_post_cache( $page_id );
		}
	}

	public static function execute_prepared( string $tool, array $args, array $context ): array {
		global $wpdb;
		if ( ! in_array( $tool, self::WRITE_TOOLS, true ) ) { throw new FG_Failure( 'invalid_change', 'Unsupported page design operation.' ); }
		$id = (int) ( $args['id'] ?? 0 );
		self::begin_transaction( $id );
		$revision = 0;
		try {
			self::check_preconditions( $tool, $args, $context );
			if ( $id ) {
				$force = static fn(): bool => false;
				add_filter( 'wp_save_post_revision_check_for_changes', $force );
				try { $revision = wp_save_post_revision( $id ); }
				finally { remove_filter( 'wp_save_post_revision_check_for_changes', $force ); }
				if ( is_wp_error( $revision ) || ! $revision || ! get_post( $revision ) ) { throw new FG_Failure( 'recovery_unavailable', 'A recovery revision could not be saved. No page update was committed.' ); }
				// Assert the paired layout is present even when a site has customized revision hooks.
				$old_layout = get_page_template_slug( $id ) ?: 'default';
				if ( get_post_meta( $revision, '_wp_page_template', true ) !== $old_layout ) {
					update_metadata( 'post', $revision, '_wp_page_template', $old_layout );
					if ( get_post_meta( $revision, '_wp_page_template', true ) !== $old_layout ) { throw new FG_Failure( 'recovery_unavailable', 'The recovery layout could not be saved. No page update was committed.' ); }
				}
			}
			$params = array_intersect_key( $args, array_fill_keys( self::PAGE_FIELDS, true ) );
			if ( array_key_exists( 'blocks', $args ) ) { $params['content'] = $context['content']; }
			if ( isset( $args['layout'] ) ) { $params['template'] = 'default' === $args['layout'] ? '' : $args['layout']; }
			$params['_fields'] = 'id,status,slug,link,template,modified_gmt';
			$result = FG_Tools::rest( 'POST', '/wp/v2/pages' . ( $id ? '/' . $id : '' ), $params );
			$written_id = (int) ( $result['data']['id'] ?? 0 );
			if ( ! $written_id ) { throw new FG_Failure( 'write_unverified', 'WordPress did not confirm the page write. Inspect the page before submitting another change.' ); }
			clean_post_cache( $written_id );
			$saved = self::page( $written_id );
			if ( array_key_exists( 'blocks', $args ) && $saved->post_content !== $context['content'] ) { throw new FG_Failure( 'write_unverified', 'A WordPress filter changed the validated design markup. No page write was committed. Inspect plugin filters before retrying.' ); }
			if ( isset( $args['layout'] ) && ( get_page_template_slug( $written_id ) ?: 'default' ) !== $args['layout'] ) { throw new FG_Failure( 'write_unverified', 'The requested page layout was not saved. No page write was committed.' ); }
			if ( $revision && ! get_post( $revision ) ) { throw new FG_Failure( 'recovery_unavailable', 'The recovery revision was removed by a site hook. No page write was committed.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new FG_Failure( 'write_unverified', 'The transaction commit could not be verified. Inspect the page before submitting another change.' ); }
			$result['data']['edit_url'] = get_edit_post_link( $written_id, 'raw' );
			$result['data']['expected_state'] = self::state( $written_id );
			$result['data']['recovery_revision_id'] = $revision ?: null;
			$result['data']['recovery_note'] = $revision ? 'Restore this revision in WordPress while JalinWP is active to restore the paired content and layout. Revision retention still applies.' : 'New page; use WordPress to edit or trash the draft.';
			return $result;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $id ) { clean_post_cache( $id ); }
			if ( ! empty( $written_id ) ) { clean_post_cache( $written_id ); }
			if ( $revision && ! is_wp_error( $revision ) ) { clean_post_cache( $revision ); }
			throw $error;
		}
	}
}
