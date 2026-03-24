<?php
/**
 * Custom Post Type registration for Sustainable Food System Events.
 * Loaded by sustainable-food-system-events.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the Sustainable Food System Event custom post type.
 */
function sfse_register_post_type() {

    $labels = array(
        'name'                  => _x( 'Sustainable Food System Events', 'Post type general name', 'sfse' ),
        'singular_name'         => _x( 'Sustainable Food System Event', 'Post type singular name', 'sfse' ),
        'menu_name'             => _x( 'SFS Events', 'Admin Menu text', 'sfse' ),
        'name_admin_bar'        => _x( 'SFS Event', 'Add New on Toolbar', 'sfse' ),
        'add_new'               => __( 'Add New', 'sfse' ),
        'add_new_item'          => __( 'Add New Event', 'sfse' ),
        'new_item'              => __( 'New Event', 'sfse' ),
        'edit_item'             => __( 'Edit Event', 'sfse' ),
        'view_item'             => __( 'View Event', 'sfse' ),
        'all_items'             => __( 'All Events', 'sfse' ),
        'search_items'          => __( 'Search Events', 'sfse' ),
        'parent_item_colon'     => __( 'Parent Events:', 'sfse' ),
        'not_found'             => __( 'No events found.', 'sfse' ),
        'not_found_in_trash'    => __( 'No events found in Trash.', 'sfse' ),
        'featured_image'        => __( 'Event Banner', 'sfse' ),
        'set_featured_image'    => __( 'Set event banner', 'sfse' ),
        'remove_featured_image' => __( 'Remove event banner', 'sfse' ),
        'use_featured_image'    => __( 'Use as event banner', 'sfse' ),
        'archives'              => __( 'Event Archives', 'sfse' ),
        'insert_into_item'      => __( 'Insert into event', 'sfse' ),
        'uploaded_to_this_item' => __( 'Uploaded to this event', 'sfse' ),
        'filter_items_list'     => __( 'Filter events list', 'sfse' ),
        'items_list_navigation' => __( 'Events list navigation', 'sfse' ),
        'items_list'            => __( 'Events list', 'sfse' ),
    );

    $args = array(
        'labels'             => $labels,
        'description'        => __( 'Events related to sustainable food systems.', 'sfse' ),

        // Visibility
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => true,
        'show_in_admin_bar'  => true,

        // REST API — required for agent to POST events
        'show_in_rest'       => true,
        'rest_base'          => 'sustainable-food-events',

        // Capabilities
        'capability_type'    => 'post',
        'map_meta_cap'       => true,

        // Features
        'supports'           => array(
            'title',
            'editor',        // maps to description
            'thumbnail',     // featured image / event banner
            'revisions',
            'custom-fields', // required for REST API meta access
        ),

        // URLs
        'has_archive'        => true,
        'rewrite'            => array(
            'slug'       => 'sustainable-food-events',
            'with_front' => false,
        ),

        'hierarchical'       => false,
        'menu_position'      => 20,
        'menu_icon'          => 'dashicons-calendar-alt',

        'query_var'          => true,
    );

    register_post_type( 'sfse_event', $args );
}
add_action( 'init', 'sfse_register_post_type' );


/**
 * Register all SFSE meta fields for REST API access.
 * This allows the Python agent to read and write meta via the WP REST API.
 */
function sfse_register_meta_fields() {

    $fields = array(
        'sfse_date_start',
        'sfse_date_end',
        'sfse_organiser',
        'sfse_event_type',
        'sfse_topics',
        'sfse_event_languages',
        'sfse_language',
        'sfse_description',
        'sfse_location_name',
        'sfse_city',
        'sfse_country',
        'sfse_continent',
        'sfse_format',
        'sfse_cost',
        'sfse_registration_deadline',
        'sfse_event_link',
        'sfse_source_url',
        'sfse_image_url',
        'sfse_date_added',
        'sfse_last_updated',
        'sfse_verified',
        'sfse_rejection_reason',
        'sfse_duplicate_of',
    );

    foreach ( $fields as $field ) {

        // Determine type per field
        $type   = 'string';
        $single = true;

        if ( $field === 'sfse_verified' ) {
            $type = 'boolean';
        } elseif ( $field === 'sfse_topics' || $field === 'sfse_event_languages' ) {
            $type   = 'array';
            $single = false;
        } elseif ( $field === 'sfse_duplicate_of' ) {
            $type = 'integer';
        }

        // Build show_in_rest — array type requires item schema for REST API
        if ( $type === 'array' ) {
            $show_in_rest = array(
                'schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'string',
                    ),
                ),
            );
        } else {
            $show_in_rest = true;
        }

        register_post_meta(
            'sfse_event',
            $field,
            array(
                'type'          => $type,
                'single'        => $single,
                'show_in_rest'  => $show_in_rest,
                'auth_callback' => function() {
                    return current_user_can( 'edit_posts' );
                },
            )
        );
    }
}
add_action( 'init', 'sfse_register_meta_fields' );


/**
 * Register plugin options for REST API access.
 * Allows the Python agent to read known sources and manual event URLs.
 */
function sfse_register_options() {

    register_setting( 'sfse_settings', 'sfse_known_sources', array(
        'type'         => 'array',
        'default'      => array(),
        'show_in_rest' => array(
            'schema' => array(
                'type'  => 'array',
                'items' => array( 'type' => 'string' ),
            ),
        ),
    ));

    register_setting( 'sfse_settings', 'sfse_manual_event_urls', array(
        'type'         => 'array',
        'default'      => array(),
        'show_in_rest' => array(
            'schema' => array(
                'type'  => 'array',
                'items' => array( 'type' => 'string' ),
            ),
        ),
    ));

    register_setting( 'sfse_settings', 'sfse_rejection_retention_days', array(
        'type'         => 'integer',
        'default'      => 7,
        'show_in_rest' => true,
    ));

    // Agent run interval — how many days the agent waits between source checks.
    // Read by the agent via the REST settings endpoint.
    register_setting( 'sfse_settings', 'sfse_agent_run_interval_days', array(
        'type'         => 'integer',
        'default'      => 7,
        'show_in_rest' => true,
    ));

    // Last agent run — written by the agent after each successful run.
    // Read-only from the admin UI; never set by the settings form.
    register_setting( 'sfse_settings', 'sfse_last_agent_run', array(
        'type'         => 'string',
        'default'      => '',
        'show_in_rest' => true,
    ));

    // Source scores — JSON-encoded quality tracker, persisted here so it
    // survives GitHub Actions runner replacements between weekly runs.
    register_setting( 'sfse_settings', 'sfse_source_scores', array(
        'type'         => 'string',
        'default'      => '{}',
        'show_in_rest' => true,
    ));
}
add_action( 'init', 'sfse_register_options' );


/**
 * Daily cron: permanently delete rejected events older than the configured retention period.
 */
function sfse_cleanup_rejected_events() {
    $days = intval( get_option( 'sfse_rejection_retention_days', 7 ) );
    if ( $days <= 0 ) {
        return; // retention disabled
    }

    $posts = get_posts( array(
        'post_type'      => 'sfse_event',
        'post_status'    => 'any',
        'numberposts'    => -1,
        'date_query'     => array(
            array( 'before' => date( 'Y-m-d', strtotime( "-{$days} days" ) ) ),
        ),
        'meta_query'     => array(
            array(
                'key'     => 'sfse_rejection_reason',
                'value'   => '',
                'compare' => '!=',
            ),
        ),
    ));

    foreach ( $posts as $post ) {
        wp_delete_post( $post->ID, true ); // permanent delete, bypass trash
    }
}
add_action( 'sfse_daily_cleanup', 'sfse_cleanup_rejected_events' );


/**
 * Auto-draft a post immediately when a rejection reason is saved.
 * Works whether set by the agent via REST API or manually in WP admin.
 */
function sfse_auto_draft_on_rejection( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( $meta_key !== 'sfse_rejection_reason' ) {
        return;
    }
    if ( empty( $meta_value ) ) {
        return;
    }
    if ( get_post_type( $post_id ) !== 'sfse_event' ) {
        return;
    }
    $post = get_post( $post_id );
    if ( $post && $post->post_status === 'publish' ) {
        wp_update_post( array(
            'ID'          => $post_id,
            'post_status' => 'draft',
        ));
    }
}
add_action( 'updated_post_meta', 'sfse_auto_draft_on_rejection', 10, 4 );
add_action( 'added_post_meta',   'sfse_auto_draft_on_rejection', 10, 4 );
