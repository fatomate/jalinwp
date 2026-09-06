<?php
// Run only through the disposable fixture runner: node run-wp-tests.mjs oauth-cleanup-tests.php.
define('FG_TEST_DISPOSABLE', true);
require '/wordpress/wp-content/plugins/fames-mcp-gateway/tests/oauth-cleanup.php';
