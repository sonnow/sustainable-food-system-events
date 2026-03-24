<?php
/**
 * Plugin Name:       Sustainable Food System Events
 * Plugin URI:        https://github.com/sonnow/sustainable-food-system-events
 * Description:       Discovers, publishes and manages sustainable food system events via a weekly AI agent.
 * Version:           1.2.1
 * Author:            Onno Westra
 * Author URI:        https://github.com/sonnow
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sfse
 * Requires at least: 6.0
 * Tested up to:      6.7
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Version constant ──────────────────────────────────────────────────────────
define( 'SFSE_VERSION', '1.2.1' );
define( 'SFSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );


// ─── Includes ──────────────────────────────────────────────────────────────────
require_once SFSE_PLUGIN_DIR . 'includes/sfs-events-cpt.php';
require_once SFSE_PLUGIN_DIR . 'includes/sfs-events-acf.php';
require_once SFSE_PLUGIN_DIR . 'includes/sfs-events-settings.php';
require_once SFSE_PLUGIN_DIR . 'includes/sfs-events-shortcodes.php';


// ─── Block registration ────────────────────────────────────────────────────────
function sfse_register_block() {
    $build_file = SFSE_PLUGIN_DIR . 'build/index.js';
    if ( ! file_exists( $build_file ) ) {
        return;
    }
    register_block_type(
        SFSE_PLUGIN_DIR . 'build',
        array(
            'render_callback' => 'sfse_render_events_block',
        )
    );
}
add_action( 'init', 'sfse_register_block' );

function sfse_render_events_block( $attributes ) {
    wp_enqueue_style(
        'sfse-frontend',
        SFSE_PLUGIN_URL . 'assets/sfse-frontend.css',
        array(),
        SFSE_VERSION
    );
    wp_enqueue_script(
        'sfse-frontend',
        SFSE_PLUGIN_URL . 'assets/sfse-frontend.js',
        array(),
        SFSE_VERSION,
        true
    );
    return sfse_events_shortcode();
}


// ─── Shortcode fallback assets ─────────────────────────────────────────────────
function sfse_enqueue_frontend_assets() {
    if ( ! is_singular( 'sfse_event' ) && ! is_post_type_archive( 'sfse_event' ) ) {
        return;
    }
    wp_enqueue_style(
        'sfse-frontend',
        SFSE_PLUGIN_URL . 'assets/sfse-frontend.css',
        array(),
        SFSE_VERSION
    );
    wp_enqueue_script(
        'sfse-frontend',
        SFSE_PLUGIN_URL . 'assets/sfse-frontend.js',
        array(),
        SFSE_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'sfse_enqueue_frontend_assets' );


// ─── Polylang integration ──────────────────────────────────────────────────────
function sfse_polylang_setup() {
    if ( ! function_exists( 'pll_register_string' ) ) {
        return;
    }
    add_filter( 'pll_get_post_types', function( $post_types ) {
        $post_types['sfse_event'] = 'sfse_event';
        return $post_types;
    });
    add_filter( 'pll_translation_url', function( $url, $lang ) {
        if ( is_post_type_archive( 'sfse_event' ) ) {
            return get_post_type_archive_link( 'sfse_event' );
        }
        return $url;
    }, 10, 2 );
}
add_action( 'init', 'sfse_polylang_setup', 20 );


// ─── LiteSpeed Cache integration ──────────────────────────────────────────────
// Exclude our CSS and JS from LiteSpeed's minify/combine to ensure version
// busting works correctly across all cache configurations.
add_filter( 'litespeed_optimize_css_excludes', function( $excludes ) {
    $excludes[] = 'sfse-frontend.css';
    return $excludes;
});
add_filter( 'litespeed_optimize_js_excludes', function( $excludes ) {
    $excludes[] = 'sfse-frontend.js';
    return $excludes;
});


// ─── Single event block template ──────────────────────────────────────────────
// Registers a block template for sfse_event single posts via the
// get_block_templates filter. No database writes — WordPress resolves it
// at runtime, exactly like a theme's templates/single-sfse_event.html.

function sfse_register_block_template( $query_result, $query, $template_type ) {
    if ( $template_type !== 'wp_template' ) {
        return $query_result;
    }

    $slug = 'single-sfse_event';

    // Only inject if no existing template with this slug is already present
    foreach ( $query_result as $template ) {
        if ( $template->slug === $slug ) {
            return $query_result;
        }
    }

    // If a specific slug is requested and it isn't ours, skip
    if ( ! empty( $query['slug__in'] ) && ! in_array( $slug, $query['slug__in'], true ) ) {
        return $query_result;
    }

    $template                 = new WP_Block_Template();
    $template->type           = 'wp_template';
    $template->theme          = get_stylesheet();
    $template->slug           = $slug;
    $template->id             = get_stylesheet() . '//' . $slug;
    $template->title          = __( 'Single SFS Event', 'sfse' );
    $template->description    = __( 'Template for individual Sustainable Food System Event pages.', 'sfse' );
    $template->status         = 'publish';
    $template->source         = 'plugin';
    $template->origin         = 'plugin';
    $template->is_custom      = false;
    $template->post_types     = array( 'sfse_event' );
    $template->area           = 'uncategorized';
    $template->content        = '<!-- wp:template-part {"slug":"header","tagName":"header","area":"header"} /-->

<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group">
<!-- wp:shortcode -->
[sfse_single_event]
<!-- /wp:shortcode -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer","area":"footer"} /-->';

    $query_result[] = $template;
    return $query_result;
}
add_filter( 'get_block_templates', 'sfse_register_block_template', 10, 3 );


// ─── Activation ────────────────────────────────────────────────────────────────
function sfse_main_activate() {
    sfse_register_post_type();
    sfse_register_options();
    flush_rewrite_rules();
    sfse_cleanup_db_templates();

    if ( ! get_option( 'sfse_known_sources' ) ) {
        update_option( 'sfse_known_sources', array(
            'https://www.slowfood.com/events/',
            'https://www.sustainablefoodtrust.org/events/',
            'https://www.eatforum.org/events/',
            'https://www.foodsystemsummit.org/events',
            'https://www.rhs.org.uk/shows-events',
        ));
    }

    if ( ! wp_next_scheduled( 'sfse_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'sfse_daily_cleanup' );
    }
}
register_activation_hook( __FILE__, 'sfse_main_activate' );


// ─── Deactivation ──────────────────────────────────────────────────────────────
function sfse_main_deactivate() {
    flush_rewrite_rules();
    wp_clear_scheduled_hook( 'sfse_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'sfse_main_deactivate' );


// ─── Cleanup & uninstall ───────────────────────────────────────────────────────
function sfse_cleanup_db_templates() {
    $templates = get_posts( array(
        'post_type'   => 'wp_template',
        'post_status' => 'any',
        'numberposts' => -1,
        'meta_query'  => array(
            array( 'key' => '_sfse_template', 'compare' => 'EXISTS' ),
        ),
    ));
    foreach ( $templates as $template ) {
        wp_delete_post( $template->ID, true );
    }
}

register_uninstall_hook( __FILE__, 'sfse_uninstall' );

function sfse_uninstall() {
    sfse_cleanup_db_templates();
    delete_option( 'sfse_known_sources' );
    delete_option( 'sfse_manual_event_urls' );
    delete_option( 'sfse_rejection_retention_days' );
    delete_option( 'sfse_events_page_id' );
}
