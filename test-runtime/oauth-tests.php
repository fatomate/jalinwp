<?php
// Run only through the disposable fixture runner: node run-wp-tests.mjs oauth-tests.php.
define('FG_TEST_DISPOSABLE', true);
require '/wordpress/wp-content/plugins/fames-mcp-gateway/tests/oauth.php';
