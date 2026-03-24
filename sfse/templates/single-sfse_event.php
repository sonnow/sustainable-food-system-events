<?php
/**
 * Single event template for Sustainable Food System Events.
 * Compatible with Twenty Twenty-Four (FSE block theme).
 *
 * @package SFSE
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<main id="primary" class="site-main">
    <div class="wp-block-group is-layout-constrained" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">

        <?php
        while ( have_posts() ) :
            the_post();
            echo do_shortcode( '[sfse_single_event]' );
        endwhile;
        ?>

    </div>
</main>

<?php
get_footer();
