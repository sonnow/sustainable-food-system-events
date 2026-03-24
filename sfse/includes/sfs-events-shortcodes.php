<?php
/**
 * Front-end shortcodes for Sustainable Food System Events.
 * Loaded by sustainable-food-system-events.php
 *
 * Shortcodes:
 *   [sfse_events]       — full events grid with filters
 *   [sfse_single_event] — single event detail (used on single-sfse_event block template)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ─── Shared label maps ─────────────────────────────────────────────────────────

function sfse_get_label_maps() {
    return array(
        'format' => array(
            'in-person' => 'In-Person',
            'online'    => 'Online',
            'hybrid'    => 'Hybrid',
        ),
        'topic' => array(
            'agroecology'        => 'Agroecology',
            'food_sovereignty'   => 'Food Sovereignty',
            'circular_economy'   => 'Circular Economy',
            'regenerative_agri'  => 'Regenerative Agriculture',
            'food_policy'        => 'Food Policy',
            'nutrition'          => 'Nutrition',
            'consumer_behaviour' => 'Consumer Behaviour',
            'other'              => 'Other',
        ),
        'type' => array(
            'conference'      => 'Conference',
            'festival'        => 'Festival',
            'workshop'        => 'Workshop',
            'webinar'         => 'Webinar',
            'summit'          => 'Summit',
            'community_event' => 'Community Event',
            'other'           => 'Other',
        ),
        'language' => array(
            'ar' => 'Arabic',    'da' => 'Danish',     'de' => 'German',
            'en' => 'English',   'es' => 'Spanish',    'fi' => 'Finnish',
            'fr' => 'French',    'hi' => 'Hindi',      'it' => 'Italian',
            'ja' => 'Japanese',  'ko' => 'Korean',     'nl' => 'Dutch',
            'no' => 'Norwegian', 'pl' => 'Polish',     'pt' => 'Portuguese',
            'sv' => 'Swedish',   'tr' => 'Turkish',    'zh' => 'Chinese',
            'other' => 'Other',
        ),
    );
}

function sfse_is_free( $cost ) {
    $v = strtolower( trim( $cost ?? '' ) );
    return in_array( $v, array( 'free', '0', 'gratis', 'gratuit', 'kostenlos' ), true );
}

function sfse_flatten_meta_array( $raw ) {
    $out = array();
    foreach ( (array) $raw as $item ) {
        foreach ( (array) $item as $v ) {
            if ( $v ) $out[] = $v;
        }
    }
    return array_unique( $out );
}


// ─── Events page URL helper ────────────────────────────────────────────────────

/**
 * Return the URL of the events page in the current language.
 *
 * Resolution order:
 *   1. sfse_events_page_id option → get_permalink()
 *   2. If Polylang is active, resolve the translated version of that page
 *   3. Fallback: home_url( '/' ) — never a broken link
 *
 * @return string
 */
function sfse_get_events_page_url() {
    $page_id = intval( get_option( 'sfse_events_page_id', 0 ) );

    if ( ! $page_id ) {
        return home_url( '/' );
    }

    // Polylang Pro: get translated version of the configured page
    if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' ) ) {
        $lang          = pll_current_language();
        $translated_id = $lang ? pll_get_post( $page_id, $lang ) : 0;
        if ( $translated_id ) {
            $page_id = $translated_id;
        }
    }

    $url = get_permalink( $page_id );
    return $url ? $url : home_url( '/' );
}


// ─── [sfse_events] shortcode ───────────────────────────────────────────────────

function sfse_events_shortcode() {
    $labels = sfse_get_label_maps();

    // Fetch all published events ordered by start date
    $all_events = get_posts( array(
        'post_type'   => 'sfse_event',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'meta_value',
        'meta_key'    => 'sfse_date_start',
        'order'       => 'ASC',
    ));

    // Collect unique filter values
    $f_countries = $f_formats = $f_topics = $f_continents = $f_types = $f_langs = $f_organisers = array();

    foreach ( $all_events as $ev ) {
        $c = get_post_meta( $ev->ID, 'sfse_country',         true );
        $f = get_post_meta( $ev->ID, 'sfse_format',          true );
        $o = get_post_meta( $ev->ID, 'sfse_continent',       true );
        $t = get_post_meta( $ev->ID, 'sfse_event_type',      true );
        $org = get_post_meta( $ev->ID, 'sfse_organiser',     true );
        $topics = sfse_flatten_meta_array( get_post_meta( $ev->ID, 'sfse_topics', false ) );
        $langs  = sfse_flatten_meta_array( get_post_meta( $ev->ID, 'sfse_event_languages', false ) );

        if ( $c )   $f_countries[ $c ]   = $c;
        if ( $f )   $f_formats[ $f ]     = $f;
        if ( $o )   $f_continents[ $o ]  = $o;
        if ( $t )   $f_types[ $t ]       = $t;
        if ( $org ) $f_organisers[ $org ] = $org;
        foreach ( $topics as $v ) $f_topics[ $v ] = $v;
        foreach ( $langs  as $v ) $f_langs[ $v ]  = $v;
    }

    asort( $f_countries );
    asort( $f_continents );
    asort( $f_types );
    asort( $f_topics );
    asort( $f_langs );
    asort( $f_organisers );

    ob_start();
    ?>

    <div class="sfse-archive alignfull wp-block" style="padding-left:var(--wp--style--root--padding-left, 1.5rem);padding-right:var(--wp--style--root--padding-right, 1.5rem);box-sizing:border-box;width:100%;display:block">

        <div class="sfse-archive-header">
            <p id="sfse-result-count" class="sfse-result-count"></p>
        </div>

        <!-- ── Filters ──────────────────────────────────────────────────────── -->
        <div class="sfse-filters" role="search" aria-label="Filter events">

            <div class="sfse-filters-primary">

                <!-- Country -->
                <div class="sfse-filter-group">
                    <label for="sfse-filter-country">Country</label>
                    <select id="sfse-filter-country">
                        <option value="">All countries</option>
                        <?php foreach ( $f_countries as $code ) : ?>
                            <option value="<?php echo esc_attr( strtolower( $code ) ); ?>">
                                <?php echo esc_html( $code ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date -->
                <div class="sfse-filter-group">
                    <label>Date</label>
                    <div class="sfse-date-presets">
                        <button type="button" class="sfse-preset-btn" data-preset="30d">Next 30 days</button>
                        <button type="button" class="sfse-preset-btn" data-preset="3m">Next 3 months</button>
                        <button type="button" class="sfse-preset-btn" data-preset="6m">Next 6 months</button>
                    </div>
                    <div class="sfse-date-range">
                        <input type="date" id="sfse-date-from" aria-label="From date">
                        <span>to</span>
                        <input type="date" id="sfse-date-to" aria-label="To date">
                    </div>
                </div>

                <button type="button" id="sfse-reset-filters" class="sfse-reset-btn">↺ Reset</button>

            </div>

            <!-- Advanced toggle -->
            <div class="sfse-advanced-toggle">
                <button type="button" id="sfse-advanced-btn" class="sfse-advanced-toggle-btn" aria-expanded="false" aria-controls="sfse-advanced-panel">
                    Advanced filters <span class="sfse-chevron">▾</span>
                </button>
            </div>

            <!-- Advanced panel -->
            <div id="sfse-advanced-panel" class="sfse-advanced-filters" role="group" aria-label="Advanced filters">

                <?php if ( ! empty( $f_formats ) ) : ?>
                <div class="sfse-filter-group">
                    <label for="sfse-filter-format">Format</label>
                    <select id="sfse-filter-format">
                        <option value="">All formats</option>
                        <?php foreach ( $f_formats as $val ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>">
                                <?php echo esc_html( $labels['format'][ $val ] ?? ucfirst( $val ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $f_topics ) ) : ?>
                <div class="sfse-filter-group">
                    <label for="sfse-filter-topic">Topic</label>
                    <select id="sfse-filter-topic">
                        <option value="">All topics</option>
                        <?php foreach ( $f_topics as $val ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>">
                                <?php echo esc_html( $labels['topic'][ $val ] ?? ucfirst( str_replace( '_', ' ', $val ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $f_continents ) ) : ?>
                <div class="sfse-filter-group">
                    <label for="sfse-filter-continent">Continent / Region</label>
                    <select id="sfse-filter-continent">
                        <option value="">All regions</option>
                        <?php foreach ( $f_continents as $val ) : ?>
                            <option value="<?php echo esc_attr( strtolower( $val ) ); ?>">
                                <?php echo esc_html( $val ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $f_types ) ) : ?>
                <div class="sfse-filter-group">
                    <label for="sfse-filter-type">Event Type</label>
                    <select id="sfse-filter-type">
                        <option value="">All types</option>
                        <?php foreach ( $f_types as $val ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>">
                                <?php echo esc_html( $labels['type'][ $val ] ?? ucfirst( str_replace( '_', ' ', $val ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="sfse-filter-group">
                    <label>Cost</label>
                    <div class="sfse-cost-toggle" role="group" aria-label="Filter by cost">
                        <button type="button" data-cost="all" class="active">All</button>
                        <button type="button" data-cost="free">Free</button>
                        <button type="button" data-cost="paid">Paid</button>
                    </div>
                </div>

                <?php if ( ! empty( $f_langs ) ) : ?>
                <div class="sfse-filter-group">
                    <label for="sfse-filter-language">Event Language</label>
                    <select id="sfse-filter-language">
                        <option value="">All languages</option>
                        <?php foreach ( $f_langs as $val ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>">
                                <?php echo esc_html( $labels['language'][ $val ] ?? strtoupper( $val ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="sfse-filter-group">
                    <label for="sfse-filter-organiser">Organiser</label>
                    <input type="text" id="sfse-filter-organiser" placeholder="Search organiser…" list="sfse-organiser-list" autocomplete="off">
                    <datalist id="sfse-organiser-list">
                        <?php foreach ( $f_organisers as $org ) : ?>
                            <option value="<?php echo esc_attr( $org ); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

            </div>

        </div><!-- /.sfse-filters -->

        <!-- ── Grid ─────────────────────────────────────────────────────────── -->
        <div class="sfse-grid" id="sfse-grid">

            <?php foreach ( $all_events as $post ) :
                setup_postdata( $post );

                $date_start  = get_post_meta( $post->ID, 'sfse_date_start',          true );
                $date_end    = get_post_meta( $post->ID, 'sfse_date_end',             true );
                $organiser   = get_post_meta( $post->ID, 'sfse_organiser',            true );
                $description = get_post_meta( $post->ID, 'sfse_description',          true );
                $format      = get_post_meta( $post->ID, 'sfse_format',               true );
                $event_type  = get_post_meta( $post->ID, 'sfse_event_type',           true );
                $topics_raw  = get_post_meta( $post->ID, 'sfse_topics',               false );
                $langs_raw   = get_post_meta( $post->ID, 'sfse_event_languages',      false );
                $city        = get_post_meta( $post->ID, 'sfse_city',                 true );
                $country     = get_post_meta( $post->ID, 'sfse_country',              true );
                $continent   = get_post_meta( $post->ID, 'sfse_continent',            true );
                $cost        = get_post_meta( $post->ID, 'sfse_cost',                 true );
                $event_link  = get_post_meta( $post->ID, 'sfse_event_link',           true );
                $image_url   = get_post_meta( $post->ID, 'sfse_image_url',            true );

                $topics_flat = sfse_flatten_meta_array( $topics_raw );
                $langs_flat  = sfse_flatten_meta_array( $langs_raw );

                // Date display
                $date_display = '';
                if ( $date_start ) {
                    $ts = strtotime( $date_start );
                    $date_display = date_i18n( 'j M Y', $ts );
                    if ( $date_end && substr( $date_end, 0, 10 ) !== substr( $date_start, 0, 10 ) ) {
                        $date_display .= ' – ' . date_i18n( 'j M Y', strtotime( $date_end ) );
                    }
                }

                // Location
                $loc_parts = array_filter( array( $city, $country ) );
                $location  = $format === 'online' ? 'Online' : implode( ', ', $loc_parts );

                // Cost badge
                $is_free    = sfse_is_free( $cost );
                $cost_badge = '';
                if ( $cost ) {
                    $cost_badge = $is_free
                        ? '<span class="sfse-badge sfse-badge-cost-free">Free</span>'
                        : '<span class="sfse-badge sfse-badge-cost-paid">' . esc_html( $cost ) . '</span>';
                }

                $data_topics = implode( ' ', $topics_flat );
                $data_langs  = implode( ' ', $langs_flat );
            ?>

            <article class="sfse-card"
                data-date-start="<?php echo esc_attr( $date_start ? substr( $date_start, 0, 10 ) : '' ); ?>"
                data-country="<?php echo esc_attr( strtolower( $country ?? '' ) ); ?>"
                data-format="<?php echo esc_attr( $format ?? '' ); ?>"
                data-topics="<?php echo esc_attr( $data_topics ); ?>"
                data-continent="<?php echo esc_attr( strtolower( $continent ?? '' ) ); ?>"
                data-event-type="<?php echo esc_attr( $event_type ?? '' ); ?>"
                data-cost="<?php echo esc_attr( $cost ?? '' ); ?>"
                data-event-languages="<?php echo esc_attr( $data_langs ); ?>"
                data-organiser="<?php echo esc_attr( strtolower( $organiser ?? '' ) ); ?>"
            >
                <?php if ( $image_url ) : ?>
                <div class="sfse-card-image">
                    <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                        <img src="<?php echo esc_url( $image_url ); ?>"
                             alt="<?php echo esc_attr( get_the_title( $post->ID ) ); ?>"
                             loading="lazy">
                    </a>
                </div>
                <?php elseif ( has_post_thumbnail( $post->ID ) ) : ?>
                <div class="sfse-card-image">
                    <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                        <?php echo get_the_post_thumbnail( $post->ID, 'medium', array( 'loading' => 'lazy' ) ); ?>
                    </a>
                </div>
                <?php else : ?>
                <div class="sfse-card-image-placeholder"></div>
                <?php endif; ?>

                <div class="sfse-card-header">
                    <div class="sfse-card-date"><?php echo esc_html( $date_display ); ?></div>
                    <h2 class="sfse-card-title">
                        <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post->ID ) ); ?></a>
                    </h2>
                </div>

                <div class="sfse-card-body">

                    <?php if ( $organiser ) : ?>
                    <div class="sfse-card-organiser"><?php echo esc_html( $organiser ); ?></div>
                    <?php endif; ?>

                    <?php if ( $description ) : ?>
                    <div class="sfse-card-description"><?php echo esc_html( $description ); ?></div>
                    <?php endif; ?>

                    <?php if ( ! empty( $topics_flat ) ) : ?>
                    <div class="sfse-card-topics">
                        <?php foreach ( $topics_flat as $t ) : ?>
                            <span class="sfse-topic-tag">
                                <?php echo esc_html( $labels['topic'][ $t ] ?? ucfirst( str_replace( '_', ' ', $t ) ) ); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="sfse-card-meta">
                        <?php if ( $format ) : ?>
                            <span class="sfse-badge sfse-badge-format">
                                <?php echo esc_html( $labels['format'][ $format ] ?? ucfirst( $format ) ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $location ) : ?>
                            <span class="sfse-badge sfse-badge-location">📍 <?php echo esc_html( $location ); ?></span>
                        <?php endif; ?>
                        <?php foreach ( $langs_flat as $lang ) : ?>
                            <span class="sfse-badge sfse-badge-lang"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
                        <?php endforeach; ?>
                        <?php echo $cost_badge; ?>
                    </div>

                </div>

                <div class="sfse-card-footer">
                    <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" class="sfse-card-link">View details →</a>
                    <?php if ( $event_link ) : ?>
                        <a href="<?php echo esc_url( $event_link ); ?>" class="sfse-card-link" target="_blank" rel="noopener noreferrer">Event site ↗</a>
                    <?php endif; ?>
                </div>

            </article>

            <?php endforeach; wp_reset_postdata(); ?>

        </div><!-- /.sfse-grid -->

    </div><!-- /.sfse-archive -->

    <?php
    return ob_get_clean();
}
add_shortcode( 'sfse_events', 'sfse_events_shortcode' );


// ─── [sfse_single_event] shortcode ────────────────────────────────────────────

function sfse_single_event_shortcode() {
    if ( ! is_singular( 'sfse_event' ) ) {
        return '';
    }

    $labels = sfse_get_label_maps();
    $id     = get_the_ID();

    $date_start    = get_post_meta( $id, 'sfse_date_start',           true );
    $date_end      = get_post_meta( $id, 'sfse_date_end',             true );
    $reg_deadline  = get_post_meta( $id, 'sfse_registration_deadline', true );
    $organiser     = get_post_meta( $id, 'sfse_organiser',            true );
    $description   = get_post_meta( $id, 'sfse_description',          true );
    $format        = get_post_meta( $id, 'sfse_format',               true );
    $event_type    = get_post_meta( $id, 'sfse_event_type',           true );
    $topics_raw    = get_post_meta( $id, 'sfse_topics',               false );
    $langs_raw     = get_post_meta( $id, 'sfse_event_languages',      false );
    $location_name = get_post_meta( $id, 'sfse_location_name',        true );
    $city          = get_post_meta( $id, 'sfse_city',                 true );
    $country       = get_post_meta( $id, 'sfse_country',              true );
    $continent     = get_post_meta( $id, 'sfse_continent',            true );
    $cost          = get_post_meta( $id, 'sfse_cost',                 true );
    $event_link    = get_post_meta( $id, 'sfse_event_link',           true );
    $source_url    = get_post_meta( $id, 'sfse_source_url',           true );
    $image_url     = get_post_meta( $id, 'sfse_image_url',            true );

    $topics_flat = sfse_flatten_meta_array( $topics_raw );
    $langs_flat  = sfse_flatten_meta_array( $langs_raw );
    $is_free     = sfse_is_free( $cost );

    // Date display
    $date_display = '';
    if ( $date_start ) {
        $ts   = strtotime( $date_start );
        $date_display = date_i18n( 'l j F Y', $ts );
        $time = date_i18n( 'H:i', $ts );
        if ( $time !== '00:00' ) $date_display .= ' at ' . $time;
    }

    $date_end_display = '';
    if ( $date_end && substr( $date_end, 0, 10 ) !== substr( $date_start, 0, 10 ) ) {
        $te = strtotime( $date_end );
        $date_end_display = date_i18n( 'l j F Y', $te );
        $te_time = date_i18n( 'H:i', $te );
        if ( $te_time !== '23:59' ) $date_end_display .= ' at ' . $te_time;
    }

    $deadline_display = $reg_deadline ? date_i18n( 'j F Y', strtotime( $reg_deadline ) ) : '';

    $loc_parts    = array_filter( array( $location_name, $city, $country ) );
    $location_full = implode( ', ', $loc_parts );
    if ( $format === 'online' && ! $location_full ) $location_full = 'Online';

    ob_start();
    ?>

    <div class="sfse-single">

        <a href="<?php echo esc_url( sfse_get_events_page_url() ); ?>" class="sfse-single-back">
            <?php esc_html_e( '← Back to all events', 'sfse' ); ?>
        </a>

        <?php if ( $image_url ) :
            // Extract domain for attribution caption — e.g. "worldfoodsummit.org"
            $image_source_domain = wp_parse_url( $event_link ?: $image_url, PHP_URL_HOST );
            $image_source_domain = $image_source_domain ? preg_replace( '/^www\./', '', $image_source_domain ) : '';
        ?>
        <figure class="sfse-single-image">
            <img src="<?php echo esc_url( $image_url ); ?>"
                 alt="<?php echo esc_attr( get_the_title( $id ) ); ?>"
                 loading="eager">
            <?php if ( $image_source_domain && $event_link ) : ?>
            <figcaption class="sfse-single-image-caption">
                <?php esc_html_e( 'Image:', 'sfse' ); ?>
                <a href="<?php echo esc_url( $event_link ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( $image_source_domain ); ?> ↗
                </a>
            </figcaption>
            <?php endif; ?>
        </figure>
        <?php elseif ( has_post_thumbnail( $id ) ) : ?>
        <div class="sfse-single-image">
            <?php echo get_the_post_thumbnail( $id, 'large', array( 'loading' => 'eager' ) ); ?>
        </div>
        <?php else : ?>
        <div class="sfse-single-image-placeholder"></div>
        <?php endif; ?>

        <div class="sfse-single-header">
            <h1><?php echo esc_html( get_the_title( $id ) ); ?></h1>

            <div class="sfse-single-badges">
                <?php if ( $format ) : ?><span class="sfse-badge sfse-badge-format"><?php echo esc_html( $labels['format'][ $format ] ?? ucfirst( $format ) ); ?></span><?php endif; ?>
                <?php if ( $event_type ) : ?><span class="sfse-badge sfse-badge-type"><?php echo esc_html( $labels['type'][ $event_type ] ?? ucfirst( str_replace( '_', ' ', $event_type ) ) ); ?></span><?php endif; ?>
                <?php foreach ( $langs_flat as $lang ) : ?><span class="sfse-badge sfse-badge-lang"><?php echo esc_html( $labels['language'][ $lang ] ?? strtoupper( $lang ) ); ?></span><?php endforeach; ?>
                <?php if ( $cost ) : ?><?php if ( $is_free ) : ?><span class="sfse-badge sfse-badge-cost-free">Free</span><?php else : ?><span class="sfse-badge sfse-badge-cost-paid"><?php echo esc_html( $cost ); ?></span><?php endif; ?><?php endif; ?>
            </div>

            <?php if ( $organiser ) : ?>
                <p class="sfse-single-organiser">Organised by <strong><?php echo esc_html( $organiser ); ?></strong></p>
            <?php endif; ?>
        </div>

        <div class="sfse-single-body">

            <?php if ( $description ) : ?>
            <div class="sfse-single-section">
                <h2>About this event</h2>
                <p><?php echo esc_html( $description ); ?></p>
            </div>
            <?php endif; ?>

            <div class="sfse-detail-grid">
                <?php if ( $date_display ) : ?>
                <div class="sfse-detail-item">
                    <h3>Start</h3>
                    <p><?php echo esc_html( $date_display ); ?></p>
                </div>
                <?php endif; ?>
                <?php if ( $date_end_display ) : ?>
                <div class="sfse-detail-item">
                    <h3>End</h3>
                    <p><?php echo esc_html( $date_end_display ); ?></p>
                </div>
                <?php endif; ?>
                <?php if ( $deadline_display ) : ?>
                <div class="sfse-detail-item">
                    <h3>Registration deadline</h3>
                    <p><?php echo esc_html( $deadline_display ); ?></p>
                </div>
                <?php endif; ?>
                <?php if ( $location_full ) : ?>
                <div class="sfse-detail-item">
                    <h3>Location</h3>
                    <p><?php echo esc_html( $location_full ); ?></p>
                </div>
                <?php endif; ?>
                <?php if ( $continent ) : ?>
                <div class="sfse-detail-item">
                    <h3>Region</h3>
                    <p><?php echo esc_html( $continent ); ?></p>
                </div>
                <?php endif; ?>
                <?php if ( $cost ) : ?>
                <div class="sfse-detail-item">
                    <h3>Cost</h3>
                    <p><?php echo esc_html( $is_free ? 'Free' : $cost ); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $topics_flat ) ) : ?>
            <div class="sfse-single-section">
                <h2>Topics</h2>
                <div class="sfse-single-topics">
                    <?php foreach ( $topics_flat as $t ) : ?><span class="sfse-topic-tag"><?php echo esc_html( $labels['topic'][ $t ] ?? ucfirst( str_replace( '_', ' ', $t ) ) ); ?></span><?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="sfse-single-actions">
                <?php if ( $event_link ) : ?><a href="<?php echo esc_url( $event_link ); ?>" class="sfse-btn-primary" target="_blank" rel="noopener noreferrer">Visit event site ↗</a><?php endif; ?>
                <?php if ( $source_url && $source_url !== $event_link ) : ?><a href="<?php echo esc_url( $source_url ); ?>" class="sfse-btn-secondary" target="_blank" rel="noopener noreferrer">View source ↗</a><?php endif; ?>
                <a href="<?php echo esc_url( sfse_get_events_page_url() ); ?>" class="sfse-btn-secondary"><?php esc_html_e( '← All events', 'sfse' ); ?></a>
            </div>

        </div>

    </div><!-- /.sfse-single -->

    <?php
    $output = ob_get_clean();

    // Strip <br> and <br /> tags injected by wpautop inside our HTML elements
    $output = preg_replace( '/<br\s*\/?>/', '', $output );

    // Restore add_filter calls removed earlier
    add_filter( 'the_content', 'wpautop' );
    add_filter( 'the_content', 'wptexturize' );

    return $output;
}
add_shortcode( 'sfse_single_event', 'sfse_single_event_shortcode' );
