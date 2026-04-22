<?php
/**
 * Plugin Name:  Trace IDs
 * Description:  Captures click IDs (GCLID, fbclid) from URL, stores in cookies, and populates Gravity Forms hidden fields — resilient to page and object caching.
 * Version:      1.2.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:       LifeX Marketing
 * License:      GPL-2.0+
 * Update URI:   https://github.com/lifexmarketing/trace-ids
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TRACE_IDS_VERSION', '1.2.0' );
define( 'TRACE_IDS_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'TRACE_IDS_REPO', 'lifexmarketing/trace-ids' );

// --- Settings ---

function trace_ids_tracker_defaults() {
    return [
        'gclid'  => [ 'enabled' => 1, 'param' => 'gclid',  'cookie_expiry' => 90, 'gf_css_class' => 'gclid-field'  ],
        'fbclid' => [ 'enabled' => 1, 'param' => 'fbclid', 'cookie_expiry' => 90, 'gf_css_class' => 'fbclid-field' ],
    ];
}

function trace_ids_settings() {
    $saved    = get_option( 'trace_ids_settings', [] );
    $defaults = trace_ids_tracker_defaults();
    foreach ( $defaults as $key => $default ) {
        $saved[ $key ] = isset( $saved[ $key ] )
            ? wp_parse_args( $saved[ $key ], $default )
            : $default;
    }
    return $saved;
}

// Migrate flat v1.1.0 settings to the per-tracker array format.
add_action( 'plugins_loaded', 'trace_ids_maybe_migrate' );
function trace_ids_maybe_migrate() {
    $saved = get_option( 'trace_ids_settings', [] );
    if ( empty( $saved ) || ! isset( $saved['gf_parameter'] ) ) return;

    $new           = trace_ids_tracker_defaults();
    $new['gclid']  = [
        'enabled'       => (int) ( $saved['enabled'] ?? 1 ),
        'param'         => sanitize_key( $saved['gf_parameter'] ?? 'gclid' ),
        'cookie_expiry' => absint( $saved['cookie_expiry'] ?? 90 ),
        'gf_css_class'  => sanitize_html_class( $saved['gf_css_class'] ?? 'gclid-field' ),
    ];
    update_option( 'trace_ids_settings', $new );
}

// --- Admin page ---

add_action( 'admin_menu', 'trace_ids_admin_menu' );
function trace_ids_admin_menu() {
    add_options_page( 'Trace IDs', 'Trace IDs', 'manage_options', 'trace-ids', 'trace_ids_settings_page' );
}

add_action( 'admin_init', 'trace_ids_register_settings' );
function trace_ids_register_settings() {
    register_setting( 'trace_ids_options', 'trace_ids_settings', [
        'sanitize_callback' => 'trace_ids_sanitize',
    ] );
}

function trace_ids_sanitize( $input ) {
    $defaults = trace_ids_tracker_defaults();
    $output   = [];
    foreach ( $defaults as $key => $default ) {
        $raw    = $input[ $key ] ?? [];
        $expiry = absint( $raw['cookie_expiry'] ?? $default['cookie_expiry'] );
        $output[ $key ] = [
            'enabled'       => ! empty( $raw['enabled'] ) ? 1 : 0,
            'param'         => sanitize_key( $raw['param'] ?? $default['param'] ) ?: $default['param'],
            'cookie_expiry' => $expiry > 0 ? $expiry : $default['cookie_expiry'],
            'gf_css_class'  => sanitize_html_class( $raw['gf_css_class'] ?? $default['gf_css_class'] ) ?: $default['gf_css_class'],
        ];
    }
    return $output;
}

function trace_ids_settings_page() {
    $s       = trace_ids_settings();
    $labels  = [
        'gclid'  => 'GCLID <span style="font-weight:normal;color:#646970;">(Google Ads)</span>',
        'fbclid' => 'fbclid <span style="font-weight:normal;color:#646970;">(Facebook / Meta)</span>',
    ];
    ?>
    <div class="wrap">
        <h1>Trace IDs</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'trace_ids_options' ); ?>

            <?php foreach ( $labels as $key => $label ) :
                $t = $s[ $key ];
            ?>
            <h2><?php echo $label; ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="trace_ids_settings[<?php echo $key; ?>][enabled]" value="1" <?php checked( 1, $t['enabled'] ); ?>>
                            Active
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Cookie expiry</th>
                    <td>
                        <input type="number" name="trace_ids_settings[<?php echo $key; ?>][cookie_expiry]" value="<?php echo esc_attr( $t['cookie_expiry'] ); ?>" min="1" max="365" class="small-text"> days
                    </td>
                </tr>
                <tr>
                    <th scope="row">GF parameter name</th>
                    <td>
                        <input type="text" name="trace_ids_settings[<?php echo $key; ?>][param]" value="<?php echo esc_attr( $t['param'] ); ?>" class="regular-text">
                        <p class="description">Matches the <strong>Allow field to be populated dynamically</strong> parameter name on your GF hidden field. Also used as the cookie name.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">GF field CSS class</th>
                    <td>
                        <input type="text" name="trace_ids_settings[<?php echo $key; ?>][gf_css_class]" value="<?php echo esc_attr( $t['gf_css_class'] ); ?>" class="regular-text">
                        <p class="description">Custom CSS class added to the GF hidden field under <strong>Appearance &rsaquo; Custom CSS Class</strong>.</p>
                    </td>
                </tr>
            </table>
            <?php endforeach; ?>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// --- Core functionality ---

add_action( 'init', 'trace_ids_init' );
function trace_ids_init() {
    $s        = trace_ids_settings();
    $enabled  = array_filter( $s, fn( $t ) => ! empty( $t['enabled'] ) );
    if ( empty( $enabled ) ) return;

    if ( ! is_admin() ) {
        add_action( 'wp_head', 'trace_ids_script', 1 );
    }
    foreach ( $enabled as $tracker ) {
        $param = $tracker['param'];
        add_filter( "gform_field_value_{$param}", function () use ( $param ) {
            return isset( $_COOKIE[ $param ] ) ? sanitize_text_field( $_COOKIE[ $param ] ) : '';
        } );
    }
}

function trace_ids_script() {
    $s       = trace_ids_settings();
    $active  = [];
    foreach ( $s as $tracker ) {
        if ( ! empty( $tracker['enabled'] ) ) {
            $active[] = [
                'param'    => $tracker['param'],
                'expiry'   => (int) $tracker['cookie_expiry'],
                'cssClass' => $tracker['gf_css_class'],
            ];
        }
    }
    if ( empty( $active ) ) return;
    ?>
<script>
(function () {
    var trackers = <?php echo wp_json_encode( $active ); ?>;
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
    trackers.forEach(function (t) {
        var val = getParam(t.param);
        if (val) setCookie(t.param, val, t.expiry);
    });
    function populate() {
        trackers.forEach(function (t) {
            var stored = getCookie(t.param);
            if (!stored) return;
            document.querySelectorAll('.' + t.cssClass + ' input').forEach(function (el) {
                el.value = stored;
            });
        });
    }
    document.addEventListener('DOMContentLoaded', populate);
    document.addEventListener('gform_post_render', populate);
})();
</script>
    <?php
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
