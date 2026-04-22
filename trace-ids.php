<?php
/**
 * Plugin Name:  Trace IDs
 * Description:  Captures GCLID from URL, stores in a cookie, and populates a Gravity Forms hidden field — resilient to page and object caching.
 * Version:      1.1.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:       LifeX Marketing
 * License:      GPL-2.0+
 * Update URI:   https://github.com/lifexmarketing/trace-ids
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TRACE_IDS_VERSION', '1.1.0' );
define( 'TRACE_IDS_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'TRACE_IDS_REPO', 'lifexmarketing/trace-ids' );

// --- Settings helpers ---

function trace_ids_defaults() {
    return [
        'enabled'       => 1,
        'cookie_expiry' => 90,
        'gf_parameter'  => 'gclid',
        'gf_css_class'  => 'gclid-field',
    ];
}

function trace_ids_settings() {
    return wp_parse_args( get_option( 'trace_ids_settings', [] ), trace_ids_defaults() );
}

// --- Admin page ---

add_action( 'admin_menu', 'trace_ids_admin_menu' );
function trace_ids_admin_menu() {
    add_options_page(
        'Trace IDs',
        'Trace IDs',
        'manage_options',
        'trace-ids',
        'trace_ids_settings_page'
    );
}

add_action( 'admin_init', 'trace_ids_register_settings' );
function trace_ids_register_settings() {
    register_setting( 'trace_ids_options', 'trace_ids_settings', [
        'sanitize_callback' => 'trace_ids_sanitize',
    ] );

    add_settings_section( 'trace_ids_main', '', '__return_null', 'trace-ids' );

    add_settings_field( 'enabled',       'Enable GCLID tracking',             'trace_ids_field_enabled',       'trace-ids', 'trace_ids_main' );
    add_settings_field( 'cookie_expiry', 'Cookie expiry',                     'trace_ids_field_cookie_expiry', 'trace-ids', 'trace_ids_main' );
    add_settings_field( 'gf_parameter',  'GF dynamic population parameter',   'trace_ids_field_gf_parameter',  'trace-ids', 'trace_ids_main' );
    add_settings_field( 'gf_css_class',  'GF field CSS class',                'trace_ids_field_gf_css_class',  'trace-ids', 'trace_ids_main' );
}

function trace_ids_sanitize( $input ) {
    $d = trace_ids_defaults();
    $expiry = absint( $input['cookie_expiry'] ?? $d['cookie_expiry'] );
    return [
        'enabled'       => ! empty( $input['enabled'] ) ? 1 : 0,
        'cookie_expiry' => $expiry > 0 ? $expiry : $d['cookie_expiry'],
        'gf_parameter'  => sanitize_key( $input['gf_parameter'] ?? $d['gf_parameter'] ) ?: $d['gf_parameter'],
        'gf_css_class'  => sanitize_html_class( $input['gf_css_class'] ?? $d['gf_css_class'] ) ?: $d['gf_css_class'],
    ];
}

function trace_ids_field_enabled() {
    $s = trace_ids_settings();
    echo '<label><input type="checkbox" name="trace_ids_settings[enabled]" value="1"' . checked( 1, $s['enabled'], false ) . '> Active</label>';
}

function trace_ids_field_cookie_expiry() {
    $s = trace_ids_settings();
    echo '<input type="number" name="trace_ids_settings[cookie_expiry]" value="' . esc_attr( $s['cookie_expiry'] ) . '" min="1" max="365" class="small-text"> days';
}

function trace_ids_field_gf_parameter() {
    $s = trace_ids_settings();
    echo '<input type="text" name="trace_ids_settings[gf_parameter]" value="' . esc_attr( $s['gf_parameter'] ) . '" class="regular-text">';
    echo '<p class="description">Must match the <strong>Allow field to be populated dynamically</strong> parameter name on your GF hidden field.</p>';
}

function trace_ids_field_gf_css_class() {
    $s = trace_ids_settings();
    echo '<input type="text" name="trace_ids_settings[gf_css_class]" value="' . esc_attr( $s['gf_css_class'] ) . '" class="regular-text">';
    echo '<p class="description">Must match the custom CSS class added to your GF hidden field under <strong>Appearance &rsaquo; Custom CSS Class</strong>.</p>';
}

function trace_ids_settings_page() { ?>
<div class="wrap">
    <h1>Trace IDs</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'trace_ids_options' );
        do_settings_sections( 'trace-ids' );
        submit_button();
        ?>
    </form>
</div>
<?php }

// --- Core functionality ---

add_action( 'init', 'trace_ids_init' );
function trace_ids_init() {
    $s = trace_ids_settings();
    if ( ! $s['enabled'] ) return;

    if ( ! is_admin() ) {
        add_action( 'wp_head', 'trace_ids_script', 1 );
    }
    add_filter( 'gform_field_value_' . $s['gf_parameter'], 'trace_ids_php_fallback' );
}

function trace_ids_script() {
    $s      = trace_ids_settings();
    $expiry = (int) $s['cookie_expiry'];
    $param  = esc_js( $s['gf_parameter'] );
    $class  = esc_js( $s['gf_css_class'] );
    ?>
<script>
(function () {
    function getParam(name) {
        var m = new RegExp('[?&]' + name + '=([^&]*)').exec(location.search);
        return m ? decodeURIComponent(m[1]) : null;
    }
    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? m[m.length - 1] : null;
    }
    function setCookie(name, val, days) {
        var exp = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + val + '; expires=' + exp + '; path=/; SameSite=Lax';
    }
    var val = getParam('<?php echo $param; ?>');
    if (val) setCookie('<?php echo $param; ?>', val, <?php echo $expiry; ?>);
    function populate() {
        var stored = getCookie('<?php echo $param; ?>');
        if (!stored) return;
        document.querySelectorAll('.<?php echo $class; ?> input').forEach(function (el) {
            el.value = stored;
        });
    }
    document.addEventListener('DOMContentLoaded', populate);
    document.addEventListener('gform_post_render', populate);
})();
</script>
    <?php
}

function trace_ids_php_fallback() {
    $param = trace_ids_settings()['gf_parameter'];
    return isset( $_COOKIE[ $param ] ) ? sanitize_text_field( $_COOKIE[ $param ] ) : '';
}

// --- GitHub updater ---

add_filter( 'update_plugins_github.com', 'trace_ids_check_update', 10, 4 );
function trace_ids_check_update( $update, $plugin_data, $plugin_file, $locales ) {
    if ( $plugin_file !== TRACE_IDS_PLUGIN_FILE ) {
        return $update;
    }

    $release = trace_ids_get_latest_release();
    if ( ! $release || empty( $release->tag_name ) ) {
        return $update;
    }

    $latest = ltrim( $release->tag_name, 'v' );
    if ( version_compare( $latest, TRACE_IDS_VERSION, '<=' ) ) {
        return $update;
    }

    $zip_url = '';
    if ( ! empty( $release->assets ) ) {
        foreach ( $release->assets as $asset ) {
            if ( substr( $asset->name, -4 ) === '.zip' ) {
                $zip_url = $asset->browser_download_url;
                break;
            }
        }
    }
    if ( ! $zip_url ) {
        return $update;
    }

    return (object) [
        'id'           => 'github.com/' . TRACE_IDS_REPO,
        'slug'         => 'trace-ids',
        'plugin'       => $plugin_file,
        'new_version'  => $latest,
        'url'          => 'https://github.com/' . TRACE_IDS_REPO,
        'package'      => $zip_url,
        'icons'        => [],
        'banners'      => [],
        'tested'       => '',
        'requires_php' => '7.4',
        'compatibility' => new stdClass(),
    ];
}

function trace_ids_get_latest_release() {
    $transient_key = 'trace_ids_latest_release';
    $cached        = get_transient( $transient_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . TRACE_IDS_REPO . '/releases/latest',
        [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $release = json_decode( wp_remote_retrieve_body( $response ) );
    set_transient( $transient_key, $release, 12 * HOUR_IN_SECONDS );
    return $release;
}
