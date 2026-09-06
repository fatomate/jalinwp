<?php
/** Real WordPress integration; run only in a disposable fixture. */
if ( ! defined( 'FG_TEST_DISPOSABLE' ) || ! FG_TEST_DISPOSABLE ) { exit( 'Disposable test harness required.' ); }
require_once '/wordpress/wp-load.php';
foreach ( array( 'core', 'tools', 'wp-tools', 'design-blocks', 'page-layouts', 'page-design' ) as $module ) { require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-' . $module . '.php'; }
if ( ! defined( 'FG_DIR' ) ) { define( 'FG_DIR', '/wordpress/wp-content/plugins/jalin-mcp-gateway/' ); }
if ( ! defined( 'FG_FILE' ) ) { define( 'FG_FILE', FG_DIR . 'jalin-mcp-gateway.php' ); }
if ( ! defined( 'FG_VERSION' ) ) { define( 'FG_VERSION', '0.3.0-test' ); }
FG_Page_Layouts::boot(); FG_Page_Layouts::register_canvas(); FG_Page_Design::boot(); FG_Page_Design::register(); FG_WP_Tools::register();
rest_get_server();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admins[0]->ID;
wp_set_current_user( $admin );
$cases = array();
function fg_design_assert( bool $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } }
function fg_design_case( string $name, callable $call ): void { global $cases; try { $call(); $cases[] = array( 'name' => $name, 'pass' => true ); } catch ( Throwable $error ) { $cases[] = array( 'name' => $name, 'pass' => false, 'error' => $error->getMessage() ); } }
function fg_design_reject( callable $call ): void { try { $call(); } catch ( FG_Failure $error ) { return; } throw new RuntimeException( 'Expected a rejected operation.' ); }
function fg_design_write( string $tool, array $args ): array { $args = FG_Page_Design::prepare( $tool, $args ); return FG_Page_Design::execute_prepared( $tool, $args, FG_Page_Design::proposal_context( $tool, $args ) ); }
$blocks = array( array( 'name' => 'core/heading', 'attributes' => array( 'content' => 'A New Landing Page', 'level' => 1 ) ), array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Editable content for staging.' ) ) );
$page_id = 0;

fg_design_case( 'layouts expose default and page-scoped plugin canvas', static function(): void {
	$choices = FG_Page_Layouts::choices();
	$ids = array_column( $choices, 'id' );
	fg_design_assert( in_array( 'default', $ids, true ) && in_array( FG_Page_Layouts::CANVAS, $ids, true ), 'Missing default or canvas layout.' );
	fg_design_assert( isset( wp_get_theme()->get_page_templates( null, 'page' )[ FG_Page_Layouts::CANVAS ] ), 'Canvas is not visible through the native template API.' );
} );
fg_design_case( 'template paths and unavailable identifiers are rejected', static function(): void {
	foreach ( array( '../../wp-config.php', '/tmp/unsafe.php', 'missing-layout' ) as $id ) { fg_design_reject( static fn() => FG_Page_Layouts::validate( $id ) ); }
} );
fg_design_case( 'validation performs no writes and returns canonical content', static function() use ( $blocks ): void {
	global $wpdb;
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
	$result = FG_Page_Design::validate( array( 'title' => 'Validated Draft', 'blocks' => $blocks, 'layout' => FG_Page_Layouts::CANVAS ) );
	fg_design_assert( $result['data']['valid'] && 'draft' === $result['data']['normalized']['status'] && str_contains( $result['data']['content'], '<!-- wp:heading' ), 'Validation result incomplete.' );
	fg_design_assert( $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'Validation wrote a post.' );
} );
fg_design_case( 'native REST creation stores editable blocks and canvas as draft', static function() use ( $blocks, &$page_id ): void {
	$result = fg_design_write( 'wp_page_design_create', array( 'title' => 'Design Integration Page', 'blocks' => $blocks, 'layout' => FG_Page_Layouts::CANVAS ) );
	$page_id = (int) $result['data']['id'];
	fg_design_assert( $page_id > 0 && 'draft' === get_post_status( $page_id ), 'Draft not created.' );
	fg_design_assert( FG_Page_Layouts::CANVAS === get_page_template_slug( $page_id ) && str_contains( get_post( $page_id )->post_content, '<!-- wp:heading' ), 'Canvas or blocks missing.' );
} );
fg_design_case( 'get returns exact source, structured outline, and fresh expected state', static function() use ( &$page_id ): void {
	$data = FG_Page_Design::get( array( 'id' => $page_id ) )['data'];
	fg_design_assert( $data['content'] === get_post( $page_id )->post_content && 64 === strlen( $data['expected_state'] ) && count( $data['blocks'] ) === 2, 'Page projection is incomplete.' );
	fg_design_assert( $data['content_window']['complete'] && ! $data['blocks_truncated'], 'False truncation.' );
} );
fg_design_case( 'layout-only update preserves unknown Gutenberg blocks byte-for-byte', static function() use ( &$page_id ): void {
	$source = '<!-- wp:vendor/custom {"mode":"preserve"} --><div>Untouched</div><!-- /wp:vendor/custom -->';
	wp_update_post( array( 'ID' => $page_id, 'post_content' => $source ) );
	$result = fg_design_write( 'wp_page_design_update', array( 'id' => $page_id, 'expected_state' => FG_Page_Design::state( $page_id ), 'layout' => 'default' ) );
	fg_design_assert( $source === get_post( $page_id )->post_content && ! get_page_template_slug( $page_id ), 'Layout-only operation changed content or failed to reset template.' );
	fg_design_assert( $result['data']['recovery_revision_id'] > 0, 'Missing paired recovery revision.' );
} );
fg_design_case( 'recovery restores both prior content and prior template', static function() use ( &$page_id, $blocks ): void {
	$before = get_post( $page_id )->post_content;
	$result = fg_design_write( 'wp_page_design_update', array( 'id' => $page_id, 'expected_state' => FG_Page_Design::state( $page_id ), 'blocks' => $blocks, 'layout' => FG_Page_Layouts::CANVAS ) );
	$revision = $result['data']['recovery_revision_id'];
	fg_design_assert( $before === get_post( $revision )->post_content && 'default' === get_post_meta( $revision, '_wp_page_template', true ), 'Recovery snapshot missing original content/layout.' );
	wp_restore_post_revision( $revision );
	clean_post_cache( $page_id );
	fg_design_assert( $before === get_post( $page_id )->post_content && ! get_page_template_slug( $page_id ), 'Native revision restore did not restore both content and layout.' );
} );
fg_design_case( 'stale proposals are rejected before saving over an editor change', static function() use ( &$page_id, $blocks ): void {
	$args = FG_Page_Design::prepare( 'wp_page_design_update', array( 'id' => $page_id, 'expected_state' => FG_Page_Design::state( $page_id ), 'blocks' => $blocks ) );
	$context = FG_Page_Design::proposal_context( 'wp_page_design_update', $args );
	wp_update_post( array( 'ID' => $page_id, 'post_content' => 'A newer manual edit.' ) );
	fg_design_reject( static fn() => FG_Page_Design::execute_prepared( 'wp_page_design_update', $args, $context ) );
	fg_design_assert( 'A newer manual edit.' === get_post( $page_id )->post_content, 'Manual editor change was overwritten.' );
} );
fg_design_case( 'a changed template identity requires new review', static function() use ( $blocks ): void {
	$args = FG_Page_Design::prepare( 'wp_page_design_create', array( 'title' => 'Identity Test', 'blocks' => $blocks ) );
	$context = FG_Page_Design::proposal_context( 'wp_page_design_create', $args );
	$filter = static fn( array $templates ): array => $templates + array( 'changed-template.php' => 'Changed Template' );
	add_filter( 'theme_page_templates', $filter );
	try { fg_design_reject( static fn() => FG_Page_Design::check_preconditions( 'wp_page_design_create', $args, $context ) ); }
	finally { remove_filter( 'theme_page_templates', $filter ); }
} );
fg_design_case( 'content changed by a site filter rolls back the page write', static function() use ( &$page_id, $blocks ): void {
	$before = get_post( $page_id )->post_content;
	$args = FG_Page_Design::prepare( 'wp_page_design_update', array( 'id' => $page_id, 'expected_state' => FG_Page_Design::state( $page_id ), 'blocks' => $blocks ) );
	$context = FG_Page_Design::proposal_context( 'wp_page_design_update', $args );
	$filter = static function( array $data ): array { if ( 'page' === $data['post_type'] ) { $data['post_content'] .= ' Changed by a plugin.'; } return $data; };
	add_filter( 'wp_insert_post_data', $filter );
	try { fg_design_reject( static fn() => FG_Page_Design::execute_prepared( 'wp_page_design_update', $args, $context ) ); }
	finally { remove_filter( 'wp_insert_post_data', $filter ); }
	clean_post_cache( $page_id );
	fg_design_assert( get_post( $page_id )->post_content === $before, 'Failed write was not rolled back.' );
} );
fg_design_case( 'disabled or insufficient revisions reject existing page updates', static function() use ( &$page_id ): void {
	foreach ( array( 0, 1 ) as $count ) {
		$filter = static fn(): int => $count;
		add_filter( 'wp_revisions_to_keep', $filter );
		try { fg_design_reject( static fn() => FG_Page_Design::prepare( 'wp_page_design_update', array( 'id' => $page_id, 'expected_state' => FG_Page_Design::state( $page_id ), 'layout' => 'default' ) ) ); }
		finally { remove_filter( 'wp_revisions_to_keep', $filter ); }
	}
} );
fg_design_case( 'missing saved template can be inspected and replaced', static function() use ( &$page_id ): void {
	update_post_meta( $page_id, '_wp_page_template', 'previous-theme-missing.php' );
	$data = FG_Page_Design::get( array( 'id' => $page_id ) )['data'];
	fg_design_assert( false === $data['layout']['available'], 'Missing template was advertised as available.' );
	fg_design_write( 'wp_page_design_update', array( 'id' => $page_id, 'expected_state' => $data['expected_state'], 'layout' => 'default' ) );
	fg_design_assert( ! get_page_template_slug( $page_id ), 'Unavailable template could not be reset.' );
} );
fg_design_case( 'publish is explicit and native page permissions are retained', static function() use ( $admin, $blocks ): void {
	add_role( 'fg_design_limited', 'Limited Page Author', array( 'read' => true, 'edit_pages' => true ) );
	$user = wp_insert_user( array( 'user_login' => 'fg_design_limited_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'fg_design_limited' ) );
	wp_set_current_user( $user );
	try { fg_design_reject( static fn() => FG_Page_Design::prepare( 'wp_page_design_create', array( 'title' => 'Denied Publish', 'blocks' => $blocks, 'status' => 'publish' ) ) ); }
	finally { wp_set_current_user( $admin ); }
	$result = fg_design_write( 'wp_page_design_create', array( 'title' => 'Explicit Publish Fixture', 'blocks' => $blocks, 'status' => 'publish' ) );
	fg_design_assert( 'publish' === get_post_status( $result['data']['id'] ), 'Explicit authorized publish failed.' );
} );
fg_design_case( 'normal approved execution cannot omit frozen design context', static function() use ( $blocks ): void { fg_design_reject( static fn() => FG_Page_Design::unprepared_write( array( 'title' => 'No Context', 'blocks' => $blocks ) ) ); } );
fg_design_case( 'unknown blocks and unapproved fields are rejected for creation', static function(): void {
	fg_design_reject( static fn() => FG_Page_Design::validate( array( 'title' => 'Unknown', 'blocks' => array( array( 'name' => 'vendor/custom', 'attributes' => array() ) ) ) ) );
	fg_design_reject( static fn() => FG_Page_Design::validate( array( 'title' => 'Unsafe', 'blocks' => array( array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => '<script>bad</script>' ) ) ) ) ) );
	fg_design_reject( static fn() => FG_Page_Design::validate( array( 'title' => 'Unsafe Meta', 'blocks' => array(), 'meta' => array( 'secret' => 'denied' ) ) ) );
} );
fg_design_case( 'block discovery distinguishes supported adapters from other installed blocks', static function(): void {
	$result = FG_WP_Tools::block_types_list( array( 'namespace' => 'core', 'search' => 'paragraph', 'per_page' => 50 ) );
	$found = false;
	foreach ( $result['data'] as $block ) { if ( 'core/paragraph' === $block['name'] ) { $found = ! empty( $block['creation_supported'] ) && isset( $block['design_attributes'] ); } }
	fg_design_assert( $found, 'Supported block attribute discovery missing.' );
} );
fg_design_case( 'complete landing page with every supported block survives native REST filtering', static function(): void {
	$media = wp_insert_attachment( array( 'post_title' => 'Landing Page Fixture', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ), false );
	update_post_meta( $media, '_wp_attached_file', '2026/09/landing-page-fixture.png' );
	$n = static fn( string $name, array $attributes = array(), array $children = array() ): array => array( 'name' => $name, 'attributes' => $attributes, 'innerBlocks' => $children );
	$p = $n( 'core/paragraph', array( 'content' => 'Editable copy & details.' ) );
	$style = array( 'color' => array( 'text' => '#102030', 'background' => '#eeeeee' ), 'spacing' => array( 'padding' => array( 'top' => '40px', 'bottom' => '40px', 'left' => '2rem', 'right' => '2rem' ) ), 'typography' => array( 'fontSize' => '22px', 'fontWeight' => '700', 'lineHeight' => '1.5' ) );
	$blocks = array(
		$n( 'core/cover', array( 'id' => $media, 'dimRatio' => 60, 'customOverlayColor' => '#102030', 'minHeight' => 400, 'align' => 'full' ), array( $n( 'core/heading', array( 'content' => 'Full Landing Page', 'level' => 1, 'style' => array( 'color' => array( 'text' => '#ffffff' ) ) ) ), $p ) ),
		$n( 'core/group', array( 'tagName' => 'section', 'layout' => array( 'type' => 'constrained', 'contentSize' => '1000px' ), 'style' => $style ), array(
			$n( 'core/columns', array(), array( $n( 'core/column', array( 'width' => '50%' ), array( $n( 'core/heading', array( 'content' => 'Benefits' ) ), $n( 'core/list', array(), array( $n( 'core/list-item', array( 'content' => 'First benefit' ) ), $n( 'core/list-item', array( 'content' => 'Second benefit' ) ) ) ) ) ), $n( 'core/column', array( 'width' => '50%' ), array( $n( 'core/image', array( 'id' => $media, 'alt' => 'Service preview', 'caption' => 'An editable image.' ) ) ) ) ) ),
			$n( 'core/separator', array( 'style' => array( 'color' => array( 'background' => '#123456' ) ) ) ),
			$n( 'core/group', array( 'layout' => array( 'type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => 'center' ) ), array( $n( 'core/heading', array( 'content' => 'RM97', 'textAlign' => 'center' ) ), $n( 'core/buttons', array( 'layout' => array( 'type' => 'flex', 'justifyContent' => 'center' ) ), array( $n( 'core/button', array( 'text' => 'Get Started', 'url' => 'https://example.com/?source=mcp&offer=97', 'linkTarget' => '_blank', 'style' => $style ) ) ) ) ) ),
			$n( 'core/spacer', array( 'height' => '20px' ) ),
		) ),
	);
	$args = FG_Page_Design::prepare( 'wp_page_design_create', array( 'title' => 'Complete Landing Page Fixture', 'layout' => FG_Page_Layouts::CANVAS, 'blocks' => $blocks ) );
	$context = FG_Page_Design::proposal_context( 'wp_page_design_create', $args );
	$result = FG_Page_Design::execute_prepared( 'wp_page_design_create', $args, $context );
	fg_design_assert( $context['content'] === get_post( $result['data']['id'] )->post_content, 'Native REST filtering altered the complete design.' );
} );
fg_design_case( 'classic canvas renders page content and required hooks without theme wrappers', static function(): void {
	$old_theme = get_stylesheet();
	$directory = get_theme_root() . '/fg-design-classic';
	wp_mkdir_p( $directory . '/templates' );
	file_put_contents( $directory . '/style.css', "/*\nTheme Name: JalinWP Disposable Classic Fixture\nVersion: 1.0\n*/\n" );
	file_put_contents( $directory . '/index.php', '<?php echo "THEME_HEADER_WRAPPER"; the_content(); echo "THEME_FOOTER_WRAPPER";' );
	file_put_contents( $directory . '/templates/wide.php', "<?php /* Template Name: Existing Wide Template */ echo 'EXISTING_TEMPLATE';" );
	$old_query = $GLOBALS['wp_query'];
	$head = static function(): void { echo '<meta name="jalin-hook-head" content="yes">'; };
	$body = static function(): void { echo '<span id="jalin-hook-body"></span>'; };
	$footer = static function(): void { echo '<span id="jalin-hook-footer"></span>'; };
	try {
		switch_theme( 'fg-design-classic' );
		$choices = FG_Page_Layouts::choices();
		fg_design_assert( in_array( 'templates/wide.php', array_column( $choices, 'id' ), true ), 'Existing theme template was not discovered.' );
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Automatic Title Must Be Absent', 'post_content' => '<p>Canvas Content Marker</p>' ) );
		update_post_meta( $id, '_wp_page_template', FG_Page_Layouts::CANVAS );
		$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id, 'post_status' => 'publish' ) );
		$path = FG_Page_Layouts::template_include( $directory . '/index.php' );
		fg_design_assert( $path === FG_DIR . 'templates/canvas.php', 'Classic canvas did not select the plugin-owned template.' );
		add_action( 'wp_head', $head ); add_action( 'wp_body_open', $body ); add_action( 'wp_footer', $footer );
		ob_start(); include $path; $rendered = ob_get_clean();
		foreach ( array( 'Canvas Content Marker', 'jalin-hook-head', 'jalin-hook-body', 'jalin-hook-footer', '<main id="jalin-mcp-canvas-main"' ) as $marker ) { fg_design_assert( str_contains( $rendered, $marker ), 'Missing canvas content/hook: ' . $marker ); }
		fg_design_assert( ! str_contains( $rendered, 'THEME_HEADER_WRAPPER' ) && ! str_contains( $rendered, 'THEME_FOOTER_WRAPPER' ) && ! str_contains( $rendered, '<h1>Automatic Title Must Be Absent</h1>' ), 'Theme wrappers leaked into canvas.' );
		update_post_meta( $id, '_wp_page_template', 'default' );
		fg_design_assert( $directory . '/index.php' === FG_Page_Layouts::template_include( $directory . '/index.php' ), 'Normal theme-default pages were changed.' );
	} finally {
		remove_action( 'wp_head', $head ); remove_action( 'wp_body_open', $body ); remove_action( 'wp_footer', $footer );
		$GLOBALS['wp_query'] = $old_query;
		switch_theme( $old_theme );
	}
} );
global $wpdb;
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'database' => get_class( $wpdb ), 'passed' => count( array_filter( $cases, static fn( array $case ): bool => $case['pass'] ) ), 'total' => count( $cases ), 'cases' => $cases ), JSON_PRETTY_PRINT );
