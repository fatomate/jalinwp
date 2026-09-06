<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$result = activate_plugin( 'jalin-mcp-gateway/jalin-mcp-gateway.php' );
if ( is_wp_error( $result ) || ! is_plugin_active( 'jalin-mcp-gateway/jalin-mcp-gateway.php' ) ) {
    throw new RuntimeException( 'Fresh JalinWP activation failed.' );
}

$settings = FG_Core::fresh_settings();
if ( is_wp_error( $settings ) || ! empty( $settings['enabled'] ) || ! empty( $settings['writes'] ) || ! empty( $settings['sensitive'] ) || 'reviewed' !== $settings['write_mode'] ) {
    throw new RuntimeException( 'Fresh JalinWP settings are not Read Only.' );
}

$resource = rest_do_request( new WP_REST_Request( 'GET', '/jalin-mcp/v1/oauth/protected-resource' ) );
$metadata = $resource->get_data();
if ( 200 !== $resource->get_status() || ( $metadata['resource'] ?? null ) !== FG_OAuth::resource() || ( $metadata['authorization_servers'][0] ?? null ) !== FG_OAuth::issuer() ) {
    throw new RuntimeException( 'JalinWP OAuth metadata does not match its public identity.' );
}

$disabled = rest_do_request( new WP_REST_Request( 'POST', '/jalin-mcp/v1/mcp' ) );
if ( 403 !== $disabled->get_status() ) {
    throw new RuntimeException( 'Disabled JalinWP MCP request did not return 403.' );
}

wp_set_current_user( 1 );
$snapshot = FG_Settings::snapshot( 'connection' );
if ( is_wp_error( $snapshot ) || is_wp_error( FG_Settings::enable_for_user( 1, $snapshot['version'] ) ) ) {
    throw new RuntimeException( 'Fresh JalinWP configuration failed.' );
}
$_SERVER['HTTPS'] = 'on';
$configured = rest_do_request( new WP_REST_Request( 'POST', '/jalin-mcp/v1/mcp' ) );
if ( 401 !== $configured->get_status() ) {
    throw new RuntimeException( 'Configured JalinWP MCP request without credentials did not return 401.' );
}

$wrong = FG_Auth::permission( new WP_REST_Request( 'POST', '/wp/v2/users/me' ) );
if ( ! is_wp_error( $wrong ) || 403 !== $wrong->get_error_data()['status'] ) {
    throw new RuntimeException( 'Gateway authentication did not reject an unrelated REST route.' );
}

if ( ! str_contains( plugins_url( 'assets/admin.js', FG_FILE ), '/jalin-mcp-gateway/assets/admin.js' ) ||
    ! str_contains( FG_Page_Layouts::block_content(), 'jalin-mcp-canvas' ) ) {
    throw new RuntimeException( 'Fresh install asset or Canvas identity is incorrect.' );
}

echo json_encode( array( 'passed' => 7, 'total' => 7, 'cases' => array(
    array( 'name' => 'activation', 'pass' => true ),
    array( 'name' => 'Read Only defaults', 'pass' => true ),
    array( 'name' => 'OAuth metadata identity', 'pass' => true ),
    array( 'name' => 'disabled MCP rejection', 'pass' => true ),
    array( 'name' => 'configured MCP rejection', 'pass' => true ),
    array( 'name' => 'route-limited authentication', 'pass' => true ),
    array( 'name' => 'asset and Canvas identity', 'pass' => true ),
) ) );
