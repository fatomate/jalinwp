<?php
/** Page-scoped templates. No theme files are edited. */
defined( 'ABSPATH' ) || exit;

final class FG_Page_Layouts {

	public const CANVAS = 'fames-mcp-canvas';
	private const BLOCK_NAME = 'fames-mcp-gateway//fames-mcp-canvas';
	public static function boot(): void {
		add_action( 'init', array( self::class, 'register_canvas' ), 20 );
		add_filter( 'theme_page_templates', array( self::class, 'page_templates' ), 20 );
		add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
	}

	public static function register_canvas(): void {
		if ( function_exists( 'register_block_template' ) && wp_is_block_theme() ) {
			register_block_template( self::BLOCK_NAME, array(
				'title' => 'JalinWP Canvas',
				'description' => 'Page content without theme header, footer, sidebar, or automatic page title.',
				'content' => self::block_content(),
				'post_types' => array( 'page' ),
			) );
		}
	}

	public static function block_content(): string {
		return (string) file_get_contents( FG_DIR . 'templates/canvas.html' );
	}

	public static function page_templates( array $templates ): array {
		// Do not rename a user/theme override as a guaranteed blank canvas.
		if ( ! isset( $templates[ self::CANVAS ] ) ) { $templates[ self::CANVAS ] = 'JalinWP Canvas'; }
		return $templates;
	}

	public static function template_include( string $template ): string {
		if ( ! is_page() || self::CANVAS !== get_page_template_slug( get_queried_object_id() ) ) { return $template; }
		// Modern block themes use core's template resolution, including explicit theme/user overrides.
		if ( wp_is_block_theme() && function_exists( 'register_block_template' ) && get_block_template( get_stylesheet() . '//' . self::CANVAS, 'wp_template' ) ) { return $template; }
		return FG_DIR . 'templates/canvas.php';
	}

	public static function body_class( array $classes ): array {
		if ( is_page() && self::CANVAS === get_page_template_slug( get_queried_object_id() ) ) { $classes[] = 'fames-canvas-page'; }
		return $classes;
	}

	public static function enqueue(): void {
		if ( ! is_page() || self::CANVAS !== get_page_template_slug( get_queried_object_id() ) ) { return; }
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'fames-mcp-canvas', plugins_url( 'assets/canvas.css', FG_FILE ), array( 'wp-block-library' ), FG_VERSION );
	}

	/** Only choices exposed by WordPress for this page are accepted. */
	public static function choices( int $page_id = 0 ): array {
		$post = $page_id ? get_post( $page_id ) : null;
		$theme = wp_get_theme();
		$items = array( array( 'id' => 'default', 'label' => 'Theme Default', 'source' => 'theme', 'canvas' => false, 'available' => true ) );
		$templates = $theme->get_page_templates( $post, 'page' );
		// Keep Canvas available even on sites with a large template catalog.
		if ( isset( $templates[ self::CANVAS ] ) ) { $templates = array( self::CANVAS => $templates[ self::CANVAS ] ) + $templates; }
		$templates = array_slice( $templates, 0, 99, true );
		foreach ( $templates as $id => $title ) {
			if ( ! is_string( $id ) || strlen( $id ) > 240 || str_contains( $id, '..' ) || str_starts_with( $id, '/' ) ) { continue; }
			$source = 'theme';
			$overridden = false;
			if ( wp_is_block_theme() ) {
				$block = get_block_template( get_stylesheet() . '//' . $id, 'wp_template' );
				if ( $block ) { $source = (string) $block->source; }
				if ( self::CANVAS === $id && $block ) {
					$overridden = 'plugin' !== $source || self::block_content() !== $block->content;
				}
			} elseif ( self::CANVAS === $id ) { $source = 'plugin'; }
			$items[] = array(
				'id' => $id,
				'label' => self::CANVAS === $id && ! $overridden ? 'JalinWP Canvas' : substr( wp_strip_all_tags( (string) $title ), 0, 200 ),
				'source' => $source,
				'canvas' => self::CANVAS === $id && ! $overridden,
				'overridden' => $overridden,
				'available' => true,
				'description' => $overridden ? 'A theme or user override is active. This layout is not guaranteed to be blank.' : ( self::CANVAS === $id ? 'No theme header, footer, sidebar, or automatic title. Normal WordPress and plugin styles still load.' : 'An existing template made available by the active theme or a plugin.' ),
			);
		}
		return $items;
	}

	public static function validate( string $layout, int $page_id = 0 ): string {
		foreach ( self::choices( $page_id ) as $choice ) {
			if ( $choice['id'] === $layout ) { return $layout; }
		}
		throw new FG_Failure( 'invalid_arguments', 'The selected page layout is no longer available. Use wp_page_layouts_list and select an available identifier.' );
	}

	public static function current( int $page_id ): array {
		$id = get_page_template_slug( $page_id ) ?: 'default';
		foreach ( self::choices( $page_id ) as $choice ) { if ( $choice['id'] === $id ) { return $choice; } }
		return array( 'id' => $id, 'label' => 'Unavailable Template', 'available' => false, 'canvas' => false, 'source' => 'unknown', 'description' => 'The saved template is unavailable. WordPress may use the theme default. Select an available layout.' );
	}

	/** Hash effective layout/theme identity without exposing paths or template source. */
	public static function identity( string $layout = 'default', int $page_id = 0 ): string {
		$theme = wp_get_theme();
		$state = array( 'stylesheet' => get_stylesheet(), 'template' => get_template(), 'theme_version' => $theme->get( 'Version' ), 'wordpress' => get_bloginfo( 'version' ), 'layout' => $layout, 'choices' => self::choices( $page_id ) );
		if ( wp_is_block_theme() ) {
			$slug = 'default' === $layout ? 'page' : $layout;
			$block = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
			if ( $block ) { $state['effective'] = array( $block->id, $block->source, $block->content ); }
		} elseif ( 'default' !== $layout && self::CANVAS !== $layout ) {
			// layout is never a caller-supplied filesystem path: require discovery membership first.
			$known = false;
			foreach ( self::choices( $page_id ) as $choice ) { if ( $choice['id'] === $layout ) { $known = true; break; } }
			$path = $known ? locate_template( array( $layout ), false, false ) : '';
			$state['effective'] = $path && is_file( $path ) ? hash_file( 'sha256', $path ) : 'plugin-template';
		}
		if ( self::CANVAS === $layout ) { $state['canvas_version'] = hash( 'sha256', self::block_content() . (string) file_get_contents( FG_DIR . 'templates/canvas.php' ) ); }
		return hash( 'sha256', wp_json_encode( $state ) );
	}
}
