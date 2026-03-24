<?php
/**
 * Admin settings page for Sustainable Food System Events.
 * Loaded by sustainable-food-system-events.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ─── Register settings page ────────────────────────────────────────────────────

function sfse_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=sfse_event',   // parent: SFS Events menu
        'SFS Events Settings',             // page title
        'Settings',                        // menu label
        'manage_options',                  // capability
        'sfse-settings',                   // slug
        'sfse_render_settings_page'        // callback
    );
}
add_action( 'admin_menu', 'sfse_add_settings_page' );


// ─── Enqueue admin scripts & styles ───────────────────────────────────────────

function sfse_enqueue_admin_assets( $hook ) {
    // Scripts and styles for the SFSE settings page
    ?>
    <style>
        .sfse-settings-wrap h2 { margin-top: 2em; }
        .sfse-url-list { margin: 0; padding: 0; list-style: none; }
        .sfse-url-list li { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .sfse-url-list li input[type="text"] { width: 480px; }
        .sfse-url-list li .button-link-delete { color: #b32d2e; text-decoration: none; font-size: 18px; line-height: 1; }
        .sfse-url-list li .button-link-delete:hover { color: #8c1a1b; }
        .sfse-add-url-row { display: flex; gap: 8px; margin-top: 8px; align-items: center; }
        .sfse-add-url-row input[type="text"] { width: 480px; }
        .sfse-section-desc { color: #646970; margin-bottom: 12px; font-style: italic; }
        .sfse-retention-row { display: flex; align-items: center; gap: 8px; }
        .sfse-retention-row input { width: 70px; }
        .sfse-events-page-row { display: flex; align-items: center; gap: 8px; }
        .sfse-events-page-row select { min-width: 300px; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        // ── Generic: add URL row to a list ───────────────────────────────────
        function addUrlRow( listId, inputId, inputName ) {
            var list     = document.getElementById( listId );
            var addInput = document.getElementById( inputId );
            var url      = addInput.value.trim();
            if ( ! url ) return;

            var li = document.createElement('li');
            li.innerHTML =
                '<input type="text" name="' + inputName + '[]" value="' + escapeHtml(url) + '">' +
                '<a href="#" class="button-link-delete" title="Remove" aria-label="Remove URL">&times;</a>';
            list.appendChild( li );
            addInput.value = '';
            bindRemove( li.querySelector('.button-link-delete') );
        }

        // ── Generic: bind remove button ──────────────────────────────────────
        function bindRemove( btn ) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                this.closest('li').remove();
            });
        }

        // ── Bind all existing remove buttons ─────────────────────────────────
        document.querySelectorAll('.button-link-delete').forEach( bindRemove );

        // ── Bind add buttons ─────────────────────────────────────────────────
        var addSourceBtn = document.getElementById('sfse-add-source');
        if ( addSourceBtn ) {
            addSourceBtn.addEventListener('click', function(e) {
                e.preventDefault();
                addUrlRow('sfse-sources-list', 'sfse-new-source-input', 'sfse_known_sources');
            });
        }

        var addManualBtn = document.getElementById('sfse-add-manual');
        if ( addManualBtn ) {
            addManualBtn.addEventListener('click', function(e) {
                e.preventDefault();
                addUrlRow('sfse-manual-list', 'sfse-new-manual-input', 'sfse_manual_event_urls');
            });
        }

        // ── Allow Enter key in add inputs ────────────────────────────────────
        document.getElementById('sfse-new-source-input').addEventListener('keydown', function(e) {
            if ( e.key === 'Enter' ) { e.preventDefault(); document.getElementById('sfse-add-source').click(); }
        });
        document.getElementById('sfse-new-manual-input').addEventListener('keydown', function(e) {
            if ( e.key === 'Enter' ) { e.preventDefault(); document.getElementById('sfse-add-manual').click(); }
        });

        function escapeHtml(str) {
            return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    });
    </script>
    <?php
}
add_action( 'admin_head', 'sfse_enqueue_admin_assets' );


// ─── Save settings ─────────────────────────────────────────────────────────────

function sfse_save_settings() {
    if (
        ! isset( $_POST['sfse_settings_nonce'] ) ||
        ! wp_verify_nonce( $_POST['sfse_settings_nonce'], 'sfse_save_settings' ) ||
        ! current_user_can( 'manage_options' )
    ) {
        return;
    }

    // Events page
    $events_page_id = isset( $_POST['sfse_events_page_id'] ) ? intval( $_POST['sfse_events_page_id'] ) : 0;
    update_option( 'sfse_events_page_id', $events_page_id );

    // Known sources
    $sources = isset( $_POST['sfse_known_sources'] ) ? (array) $_POST['sfse_known_sources'] : array();
    $sources = array_values( array_filter( array_map( 'esc_url_raw', $sources ) ) );
    update_option( 'sfse_known_sources', $sources );

    // Manual event URLs
    $manual = isset( $_POST['sfse_manual_event_urls'] ) ? (array) $_POST['sfse_manual_event_urls'] : array();
    $manual = array_values( array_filter( array_map( 'esc_url_raw', $manual ) ) );
    update_option( 'sfse_manual_event_urls', $manual );

    // Retention days
    $days = isset( $_POST['sfse_rejection_retention_days'] ) ? intval( $_POST['sfse_rejection_retention_days'] ) : 7;
    $days = max( 0, $days ); // 0 = disabled
    update_option( 'sfse_rejection_retention_days', $days );

    // Agent run interval
    $interval = isset( $_POST['sfse_agent_run_interval_days'] ) ? intval( $_POST['sfse_agent_run_interval_days'] ) : 7;
    $interval = max( 1, $interval ); // minimum 1 day
    update_option( 'sfse_agent_run_interval_days', $interval );

    add_settings_error( 'sfse_settings', 'sfse_saved', 'Settings saved.', 'success' );
}
add_action( 'admin_init', function() {
    if ( isset( $_POST['sfse_settings_nonce'] ) ) {
        sfse_save_settings();
    }
});


// ─── Render settings page ──────────────────────────────────────────────────────

function sfse_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $sources          = get_option( 'sfse_known_sources', array() );
    $manual           = get_option( 'sfse_manual_event_urls', array() );
    $ret_days         = intval( get_option( 'sfse_rejection_retention_days', 7 ) );
    $events_page_id   = intval( get_option( 'sfse_events_page_id', 0 ) );
    $run_interval     = intval( get_option( 'sfse_agent_run_interval_days', 7 ) );
    $last_agent_run   = get_option( 'sfse_last_agent_run', '' );

    settings_errors( 'sfse_settings' );
    ?>
    <div class="wrap sfse-settings-wrap">
        <h1>SFS Events — Settings</h1>

        <form method="post" action="">
            <?php wp_nonce_field( 'sfse_save_settings', 'sfse_settings_nonce' ); ?>

            <!-- ── Events Page ──────────────────────────────────────────── -->
            <h2>Events Page</h2>
            <p class="sfse-section-desc">
                Select the page that contains the SFS Events block. This is used for
                "back to all events" links on single event pages. If you use Polylang,
                set this to the default-language page — translated versions are
                resolved automatically at runtime.
            </p>

            <div class="sfse-events-page-row">
                <select name="sfse_events_page_id" id="sfse-events-page-id">
                    <option value="0"><?php esc_html_e( '— Select a page —', 'sfse' ); ?></option>
                    <?php
                    $pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) );
                    foreach ( $pages as $page ) :
                    ?>
                        <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $events_page_id, $page->ID ); ?>>
                            <?php echo esc_html( $page->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $events_page_id ) : ?>
                    <a href="<?php echo esc_url( get_permalink( $events_page_id ) ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'View page ↗', 'sfse' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- ── Known Sources ────────────────────────────────────────── -->
            <h2>Known Sources</h2>
            <p class="sfse-section-desc">
                URLs the agent visits on every run. Add event listing pages from
                organisations you want to monitor regularly.
            </p>

            <ul class="sfse-url-list" id="sfse-sources-list">
                <?php foreach ( $sources as $url ) : ?>
                <li>
                    <input type="text" name="sfse_known_sources[]" value="<?php echo esc_attr( $url ); ?>">
                    <a href="#" class="button-link-delete" title="Remove" aria-label="Remove URL">&times;</a>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="sfse-add-url-row">
                <input type="text" id="sfse-new-source-input" class="sfse-new-url-input" placeholder="https://example.org/events/">
                <button type="button" id="sfse-add-source" class="button">Add Source</button>
            </div>

            <!-- ── Manual Event URLs ────────────────────────────────────── -->
            <h2>Manual Event URLs</h2>
            <p class="sfse-section-desc">
                Paste a direct URL to a specific event page. The agent will process
                these on its next run. URLs are removed from this list once processed.<br>
                <strong>Note:</strong> Events that fail the relevance check are saved
                as pending drafts with a rejection reason — not silently discarded.
            </p>

            <ul class="sfse-url-list" id="sfse-manual-list">
                <?php foreach ( $manual as $url ) : ?>
                <li>
                    <input type="text" name="sfse_manual_event_urls[]" value="<?php echo esc_attr( $url ); ?>">
                    <a href="#" class="button-link-delete" title="Remove" aria-label="Remove URL">&times;</a>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="sfse-add-url-row">
                <input type="text" id="sfse-new-manual-input" class="sfse-new-url-input" placeholder="https://example.org/events/my-event">
                <button type="button" id="sfse-add-manual" class="button">Add URL</button>
            </div>

            <!-- ── Rejected Events Retention ────────────────────────────── -->
            <h2>Rejected Events Retention</h2>
            <p class="sfse-section-desc">
                Rejected events are kept for this many days so the agent can learn
                from them, then permanently deleted. Set to 0 to disable auto-deletion.
            </p>

            <div class="sfse-retention-row">
                <input
                    type="number"
                    name="sfse_rejection_retention_days"
                    value="<?php echo esc_attr( $ret_days ); ?>"
                    min="0"
                    step="1"
                >
                <span>days (0 = keep forever)</span>
            </div>

            <!-- ── Agent Settings ────────────────────────────────────── -->
            <h2>Agent</h2>

            <h3 style="margin-bottom:0.25em">Last Run</h3>
            <p class="sfse-section-desc" style="margin-bottom:0.75em">
                Automatically updated by the agent at the end of each run.
            </p>
            <p>
                <?php if ( $last_agent_run ) : ?>
                    <strong><?php echo esc_html( $last_agent_run ); ?></strong>
                <?php else : ?>
                    <em style="color:#646970">No run recorded yet.</em>
                <?php endif; ?>
            </p>

            <h3 style="margin-bottom:0.25em">Run Interval</h3>
            <p class="sfse-section-desc" style="margin-bottom:0.75em">
                How many days the agent waits before re-checking a known source.
                Matches your GitHub Actions schedule — set both to the same value.
            </p>
            <div class="sfse-retention-row">
                <input
                    type="number"
                    name="sfse_agent_run_interval_days"
                    id="sfse-agent-run-interval"
                    value="<?php echo esc_attr( $run_interval ); ?>"
                    min="1"
                    step="1"
                >
                <span>days between runs</span>
            </div>

            <p style="margin-top: 2em;">
                <?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
            </p>

        </form>
    </div>
    <?php
}
