<?php
if ( ! defined( 'FG_TEST_DISPOSABLE' ) || ! FG_TEST_DISPOSABLE ) { exit( 'Disposable test harness required.' ); }
require_once '/wordpress/wp-load.php';
require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/jalin-mcp-gateway.php';
if ( ! defined( 'REST_REQUEST' ) ) { define( 'REST_REQUEST', true ); }
$_SERVER['HTTPS'] = 'on';
FG_Core::activate();
FG_Page_Layouts::register_canvas();
rest_get_server();
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$settings = array_replace( FG_Core::settings(), array( 'enabled' => true, 'oauth_enabled' => true, 'writes' => true, 'users' => array( $admin->ID ), '_policy_revision' => wp_generate_uuid4() ) );
update_option( 'fg_settings', $settings );
$credential = WP_Application_Passwords::create_new_application_password( $admin->ID, array( 'name' => 'Disposable Design Approval Test' ) );
if ( is_wp_error( $credential ) ) { throw new RuntimeException( 'Test authentication setup failed.' ); }
$cases = array(); $last_page = 0;
function fg_da_auth(): void {
	global $admin, $credential;
	wp_set_current_user( 0 );
	$user = wp_authenticate_application_password( null, $admin->user_login, $credential[0] );
	if ( ! $user instanceof WP_User ) { throw new RuntimeException( 'Application Password authentication failed.' ); }
	wp_set_current_user( $user->ID );
	$request = new WP_REST_Request( 'POST', '/jalin-mcp/v1/mcp' );
	if ( true !== FG_Auth::permission( $request ) ) { throw new RuntimeException( 'Gateway permission check failed.' ); }
}
function fg_da_assert( bool $result, string $message ): void { if ( ! $result ) { throw new RuntimeException( $message ); } }
function fg_da_reject( callable $call, string $reason = '' ): void {
	try { $call(); }
	catch ( FG_Failure $error ) { if ( $reason !== '' && $reason !== $error->reason ) { throw new RuntimeException( 'Expected ' . $reason . ', received ' . $error->reason . ': ' . $error->getMessage() ); } return; }
	throw new RuntimeException( 'Expected rejection: ' . $reason );
}
function fg_da_case( string $name, callable $call ): void {
	global $cases;
	try { fg_da_auth(); $call(); $cases[] = array( 'name' => $name, 'pass' => true ); }
	catch ( Throwable $error ) { $cases[] = array( 'name' => $name, 'pass' => false, 'error' => $error->getMessage() ); }
}
function fg_da_propose( string $tool = 'wp_page_design_create', array $override = array() ): array {
	$args = array( 'title' => 'Reviewed Design ' . wp_generate_password( 5, false ), 'layout' => FG_Page_Layouts::CANVAS, 'blocks' => array( array( 'name' => 'core/heading', 'attributes' => array( 'content' => 'Exact Reviewed Heading', 'level' => 1 ) ), array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Exact reviewed paragraph.' ) ) ) );
	if ( 'wp_page_design_update' === $tool ) { $args = array(); }
	return FG_Tools::call( $tool, array_replace( $args, $override ) );
}
function fg_da_approve( string $id ): array {
	$row = FG_Approvals::get_owned( $id );
	$context = FG_Approvals::context( $row );
	FG_Approvals::review( $id, 'approved', $context['design']['content_sha256'] ?? '' );
	return $context;
}

fg_da_case( 'proposing a typed page through FG_Tools saves only the review request', static function(): void {
	global $wpdb;
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" );
	$proposal = fg_da_propose();
	$row = FG_Approvals::get_owned( $proposal['change_id'] );
	$context = FG_Approvals::context( $row );
	fg_da_assert( 'pending' === $row['status'] && is_string( $context['design']['content'] ), 'Proposal or frozen content missing.' );
	fg_da_assert( hash( 'sha256', $context['design']['content'] ) === $context['design']['content_sha256'], 'Frozen content checksum does not match.' );
	fg_da_assert( $count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), 'Proposal changed a page before review.' );
} );
fg_da_case( 'approval requires the exact installed-editor validation digest', static function(): void {
	$id = fg_da_propose()['change_id'];
	fg_da_reject( static fn() => FG_Approvals::review( $id, 'approved' ), 'design_validation_required' );
	fg_da_reject( static fn() => FG_Approvals::review( $id, 'approved', str_repeat( 'a', 64 ) ), 'design_validation_required' );
	fg_da_assert( 'pending' === FG_Approvals::status( $id )['status'], 'Failed validation made the request executable.' );
	fg_da_approve( $id );
	fg_da_assert( 'approved' === FG_Approvals::status( $id )['status'], 'Validated request could not be approved.' );
} );
fg_da_case( 'approved proposal applies exactly once using the frozen markup', static function() use ( &$last_page ): void {
	global $wpdb;
	$id = fg_da_propose()['change_id'];
	$context = fg_da_approve( $id );
	$result = FG_Approvals::apply( $id );
	$last_page = (int) $result['result']['data']['id'];
	fg_da_assert( 'applied' === $result['status'] && get_post( $last_page )->post_content === $context['design']['content'], 'Saved content did not match the approved payload.' );
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" );
	fg_da_reject( static fn() => FG_Approvals::apply( $id ), 'not_approved' );
	fg_da_assert( $count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), 'Duplicate apply created another page.' );
} );
fg_da_case( 'tampered frozen markup is rejected at review and apply', static function(): void {
	global $wpdb;
	$id = fg_da_propose()['change_id'];
	$row = FG_Approvals::get_owned( $id );
	$context = FG_Approvals::context( $row );
	$context['design']['content'] .= '<p>Unreviewed</p>';
	$wpdb->update( FG_Core::table( 'changes' ), array( 'server_context' => wp_json_encode( $context ) ), array( 'id' => $id ) );
	fg_da_reject( static fn() => FG_Approvals::review( $id, 'approved', $context['design']['content_sha256'] ), 'invalid_change' );
	$wpdb->update( FG_Core::table( 'changes' ), array( 'status' => 'approved' ), array( 'id' => $id ) );
	fg_da_reject( static fn() => FG_Approvals::apply( $id ), 'invalid_change' );
} );
fg_da_case( 'manual page edits invalidate pending and approved design proposals', static function() use ( &$last_page ): void {
	$args = array( 'id' => $last_page, 'expected_state' => FG_Page_Design::state( $last_page ), 'title' => 'Old Proposal' );
	$pending = fg_da_propose( 'wp_page_design_update', $args )['change_id'];
	$approved = fg_da_propose( 'wp_page_design_update', $args )['change_id'];
	fg_da_approve( $approved );
	wp_update_post( array( 'ID' => $last_page, 'post_title' => 'New Manual Edit' ) );
	fg_da_reject( static fn() => FG_Approvals::review( $pending, 'approved' ), 'page_changed' );
	fg_da_reject( static fn() => FG_Approvals::apply( $approved ), 'page_changed' );
	fg_da_assert( 'New Manual Edit' === get_post( $last_page )->post_title, 'Stale approval overwrote a manual change.' );
} );
fg_da_case( 'layout-only approval needs no content validation token and preserves custom blocks', static function() use ( &$last_page ): void {
	$source = '<!-- wp:vendor/keep --><div>Keep This Custom Block</div><!-- /wp:vendor/keep -->';
	wp_update_post( array( 'ID' => $last_page, 'post_content' => $source ) );
	$id = fg_da_propose( 'wp_page_design_update', array( 'id' => $last_page, 'expected_state' => FG_Page_Design::state( $last_page ), 'layout' => 'default' ) )['change_id'];
	FG_Approvals::review( $id, 'approved' );
	$result = FG_Approvals::apply( $id );
	fg_da_assert( 'applied' === $result['status'] && $source === get_post( $last_page )->post_content, 'Layout-only change damaged custom blocks.' );
} );
fg_da_case( 'settings policy changes invalidate an approved design', static function(): void {
	$id = fg_da_propose()['change_id'];
	fg_da_approve( $id );
	$result = FG_Settings::save_section( 'connection', array( 'origins' => array( 'https://new-origin.example' ) ), FG_Settings::fingerprint( 'connection' ) );
	fg_da_assert( ! is_wp_error( $result ), 'Test settings update failed.' );
	fg_da_reject( static fn() => FG_Approvals::apply( $id ) );
	fg_da_assert( 'applied' !== FG_Approvals::status( $id )['status'], 'Policy-invalidated design was applied.' );
} );
fg_da_case( 'expired approvals cannot apply', static function(): void {
	global $wpdb;
	$id = fg_da_propose()['change_id'];
	fg_da_approve( $id );
	$wpdb->update( FG_Core::table( 'changes' ), array( 'expires' => time() - 1 ), array( 'id' => $id ) );
	fg_da_reject( static fn() => FG_Approvals::apply( $id ), 'expired' );
} );
fg_da_case( 'a failed exact-content write is non-retriable and preserves the previous page', static function() use ( &$last_page ): void {
	$before = get_post( $last_page )->post_content;
	$id = fg_da_propose( 'wp_page_design_update', array( 'id' => $last_page, 'expected_state' => FG_Page_Design::state( $last_page ), 'blocks' => array( array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Reviewed Replacement' ) ) ) ) )['change_id'];
	fg_da_approve( $id );
	$filter = static function( array $data ): array { if ( 'page' === $data['post_type'] ) { $data['post_content'] .= ' Unapproved Plugin Change'; } return $data; };
	add_filter( 'wp_insert_post_data', $filter );
	try { fg_da_reject( static fn() => FG_Approvals::apply( $id ), 'execution_failed' ); }
	finally { remove_filter( 'wp_insert_post_data', $filter ); }
	fg_da_assert( 'failed' === FG_Approvals::status( $id )['status'] && $before === get_post( $last_page )->post_content, 'Failure was not terminal or rollback did not preserve content.' );
	fg_da_reject( static fn() => FG_Approvals::apply( $id ), 'not_approved' );
} );
fg_da_case( 'review HTML escapes data and keeps proposed markup inside an isolated preview', static function(): void {
	$id = fg_da_propose()['change_id'];
	$row = FG_Approvals::get_owned( $id );
	ob_start(); FG_Design_Review::render( $row ); $html = ob_get_clean();
	fg_da_assert( str_contains( $html, 'sandbox=""' ) && str_contains( $html, 'data-fg-design-source' ) && str_contains( $html, 'data-content-digest' ), 'Review is missing preview isolation or exact-content validation fields.' );
	fg_da_assert( ! str_contains( $html, '<h1 class="wp-block-heading">Exact Reviewed Heading</h1>' ), 'Raw proposal markup leaked into admin document.' );
} );
global $wpdb;
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'database' => get_class( $wpdb ), 'authentication' => 'Real WordPress Application Password; direct gateway tool and review methods', 'browser_validation' => 'Separate installed-JavaScript fixture and browser gate; digest supplied by integration test', 'passed' => count( array_filter( $cases, static fn( array $case ): bool => $case['pass'] ) ), 'total' => count( $cases ), 'cases' => $cases ), JSON_PRETTY_PRINT );
