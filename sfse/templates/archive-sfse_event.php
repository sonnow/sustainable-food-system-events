<?php
/**
 * Archive template for Sustainable Food System Events.
 * Compatible with Twenty Twenty-Four (FSE block theme).
 *
 * @package SFSE
 */

// Required for block theme header/footer rendering
if ( ! defined( 'ABSPATH' ) ) exit;

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php block_template_part( 'header' ); ?>

<main id="primary" class="site-main">
    <div class="wp-block-group is-layout-constrained" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">

        <h1 class="wp-block-heading" style="font-style:normal;font-weight:600">
            <?php post_type_archive_title(); ?>
        </h1>

        <?php echo do_shortcode( '[sfse_events]' ); ?>

    </div>
</main>

<?php block_template_part( 'footer' ); ?>

<?php wp_footer(); ?>
</body>
</html>
