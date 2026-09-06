<?php
/** Fames Canvas for classic themes and WordPress 6.6 block-theme fallback. */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'fames-canvas-page' ); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#fames-canvas-main"><?php esc_html_e( 'Skip to Content', 'fames-mcp-gateway' ); ?></a>
<main id="fames-canvas-main" class="fames-canvas-main">
<?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
