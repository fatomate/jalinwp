<?php
/** Run only against a disposable WordPress installation (the Playground harness). */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { exit('Disposable test harness required.'); }
require_once '/wordpress/wp-load.php';
foreach ( array( 'core', 'tools', 'wp-tools' ) as $module ) {
	require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-' . $module . '.php';
}
FG_WP_Tools::register();
rest_get_server();
$fg_results = array();
$fg_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$fg_admin_id = $fg_admins[0]->ID;
wp_set_current_user( $fg_admin_id );

function fg_wp_case( string $name, callable $case ): void {
	global $fg_results;
	try { $case(); $fg_results[] = array( 'name' => $name, 'pass' => true ); }
	catch ( Throwable $error ) { $fg_results[] = array( 'name' => $name, 'pass' => false, 'error' => $error->getMessage() ); }
}
function fg_wp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function fg_wp_reject( callable $call ): void {
	try { $call(); }
	catch ( FG_Failure $error ) { return; }
	throw new RuntimeException( 'Expected FG_Failure.' );
}

$fg_source = '<!-- wp:paragraph --><p>Gateway test</p><!-- /wp:paragraph --><script>alert(1)</script><img src="x" onerror="alert(1)">';
$fg_created = FG_WP_Tools::content_create( array( 'kind' => 'posts', 'title' => 'Gateway test draft', 'content' => $fg_source ) );
$fg_post_id = $fg_created['data']['id'];

fg_wp_case( 'new content defaults to draft; administrator HTML is filtered; Gutenberg survives', static function() use ( $fg_post_id ): void {
	$post = get_post( $fg_post_id );
	fg_wp_assert( 'draft' === $post->post_status, 'New content was not a draft.' );
	fg_wp_assert( str_contains( $post->post_content, '<!-- wp:paragraph -->' ), 'Gutenberg delimiter lost.' );
	fg_wp_assert( ! str_contains( $post->post_content, '<script' ) && ! str_contains( $post->post_content, 'onerror' ), 'Unsafe HTML survived.' );
} );

fg_wp_case( 'all WordPress schemas reject undeclared fields and writes require approval', static function(): void {
	foreach ( FG_Tools::catalog() as $tool ) {
		if ( ! str_starts_with( $tool['name'], 'wp_' ) ) { continue; }
		fg_wp_assert( false === $tool['inputSchema']['additionalProperties'], 'Schema permits arbitrary fields.' );
		if ( ! $tool['annotations']['readOnlyHint'] ) { fg_wp_assert( str_contains( $tool['description'], 'wp-admin approval' ), 'Write omits approval disclosure.' ); }
	}
	fg_wp_reject( static fn() => FG_Tools::validate( 'wp_content_create', array( 'kind' => 'posts', 'title' => 'A', 'meta' => array( 'unsafe' => true ) ) ) );
	fg_wp_reject( static fn() => FG_Tools::validate( 'wp_content_list', array( 'kind' => '../../users', 'per_page' => 1000 ) ) );
} );

fg_wp_case( 'content reads exclude passwords, raw metadata and extension fields', static function() use ( $fg_post_id ): void {
	wp_update_post( array( 'ID' => $fg_post_id, 'post_password' => 'private-test-password' ) );
	register_rest_field( 'post', 'extension_secret', array( 'get_callback' => static fn() => 'must-never-leak', 'schema' => array( 'type' => 'string', 'context' => array( 'edit' ) ) ) );
	$result = FG_WP_Tools::content_get( array( 'kind' => 'posts', 'id' => $fg_post_id ) );
	fg_wp_assert( ! array_intersect_key( $result['data'], array_flip( array( 'password', 'meta', 'extension_secret' ) ) ), 'Restricted fields exposed.' );
	fg_wp_assert( ! str_contains( wp_json_encode( $result ), 'private-test-password' ), 'Password leaked.' );
} );

fg_wp_case( 'Unicode content can be read completely in bounded windows', static function() use ( $fg_post_id ): void {
	$source = str_repeat( '🙂abc', 6000 );
	wp_update_post( array( 'ID' => $fg_post_id, 'post_content' => $source ) );
	$first = FG_WP_Tools::content_get( array( 'kind' => 'posts', 'id' => $fg_post_id ) )['data'];
	$second = FG_WP_Tools::content_get( array( 'kind' => 'posts', 'id' => $fg_post_id, 'content_offset' => $first['content_window']['next_offset'] ) )['data'];
	fg_wp_assert( false === $first['content_window']['complete'] && 24000 === $first['content_window']['total_characters'], 'Invalid Unicode window metadata.' );
	fg_wp_assert( $source === $first['content']['raw'] . $second['content']['raw'], 'Source was truncated or damaged.' );
} );

fg_wp_case( 'partial updates retain content and explicit publish works', static function() use ( $fg_post_id ): void {
	$before = get_post( $fg_post_id )->post_content;
	FG_WP_Tools::content_update( array( 'kind' => 'posts', 'id' => $fg_post_id, 'title' => 'Changed title', 'status' => 'publish' ) );
	$post = get_post( $fg_post_id );
	fg_wp_assert( 'publish' === $post->post_status && $post->post_content === $before, 'Update changed omitted content or failed to publish.' );
} );

fg_wp_case( 'future scheduling rejects a past date and missing date', static function(): void {
	fg_wp_reject( static fn() => FG_WP_Tools::content_create( array( 'kind' => 'posts', 'title' => 'Past scheduled', 'status' => 'future', 'date' => '2020-01-01T10:00:00' ) ) );
	fg_wp_reject( static fn() => FG_WP_Tools::content_create( array( 'kind' => 'posts', 'title' => 'Missing date', 'status' => 'future' ) ) );
} );

fg_wp_case( 'page-only role can create a page but cannot operate on posts', static function() use ( $fg_admin_id ): void {
	add_role( 'fg_pages_test', 'Gateway Pages Test', array( 'read' => true, 'edit_pages' => true ) );
	$user = wp_insert_user( array( 'user_login' => 'fg_pages_test_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'fg_pages_test' ) );
	wp_set_current_user( $user );
	try {
		FG_Tools::validate( 'wp_content_create', array( 'kind' => 'pages', 'title' => 'Page only' ) );
		$result = FG_WP_Tools::content_create( array( 'kind' => 'pages', 'title' => 'Page only' ) );
		fg_wp_assert( 'page' === get_post_type( $result['data']['id'] ), 'Page was not created.' );
		fg_wp_reject( static fn() => FG_WP_Tools::content_list( array( 'kind' => 'posts' ) ) );
	} finally { wp_set_current_user( $fg_admin_id ); }
} );

fg_wp_case( 'native per-object permissions deny another authors content update', static function() use ( $fg_admin_id, $fg_post_id ): void {
	$user = wp_insert_user( array( 'user_login' => 'fg_author_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
	wp_set_current_user( $user );
	try { fg_wp_reject( static fn() => FG_WP_Tools::content_update( array( 'kind' => 'posts', 'id' => $fg_post_id, 'title' => 'Unauthorized edit' ) ) ); }
	finally { wp_set_current_user( $fg_admin_id ); }
} );

fg_wp_case( 'taxonomy create/update works and tags cannot have a parent', static function(): void {
	$created = FG_WP_Tools::terms_create( array( 'taxonomy' => 'categories', 'name' => 'Gateway Category ' . wp_generate_password( 6, false ) ) );
	$id = $created['data']['id'];
	FG_WP_Tools::terms_update( array( 'taxonomy' => 'categories', 'id' => $id, 'description' => 'Updated category' ) );
	fg_wp_assert( 'Updated category' === get_term( $id )->description, 'Term update failed.' );
	fg_wp_reject( static fn() => FG_WP_Tools::terms_create( array( 'taxonomy' => 'tags', 'name' => 'Bad tag', 'parent' => $id ) ) );
	$list = FG_WP_Tools::terms_list( array( 'taxonomy' => 'categories', 'per_page' => 1 ) );
	fg_wp_assert( count( $list['data'] ) <= 1 && isset( $list['pagination']['total'] ), 'Term list pagination missing.' );
} );

fg_wp_case( 'existing media metadata can be found, read and safely updated', static function(): void {
	$id = wp_insert_attachment( array( 'post_title' => 'Gateway Media Test', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'guid' => home_url( '/gateway-test-image.jpg' ) ) );
	fg_wp_assert( ! is_wp_error( $id ) && $id > 0, 'Attachment fixture failed.' );
	FG_WP_Tools::media_update( array( 'id' => $id, 'alt_text' => '<b>Helpful alt text</b>', 'caption' => '<p>Safe caption</p><script>alert(1)</script>', 'description' => 'Readable description' ) );
	$item = FG_WP_Tools::media_get( array( 'id' => $id ) )['data'];
	fg_wp_assert( 'Helpful alt text' === $item['alt_text'], 'Alternative text was not sanitized.' );
	fg_wp_assert( ! str_contains( $item['caption']['raw'], '<script' ) && 'Readable description' === $item['description']['raw'], 'Media metadata update failed: ' . wp_json_encode( array( 'caption' => $item['caption'], 'description' => $item['description'], 'stored' => get_post( $id )->post_content ) ) );
	fg_wp_assert( ! array_intersect_key( $item, array_flip( array( 'meta', 'media_details', 'password' ) ) ), 'Raw media metadata leaked.' );
	$list = FG_WP_Tools::media_list( array( 'search' => 'Gateway Media Test', 'per_page' => 1 ) );
	fg_wp_assert( 1 === count( $list['data'] ) && $id === $list['data'][0]['id'], 'Attachment search failed.' );
} );

fg_wp_case( 'comments are projected safely and moderation trashes without deletion', static function() use ( $fg_post_id ): void {
	$id = wp_insert_comment( array( 'comment_post_ID' => $fg_post_id, 'comment_content' => 'Gateway moderation', 'comment_author' => 'Test Author', 'comment_author_email' => 'private@example.test', 'comment_author_IP' => '192.0.2.1', 'comment_approved' => 0 ) );
	$list = FG_WP_Tools::comments_list( array( 'post' => $fg_post_id ) );
	fg_wp_assert( ! str_contains( wp_json_encode( $list ), 'private@example.test' ) && ! str_contains( wp_json_encode( $list ), '192.0.2.1' ), 'Comment private fields leaked.' );
	fg_wp_assert( 'Gateway moderation' === $list['data'][0]['content']['raw'], 'Comment text was not returned.' );
	FG_WP_Tools::comments_update( array( 'id' => $id, 'status' => 'approved' ) );
	fg_wp_assert( 'approved' === wp_get_comment_status( $id ), 'Comment approval failed.' );
	FG_WP_Tools::comments_update( array( 'id' => $id, 'status' => 'trash' ) );
	fg_wp_assert( null !== get_comment( $id ) && 'trash' === wp_get_comment_status( $id ), 'Comment was not safely trashed.' );
} );

fg_wp_case( 'post trash retains the database record', static function() use ( $fg_post_id ): void {
	FG_WP_Tools::content_trash( array( 'kind' => 'posts', 'id' => $fg_post_id ) );
	fg_wp_assert( null !== get_post( $fg_post_id ) && 'trash' === get_post_status( $fg_post_id ), 'Post permanently deleted or not trashed.' );
} );

fg_wp_case( 'site and block inventories return bounded pages', static function(): void {
	$runtime = FG_WP_Tools::site_inspect( array( 'section' => 'runtime' ) );
	fg_wp_assert( isset( $runtime['data']['wordpress_version'], $runtime['data']['active_theme'] ), 'Runtime fields missing.' );
	foreach ( array( 'plugins', 'themes' ) as $section ) {
		$page = FG_WP_Tools::site_inspect( array( 'section' => $section, 'per_page' => 1 ) );
		fg_wp_assert( count( $page['data'] ) <= 1 && isset( $page['pagination']['total'] ), 'Inventory pagination missing.' );
	}
	$blocks = FG_WP_Tools::block_types_list( array( 'namespace' => 'core', 'per_page' => 2 ) );
	fg_wp_assert( count( $blocks['data'] ) <= 2 && isset( $blocks['pagination']['total'] ), 'Block pagination missing.' );
} );

$fg_output = array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'passed' => count( array_filter( $fg_results, static fn( $case ) => $case['pass'] ) ), 'total' => count( $fg_results ), 'cases' => $fg_results );
file_put_contents( '/test-results/wp-tools.json', wp_json_encode( $fg_output, JSON_PRETTY_PRINT ) );
echo wp_json_encode( $fg_output, JSON_PRETTY_PRINT );
if ( $fg_output['passed'] !== $fg_output['total'] ) { exit( 1 ); }
