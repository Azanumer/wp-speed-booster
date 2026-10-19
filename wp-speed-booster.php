<?php
/**
 * Plugin Name: WP Speed Booster
 * Description: Lightweight frontend speed tweaks — defer non-essential scripts, async-load CSS, lazy-load iframes, strip ?ver= cache-busters, preconnect hints. No page-builder lock-in, no bloat.
 * Version:     1.0.0
 * Author:      Azan Umer
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-speed-booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WSB_VERSION', '1.0.0' );
define( 'WSB_OPTION', 'wsb_settings' );

/**
 * Get settings merged with defaults.
 *
 * @return array
 */
function wsb_get_settings() {
    $defaults = array(
        'defer_scripts'      => 1,  // add defer to frontend <script> tags
        'async_css'          => 1,  // load stylesheets via rel=preload swap
        'lazy_iframes'       => 1,  // add loading="lazy" to iframes
        'strip_version'      => 1,  // remove ?ver= from static asset URLs
        'preconnect'         => '', // one host per line, e.g. cdn.example.com
        'excluded_scripts'   => '', // one handle per line (never deferred)
        'excluded_styles'    => '', // one handle per line (never async-loaded)
    );

    $saved = get_option( WSB_OPTION, array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return array_merge( $defaults, $saved );
}

/**
 * Parse a textarea (one-per-line) setting into an array of trimmed strings.
 *
 * @param string $raw Raw textarea value.
 * @return array
 */
function wsb_lines( $raw ) {
    $lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
    $out   = array();
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' !== $line ) {
            $out[] = $line;
        }
    }
    return array_unique( $out );
}

/**
 * Whether a script/style tag is allowed to be optimized for this handle.
 *
 * Always-on exclusions:
 *  - jQuery core/migrate are never deferred (too many inline scripts depend on load order).
 *  - Admin, login, AJAX, cron and customizer requests are untouched.
 *
 * @param string $handle Script/style handle.
 * @param array  $extra  Handles from the settings textarea.
 * @return bool
 */
function wsb_handle_allowed( $handle, $extra ) {
    $built_in = array( 'jquery-core', 'jquery-migrate' );
    $excluded = array_merge( $built_in, $extra );

    /**
     * Filter the handles that are excluded from optimization.
     *
     * @param array  $excluded Excluded handles.
     * @param string $handle   Current handle.
     */
    $excluded = apply_filters( 'wsb_excluded_handles', $excluded, $handle );

    return ! in_array( $handle, $excluded, true );
}

function wsb_is_frontend() {
    return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
}

/* ------------------------------------------------------------------------- *
 * 1. Defer frontend scripts
 * ------------------------------------------------------------------------- */

/**
 * Add defer to frontend script tags.
 */
function wsb_defer_scripts( $tag, $handle, $src ) {
    if ( ! wsb_is_frontend() ) {
        return $tag;
    }

    $settings = wsb_get_settings();
    if ( empty( $settings['defer_scripts'] ) ) {
        return $tag;
    }

    if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
        return $tag;
    }

    if ( ! wsb_handle_allowed( $handle, wsb_lines( $settings['excluded_scripts'] ) ) ) {
        return $tag;
    }

    // Never defer scripts without a src (inline scripts).
    if ( empty( $src ) ) {
        return $tag;
    }

    return str_replace( ' src=', ' defer="defer" src=', $tag );
}
add_filter( 'script_loader_tag', 'wsb_defer_scripts', 10, 3 );

/* ------------------------------------------------------------------------- *
 * 2. Async-load stylesheets
 * ------------------------------------------------------------------------- */

/**
 * Turn rel="stylesheet" into rel="preload" with an onload swap,
 * plus a noscript fallback so no-JS visitors still get styled.
 */
function wsb_async_css( $tag, $handle, $href, $media ) {
    if ( ! wsb_is_frontend() ) {
        return $tag;
    }

    $settings = wsb_get_settings();
    if ( empty( $settings['async_css'] ) ) {
        return $tag;
    }

    if ( ! wsb_handle_allowed( $handle, wsb_lines( $settings['excluded_styles'] ) ) ) {
        return $tag;
    }

    // Skip print/other media — preloading only helps screen CSS.
    if ( 'all' !== $media && 'screen' !== $media ) {
        return $tag;
    }

    $original = $tag; // keep the plain rel="stylesheet" link for the no-JS fallback

    $tag  = str_replace(
        "rel='stylesheet'",
        "rel='preload' as='style' onload=\"this.onload=null;this.rel='stylesheet'\"",
        $tag
    );
    $tag  = str_replace(
        'rel="stylesheet"',
        'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
        $tag
    );
    $tag .= '<noscript>' . $original . '</noscript>';

    return $tag;
}
add_filter( 'style_loader_tag', 'wsb_async_css', 10, 4 );

/* ------------------------------------------------------------------------- *
 * 3. Lazy-load iframes
 * ------------------------------------------------------------------------- */

/**
 * Add loading="lazy" to <iframe> tags in content and text widgets.
 */
function wsb_lazy_iframes( $content ) {
    if ( ! wsb_is_frontend() ) {
        return $content;
    }

    $settings = wsb_get_settings();
    if ( empty( $settings['lazy_iframes'] ) ) {
        return $content;
    }

    return preg_replace_callback(
        '/<iframe(?![^>]*\bloading=)[^>]*>/i',
        function ( $matches ) {
            return str_replace( '<iframe', '<iframe loading="lazy"', $matches[0] );
        },
        $content
    );
}
add_filter( 'the_content', 'wsb_lazy_iframes', 20 );
add_filter( 'widget_text_content', 'wsb_lazy_iframes', 20 );

/* ------------------------------------------------------------------------- *
 * 4. Strip ?ver= query strings
 * ------------------------------------------------------------------------- */

/**
 * Remove the ?ver= cache-buster from static asset URLs so shared caches
 * (CDN, browser) hit on the canonical URL.
 */
function wsb_strip_version( $src ) {
    if ( ! wsb_is_frontend() ) {
        return $src;
    }

    $settings = wsb_get_settings();
    if ( empty( $settings['strip_version'] ) ) {
        return $src;
    }

    return remove_query_arg( 'ver', $src );
}
add_filter( 'script_loader_src', 'wsb_strip_version', 20 );
add_filter( 'style_loader_src', 'wsb_strip_version', 20 );

/* ------------------------------------------------------------------------- *
 * 5. Preconnect hints
 * ------------------------------------------------------------------------- */

/**
 * Output <link rel="preconnect"> for hosts listed in settings.
 */
function wsb_preconnect_hints() {
    $settings = wsb_get_settings();
    $hosts    = wsb_lines( $settings['preconnect'] );

    /**
     * Filter the preconnect host list.
     *
     * @param array $hosts Hostnames without scheme, e.g. ['cdn.example.com'].
     */
    $hosts = apply_filters( 'wsb_preconnect_hosts', $hosts );

    foreach ( $hosts as $host ) {
        $host = preg_replace( '#^https?://#', '', $host );
        $host = trim( $host, '/' );
        if ( '' !== $host ) {
            printf(
                '<link rel="preconnect" href="https://%s" crossorigin>' . "\n",
                esc_attr( $host )
            );
        }
    }
}
add_action( 'wp_head', 'wsb_preconnect_hints', 1 );

/* ------------------------------------------------------------------------- *
 * Settings page
 * ------------------------------------------------------------------------- */

function wsb_register_settings() {
    register_setting( 'wsb_group', WSB_OPTION, 'wsb_sanitize' );

    add_settings_section( 'wsb_main', 'Speed Tweaks', '__return_null', 'wsb' );

    $fields = array(
        'defer_scripts'    => 'Defer frontend scripts',
        'async_css'        => 'Load CSS asynchronously (preload swap)',
        'lazy_iframes'     => 'Lazy-load iframes (embeds, maps, videos)',
        'strip_version'    => 'Strip ?ver= from static asset URLs',
    );

    foreach ( $fields as $key => $label ) {
        add_settings_field(
            $key,
            $label,
            'wsb_checkbox_field',
            'wsb',
            'wsb_main',
            array( 'key' => $key )
        );
    }

    add_settings_field(
        'preconnect',
        'Preconnect hosts (one per line)',
        'wsb_textarea_field',
        'wsb',
        'wsb_main',
        array( 'key' => 'preconnect' )
    );
    add_settings_field(
        'excluded_scripts',
        'Never defer these script handles (one per line)',
        'wsb_textarea_field',
        'wsb',
        'wsb_main',
        array( 'key' => 'excluded_scripts' )
    );
    add_settings_field(
        'excluded_styles',
        'Never async-load these style handles (one per line)',
        'wsb_textarea_field',
        'wsb',
        'wsb_main',
        array( 'key' => 'excluded_styles' )
    );
}
add_action( 'admin_init', 'wsb_register_settings' );

function wsb_sanitize( $input ) {
    $clean = array();
    foreach ( array( 'defer_scripts', 'async_css', 'lazy_iframes', 'strip_version' ) as $key ) {
        $clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
    }
    foreach ( array( 'preconnect', 'excluded_scripts', 'excluded_styles' ) as $key ) {
        $clean[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( $input[ $key ] ) : '';
    }
    return $clean;
}

function wsb_checkbox_field( $args ) {
    $settings = wsb_get_settings();
    printf(
        '<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s>',
        esc_attr( WSB_OPTION ),
        esc_attr( $args['key'] ),
        checked( 1, (int) $settings[ $args['key'] ], false )
    );
}

function wsb_textarea_field( $args ) {
    $settings = wsb_get_settings();
    printf(
        '<textarea name="%1$s[%2$s]" rows="4" cols="50" class="large-text code">%3$s</textarea>',
        esc_attr( WSB_OPTION ),
        esc_attr( $args['key'] ),
        esc_textarea( $settings[ $args['key'] ] )
    );
}

function wsb_add_menu() {
    add_options_page(
        'Speed Booster',
        'Speed Booster',
        'manage_options',
        'wsb',
        'wsb_render_page'
    );
}
add_action( 'admin_menu', 'wsb_add_menu' );

function wsb_render_page() {
    ?>
    <div class="wrap">
        <h1>Speed Booster</h1>
        <p>Lightweight frontend optimizations. Everything applies to the frontend only — admin, login, AJAX and cron are untouched. jQuery core is never deferred.</p>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wsb_group' );
            do_settings_sections( 'wsb' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
