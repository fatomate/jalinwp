<?php
/**
 * Typed WordPress operations exposed by the gateway.
 *
 * All writes use the central reviewed or YOLO execution boundary before these handlers execute.
 * Core REST controllers retain their object-level permission checks. Responses
 * are projected here as well as requested with _fields: an internal REST
 * dispatch must never be assumed to apply the HTTP response field filter.
 */

defined( 'ABSPATH' ) || exit;

final class FG_WP_Tools {

	private const CONTENT_FIELDS = array( 'id', 'type', 'status', 'slug', 'link', 'date', 'date_gmt', 'modified_gmt', 'author', 'featured_media', 'parent', 'menu_order', 'categories', 'tags', 'comment_status', 'ping_status', 'sticky' );
	private const TERM_FIELDS = array( 'id', 'name', 'slug', 'taxonomy', 'parent', 'count', 'link', 'description' );
	private const MEDIA_FIELDS = array( 'id', 'slug', 'link', 'date', 'modified', 'post', 'media_type', 'mime_type', 'source_url', 'alt_text' );

	public static function register(): void {
		$kind = array( 'type' => 'string', 'enum' => array( 'posts', 'pages' ), 'description' => 'WordPress content collection.' );
		$taxonomy = array( 'type' => 'string', 'enum' => array( 'categories', 'tags' ) );
		$id = FG_Tools::id();
		$page = FG_Tools::pagination();
		$write = ' Execution follows this connection\'s write mode. Native WordPress permissions still apply.';

		FG_Tools::register( 'wp_content_list', 'Find posts or pages, including drafts. Returns paginated summaries; use wp_content_get for source content.', FG_Tools::schema( array_merge( $page, array(
			'kind' => $kind,
			'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'any' ) ),
			'order' => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
			'orderby' => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'id' ) ),
			'author' => $id,
		) ), array( 'kind' ) ), array( self::class, 'content_list' ), false, 'read' );

		FG_Tools::register( 'wp_content_get', 'Read editable post or page source, including Gutenberg comments. content_window describes the returned character range; retrieve all ranges before replacing long content.', FG_Tools::schema( array(
			'kind' => $kind, 'id' => $id,
			'content_offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000000 ),
			'content_length' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20000 ),
		) , array( 'kind', 'id' ) ), array( self::class, 'content_get' ), false, 'read' );

		$fields = self::content_schema();
		FG_Tools::register( 'wp_content_create', 'Create a post or page. New content defaults to draft; publish/private/future require an explicit status. HTML is filtered with wp_kses_post, including for administrators.' . $write, FG_Tools::schema( array_merge( array( 'kind' => $kind ), $fields ), array( 'kind', 'title' ) ), array( self::class, 'content_create' ), true, 'read' );
		FG_Tools::register( 'wp_content_update', 'Update only supplied post or page fields; omitted fields retain their values. content replaces the complete source, so first retrieve all content windows. HTML is filtered with wp_kses_post.' . $write, FG_Tools::schema( array_merge( array( 'kind' => $kind, 'id' => $id ), $fields ), array( 'kind', 'id' ) ), array( self::class, 'content_update' ), true, 'read' );
		FG_Tools::register( 'wp_content_trash', 'Move a post or page to WordPress Trash using force=false. Permanent deletion is unavailable.' . $write, FG_Tools::schema( array( 'kind' => $kind, 'id' => $id ), array( 'kind', 'id' ) ), array( self::class, 'content_trash' ), true, 'read' );

		FG_Tools::register( 'wp_terms_list', 'List categories or tags with pagination. No custom taxonomy or raw metadata access.', FG_Tools::schema( array_merge( $page, array( 'taxonomy' => $taxonomy, 'hide_empty' => array( 'type' => 'boolean' ) ) ), array( 'taxonomy' ) ), array( self::class, 'terms_list' ), false, 'edit_posts' );
		$terms = array( 'taxonomy' => $taxonomy, 'name' => self::string_schema( 200, 1 ), 'slug' => self::string_schema( 200 ), 'description' => self::string_schema( 5000 ), 'parent' => array( 'type' => 'integer', 'minimum' => 0 ) );
		FG_Tools::register( 'wp_terms_create', 'Create a category or tag. parent is supported only for categories.' . $write, FG_Tools::schema( $terms, array( 'taxonomy', 'name' ) ), array( self::class, 'terms_create' ), true, 'manage_categories' );
		FG_Tools::register( 'wp_terms_update', 'Update a category or tag by ID. parent is supported only for categories.' . $write, FG_Tools::schema( array_merge( array( 'id' => $id ), $terms ), array( 'taxonomy', 'id' ) ), array( self::class, 'terms_update' ), true, 'manage_categories' );

		FG_Tools::register( 'wp_media_list', 'Search existing media and return bounded, paginated metadata. File uploads and remote downloads are unavailable.', FG_Tools::schema( array_merge( $page, array( 'media_type' => array( 'type' => 'string', 'enum' => array( 'image', 'video', 'text', 'application', 'audio' ) ) ) ) ), array( self::class, 'media_list' ), false, 'upload_files' );
		FG_Tools::register( 'wp_media_get', 'Read an existing media item and editable caption, description and alternative text. Does not expose filesystem paths or raw attachment metadata.', FG_Tools::schema( array( 'id' => $id ), array( 'id' ) ), array( self::class, 'media_get' ), false, 'upload_files' );
		FG_Tools::register( 'wp_media_update', 'Update title, alternative text, caption or description on existing media. The underlying file is unchanged; HTML is filtered with wp_kses_post.' . $write, FG_Tools::schema( array( 'id' => $id, 'title' => self::string_schema( 500 ), 'alt_text' => self::string_schema( 2000 ), 'caption' => self::string_schema( 5000 ), 'description' => self::string_schema( 10000 ) ), array( 'id' ) ), array( self::class, 'media_update' ), true, 'upload_files' );

		FG_Tools::register( 'wp_comments_list', 'List comments for moderation. Email, IP address, user agent and raw metadata are excluded; long comment text is marked when truncated.', FG_Tools::schema( array_merge( $page, array( 'post' => $id, 'status' => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash', 'all' ) ) ) ) ), array( self::class, 'comments_list' ), false, 'moderate_comments', true );
		FG_Tools::register( 'wp_comments_update', 'Moderate a comment or replace its text. Supported moderation states are approved, hold, spam and trash; no permanent deletion. Request trash separately from text replacement.' . $write, FG_Tools::schema( array( 'id' => $id, 'status' => array( 'type' => 'string', 'enum' => array( 'approved', 'hold', 'spam', 'trash' ) ), 'content' => self::string_schema( 10000 ) ), array( 'id' ) ), array( self::class, 'comments_update' ), true, 'moderate_comments', true );

		FG_Tools::register( 'wp_site_inspect', 'Read administrator-only site/runtime information or a paginated installed plugin/theme inventory. Does not install, activate, update, edit configuration or expose secrets.', FG_Tools::schema( array_merge( $page, array( 'section' => array( 'type' => 'string', 'enum' => array( 'runtime', 'plugins', 'themes' ) ) ) ), array( 'section' ) ), array( self::class, 'site_inspect' ), false, 'manage_options' );
		FG_Tools::register( 'wp_block_types_list', 'List registered Gutenberg block types with local pagination and optional namespace/search filters. Returns descriptive block metadata; no rendering or code execution.', FG_Tools::schema( array_merge( $page, array( 'namespace' => array( 'type' => 'string', 'maxLength' => 100, 'pattern' => '^[a-z0-9-]+$' ) ) ) ), array( self::class, 'block_types_list' ), false, 'edit_posts' );
	}

	private static function string_schema( int $max, int $min = 0 ): array {
		return array( 'type' => 'string', 'minLength' => $min, 'maxLength' => $max );
	}

	private static function content_schema(): array {
		$ids = array( 'type' => 'array', 'maxItems' => 50, 'uniqueItems' => true, 'items' => FG_Tools::id() );
		return array(
			'title' => self::string_schema( 500 ),
			'content' => self::string_schema( 100000 ),
			'excerpt' => self::string_schema( 10000 ),
			'slug' => self::string_schema( 200 ),
			'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private', 'future' ) ),
			'date' => array( 'type' => 'string', 'maxLength' => 40, 'description' => 'Publication date in site timezone, ISO 8601, e.g. 2026-09-20T10:00:00. Required with status=future.' ),
			'featured_media' => array( 'type' => 'integer', 'minimum' => 0 ),
			'categories' => $ids,
			'tags' => $ids,
			'parent' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Pages only; zero removes the parent.' ),
			'menu_order' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'Pages only.' ),
			'comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
			'ping_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
			'sticky' => array( 'type' => 'boolean', 'description' => 'Posts only.' ),
		);
	}

	/** Dynamic collection capability plus the core controller's item capabilities. */
	private static function content_path( array $args ): string|WP_Error {
		if ( ! in_array( $args['kind'] ?? '', array( 'posts', 'pages' ), true ) ) {
			return self::error( 'Choose posts or pages.' );
		}
		$cap = 'pages' === $args['kind'] ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			return self::error( 'Your WordPress role cannot edit this content type.', 403 );
		}
		return '/wp/v2/' . $args['kind'];
	}

	public static function content_list( array $args ): array|WP_Error {
		$path = self::content_path( $args );
		if ( is_wp_error( $path ) ) { return $path; }
		$params = self::only( $args, array( 'search', 'status', 'order', 'orderby', 'author' ) );
		$params += self::page_params( $args ) + array( 'context' => 'edit', 'status' => 'any', '_fields' => implode( ',', array_merge( self::CONTENT_FIELDS, array( 'title.raw' ) ) ) );
		return self::map_result( FG_Tools::rest( 'GET', $path, $params ), array( self::class, 'content_summary' ), true );
	}

	public static function content_get( array $args ): array|WP_Error {
		$path = self::content_path( $args );
		if ( is_wp_error( $path ) ) { return $path; }
		$result = FG_Tools::rest( 'GET', $path . '/' . (int) $args['id'], array( 'context' => 'edit', '_fields' => implode( ',', array_merge( self::CONTENT_FIELDS, array( 'title.raw', 'content.raw', 'excerpt.raw' ) ) ) ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$item = $result['data'];
		$data = self::content_summary( $item );
		$content = (string) ( $item['content']['raw'] ?? '' );
		$offset = max( 0, (int) ( $args['content_offset'] ?? 0 ) );
		$limit = min( 20000, max( 1, (int) ( $args['content_length'] ?? 20000 ) ) );
		$total = self::length( $content );
		$data['content'] = array( 'raw' => self::substring( $content, $offset, $limit ) );
		$data['content_window'] = array( 'offset' => $offset, 'total_characters' => $total, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null, 'complete' => 0 === $offset && $total <= $limit );
		$data['excerpt'] = self::text_window( (string) ( $item['excerpt']['raw'] ?? '' ), 10000 );
		$result['data'] = $data;
		return $result;
	}

	public static function content_create( array $args ): array|WP_Error {
		return self::content_write( $args, true );
	}

	public static function content_update( array $args ): array|WP_Error {
		return self::content_write( $args, false );
	}

	private static function content_write( array $args, bool $create ): array|WP_Error {
		$path = self::content_path( $args );
		if ( is_wp_error( $path ) ) { return $path; }
		$params = self::only( $args, array_keys( self::content_schema() ) );
		if ( ! $params ) { return self::error( 'Supply at least one content field to update.' ); }
		$invalid = 'pages' === $args['kind'] ? array( 'categories', 'tags', 'sticky' ) : array( 'parent', 'menu_order' );
		foreach ( $invalid as $field ) {
			if ( array_key_exists( $field, $params ) ) { return self::error( $field . ' is not supported for ' . $args['kind'] . '.' ); }
		}
		if ( $create ) { $params += array( 'status' => 'draft' ); }
		if ( 'future' === ( $params['status'] ?? '' ) && empty( $params['date'] ) ) {
			return self::error( 'An explicit publication date is required for scheduled content.' );
		}
		if ( 'future' === ( $params['status'] ?? '' ) ) {
			try { $scheduled = new DateTimeImmutable( $params['date'], wp_timezone() ); }
			catch ( Exception $error ) { return self::error( 'The scheduled publication date is invalid.' ); }
			if ( $scheduled->getTimestamp() <= time() + 60 ) { return self::error( 'Scheduled publication must be more than one minute in the future.' ); }
		}
		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) { $params[ $field ] = wp_kses_post( $params[ $field ] ); }
		}
		if ( ! $create ) { $path .= '/' . (int) $args['id']; }
		$params['_fields'] = implode( ',', array_merge( self::CONTENT_FIELDS, array( 'title.raw' ) ) );
		return self::map_result( FG_Tools::rest( 'POST', $path, $params ), array( self::class, 'content_summary' ) );
	}

	public static function content_trash( array $args ): array|WP_Error {
		$path = self::content_path( $args );
		if ( is_wp_error( $path ) ) { return $path; }
		if ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS <= 0 ) { return self::error( 'WordPress Trash is disabled; permanent deletion is unavailable.' ); }
		return self::map_result( FG_Tools::rest( 'DELETE', $path . '/' . (int) $args['id'], array( 'force' => false, '_fields' => 'id,type,status,link,title.raw' ) ), array( self::class, 'content_summary' ) );
	}

	public static function content_summary( array $item ): array {
		$data = self::only( $item, self::CONTENT_FIELDS );
		$data['title'] = self::text_window( (string) ( $item['title']['raw'] ?? $item['title']['rendered'] ?? '' ), 500 );
		return $data;
	}

	public static function terms_list( array $args ): array|WP_Error {
		$params = self::only( $args, array( 'search', 'hide_empty' ) ) + self::page_params( $args ) + array( 'context' => 'view', 'hide_empty' => false, '_fields' => implode( ',', self::TERM_FIELDS ) );
		return self::map_result( FG_Tools::rest( 'GET', '/wp/v2/' . $args['taxonomy'], $params ), array( self::class, 'term_summary' ), true );
	}

	public static function terms_create( array $args ): array|WP_Error { return self::term_write( $args, true ); }
	public static function terms_update( array $args ): array|WP_Error { return self::term_write( $args, false ); }

	private static function term_write( array $args, bool $create ): array|WP_Error {
		$params = self::only( $args, array( 'name', 'slug', 'description', 'parent' ) );
		if ( ! $params ) { return self::error( 'Supply at least one term field to update.' ); }
		if ( 'tags' === $args['taxonomy'] && array_key_exists( 'parent', $params ) ) { return self::error( 'Tags cannot have a parent.' ); }
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) { $params[ $field ] = wp_kses_post( $params[ $field ] ); }
		}
		$params['_fields'] = implode( ',', self::TERM_FIELDS );
		$path = '/wp/v2/' . $args['taxonomy'] . ( $create ? '' : '/' . (int) $args['id'] );
		return self::map_result( FG_Tools::rest( 'POST', $path, $params ), array( self::class, 'term_summary' ) );
	}

	public static function term_summary( array $item ): array {
		$data = self::only( $item, self::TERM_FIELDS );
		$data['description'] = self::text_window( (string) ( $item['description'] ?? '' ), 1000 );
		return $data;
	}

	public static function media_list( array $args ): array|WP_Error {
		$params = self::only( $args, array( 'search', 'media_type' ) ) + self::page_params( $args ) + array( 'context' => 'edit', '_fields' => implode( ',', array_merge( self::MEDIA_FIELDS, array( 'title.raw' ) ) ) );
		return self::map_result( FG_Tools::rest( 'GET', '/wp/v2/media', $params ), static fn( array $item ): array => self::media_summary( $item, false ), true );
	}

	public static function media_get( array $args ): array|WP_Error {
		return self::map_result( FG_Tools::rest( 'GET', '/wp/v2/media/' . (int) $args['id'], array( 'context' => 'edit', '_fields' => implode( ',', array_merge( self::MEDIA_FIELDS, array( 'title.raw', 'caption', 'description' ) ) ) ) ), array( self::class, 'media_summary' ) );
	}

	public static function media_update( array $args ): array|WP_Error {
		$params = self::only( $args, array( 'title', 'alt_text', 'caption', 'description' ) );
		if ( ! $params ) { return self::error( 'Supply at least one media field to update.' ); }
		foreach ( $params as $field => $value ) {
			$params[ $field ] = 'alt_text' === $field ? sanitize_text_field( $value ) : wp_kses_post( $value );
		}
		$params['_fields'] = implode( ',', array_merge( self::MEDIA_FIELDS, array( 'title.raw', 'caption', 'description' ) ) );
		return self::map_result( FG_Tools::rest( 'POST', '/wp/v2/media/' . (int) $args['id'], $params ), array( self::class, 'media_summary' ) );
	}

	public static function media_summary( array $item, bool $detail = true ): array {
		$data = self::only( $item, self::MEDIA_FIELDS );
		$data['alt_text'] = self::substring( (string) ( $item['alt_text'] ?? '' ), 0, 2000 );
		$data['title'] = self::text_window( (string) ( $item['title']['raw'] ?? $item['title']['rendered'] ?? '' ), 500 );
		if ( $detail ) {
			$data['caption'] = self::text_window( (string) ( $item['caption']['raw'] ?? '' ), 5000 );
			$data['description'] = self::text_window( (string) ( $item['description']['raw'] ?? '' ), 10000 );
		}
		return $data;
	}

	public static function comments_list( array $args ): array|WP_Error {
		$params = self::only( $args, array( 'search', 'post', 'status' ) ) + self::page_params( $args ) + array( 'context' => 'edit', 'status' => 'all', '_fields' => 'id,post,parent,author,author_name,date_gmt,status,type,link,content' );
		return self::map_result( FG_Tools::rest( 'GET', '/wp/v2/comments', $params ), array( self::class, 'comment_summary' ), true );
	}

	public static function comments_update( array $args ): array|WP_Error {
		$params = self::only( $args, array( 'status', 'content' ) );
		if ( ! $params ) { return self::error( 'Supply a moderation status or replacement content.' ); }
		if ( 'trash' === ( $params['status'] ?? '' ) ) {
			if ( array_key_exists( 'content', $params ) ) { return self::error( 'Trash and text replacement must be separate requests.' ); }
			if ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS <= 0 ) { return self::error( 'WordPress Trash is disabled; permanent deletion is unavailable.' ); }
			return self::map_result( FG_Tools::rest( 'DELETE', '/wp/v2/comments/' . (int) $args['id'], array( 'force' => false, '_fields' => 'id,post,parent,author,author_name,date_gmt,status,type,link,content' ) ), array( self::class, 'comment_summary' ) );
		}
		if ( isset( $params['content'] ) ) { $params['content'] = wp_kses_post( $params['content'] ); }
		$params['_fields'] = 'id,post,parent,author,author_name,date_gmt,status,type,link,content';
		return self::map_result( FG_Tools::rest( 'POST', '/wp/v2/comments/' . (int) $args['id'], $params ), array( self::class, 'comment_summary' ) );
	}

	public static function comment_summary( array $item ): array {
		$data = self::only( $item, array( 'id', 'post', 'parent', 'author', 'author_name', 'date_gmt', 'status', 'type', 'link' ) );
		$data['content'] = self::text_window( (string) ( $item['content']['raw'] ?? '' ), 1000 );
		return $data;
	}

	public static function site_inspect( array $args ): array|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'Administrator access is required for site inspection.', 403 ); }
		if ( 'runtime' === $args['section'] ) {
			$theme = wp_get_theme();
			return array( 'data' => array(
				'name' => get_bloginfo( 'name' ),
				'home_url' => home_url( '/' ),
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'locale' => get_locale(),
				'timezone' => wp_timezone_string(),
				'multisite' => is_multisite(),
				'woocommerce_active' => class_exists( 'WooCommerce' ),
				'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'active_theme' => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'stylesheet' => $theme->get_stylesheet() ),
			) );
		}
		$items = array();
		if ( 'plugins' === $args['section'] ) {
			if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
			foreach ( get_plugins() as $file => $plugin ) {
				$items[] = array( 'plugin' => $file, 'name' => wp_strip_all_tags( $plugin['Name'] ), 'version' => $plugin['Version'], 'active' => is_plugin_active( $file ), 'network_active' => is_plugin_active_for_network( $file ), 'requires_wordpress' => $plugin['RequiresWP'] ?? '', 'requires_php' => $plugin['RequiresPHP'] ?? '' );
			}
		} elseif ( 'themes' === $args['section'] ) {
			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				$items[] = array( 'stylesheet' => $stylesheet, 'name' => wp_strip_all_tags( $theme->get( 'Name' ) ), 'version' => $theme->get( 'Version' ), 'active' => get_stylesheet() === $stylesheet, 'parent' => $theme->get( 'Template' ), 'requires_wordpress' => $theme->get( 'RequiresWP' ), 'requires_php' => $theme->get( 'RequiresPHP' ) );
			}
		} else {
			return self::error( 'Choose runtime, plugins or themes.' );
		}
		return self::local_page( $items, $args, array( 'name', 'plugin', 'stylesheet' ) );
	}

	public static function block_types_list( array $args ): array|WP_Error {
		$params = self::only( $args, array( 'namespace' ) ) + array( 'context' => 'view', '_fields' => 'name,title,description,category,api_version,is_dynamic,parent,ancestor' );
		$result = FG_Tools::rest( 'GET', '/wp/v2/block-types', $params );
		if ( is_wp_error( $result ) ) { return $result; }
		$items = array_map( static function( array $block ): array {
			$item = self::only( $block, array( 'name', 'title', 'category', 'api_version', 'is_dynamic', 'parent', 'ancestor' ) );
			$item['description'] = self::substring( (string) ( $block['description'] ?? '' ), 0, 1000 );
			$supported = class_exists( 'FG_Design_Blocks' ) ? FG_Design_Blocks::supported() : array();
			$item['creation_supported'] = isset( $supported[ $item['name'] ] );
			if ( $item['creation_supported'] ) { $item['design_attributes'] = $supported[ $item['name'] ]; }
			return $item;
		}, array_values( $result['data'] ) );
		return self::local_page( $items, $args, array( 'name', 'title', 'description' ) );
	}

	/** Explicit projections protect against extension-added sensitive response fields. */
	private static function only( array $args, array $keys ): array {
		return array_intersect_key( $args, array_fill_keys( $keys, true ) );
	}

	private static function page_params( array $args ): array {
		return array( 'page' => max( 1, (int) ( $args['page'] ?? 1 ) ), 'per_page' => min( 50, max( 1, (int) ( $args['per_page'] ?? 10 ) ) ) );
	}

	private static function map_result( array|WP_Error $result, callable $map, bool $list = false ): array|WP_Error {
		if ( is_wp_error( $result ) ) { return $result; }
		$result['data'] = $list ? array_map( $map, array_values( $result['data'] ) ) : $map( $result['data'] );
		return $result;
	}

	private static function local_page( array $items, array $args, array $search_fields ): array {
		if ( ! empty( $args['search'] ) ) {
			$needle = (string) $args['search'];
			$items = array_values( array_filter( $items, static function( array $item ) use ( $needle, $search_fields ): bool {
				foreach ( $search_fields as $field ) { if ( false !== stripos( (string) ( $item[ $field ] ?? '' ), $needle ) ) { return true; } }
				return false;
			} ) );
		}
		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ) );
		$page = self::page_params( $args );
		$total = count( $items );
		return array( 'data' => array_slice( $items, ( $page['page'] - 1 ) * $page['per_page'], $page['per_page'] ), 'pagination' => $page + array( 'total' => $total, 'total_pages' => (int) ceil( $total / $page['per_page'] ) ) );
	}

	private static function text_window( string $text, int $max ): array {
		return array( 'raw' => self::substring( $text, 0, $max ), 'truncated' => self::length( $text ) > $max, 'total_characters' => self::length( $text ) );
	}

	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : (int) preg_match_all( '/./us', $text );
	}

	private static function substring( string $text, int $offset, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, $offset, $length, 'UTF-8' ); }
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return false === $characters ? '' : implode( '', array_slice( $characters, $offset, $length ) );
	}

	private static function error( string $message, int $status = 400 ): never {
		throw new FG_Failure( 403 === $status ? 'forbidden' : 'invalid_arguments', $message );
	}
}
