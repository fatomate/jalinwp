<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
$id = wp_insert_post(['post_title'=>'Runtime Test','post_status'=>'draft','post_type'=>'post'], true);
if (is_wp_error($id) || !$id) { throw new RuntimeException('Draft insertion failed'); }
if (get_post_status($id) !== 'draft') { throw new RuntimeException('Draft verification failed'); }
echo json_encode(['wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'database'=>get_class($GLOBALS['wpdb']),'draft_created'=>true,'current_user'=>get_current_user_id()]);
