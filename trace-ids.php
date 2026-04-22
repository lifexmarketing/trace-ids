<?php
/**
 * Plugin Name:  Trace IDs
 * Description:  Captures GCLID from URL, stores in a 90-day cookie, and populates a Gravity Forms hidden field — resilient to page and object caching.
 * Version:      1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:       LifeX Marketing
 * License:      GPL-2.0+
 * Update URI:   https://github.com/lifexmarketing/trace-ids
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TRACE_IDS_VERSION', '1.0.0' );
define( 'TRACE_IDS_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'TRACE_IDS_REPO', 'lifexmarketing/trace-ids' );

// --- Core functionality ---

add_action( 'wp_head', 'trace_ids_script', 1 );
function trace_ids_script() { ?>
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
    var gclid = getParam('gclid');
    if (gclid) setCookie('gclid', gclid, 90);
    function populate() {
        var stored = getCookie('gclid');
        if (!stored) return;
        document.querySelectorAll('.gclid-field input').forEach(function (el) {
            el.value = stored;
        });
    }
    document.addEventListener('DOMContentLoaded', populate);
    document.addEventListener('gform_post_render', populate);
})();
</script>
<?php }

add_filter( 'gform_field_value_gclid', 'trace_ids_php_fallback' );
function trace_ids_php_fallback() {
    return isset( $_COOKIE['gclid'] ) ? sanitize_text_field( $_COOKIE['gclid'] ) : '';
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
