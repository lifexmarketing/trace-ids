<?php
/**
 * Plugin Name:  Trace IDs
 * Description:  Captures click IDs (GCLID, fbclid) from URL, stores in cookies, populates Gravity Forms hidden fields, and appends parameters to HighLevel iframes — resilient to page and object caching.
 * Version:      1.5.1
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:       LifeX Marketing
 * License:      GPL-2.0+
 * Update URI:   https://github.com/lifexmarketing/trace-ids
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TRACE_IDS_VERSION', '1.5.1' );
define( 'TRACE_IDS_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'TRACE_IDS_REPO', 'lifexmarketing/trace-ids' );

// --- Settings ---

function trace_ids_tracker_defaults() {
    return [
        'gclid'  => [ 'enabled' => 1, 'param' => 'gclid',  'cookie_expiry' => 90 ],
        'fbclid' => [ 'enabled' => 0, 'param' => 'fbclid', 'cookie_expiry' => 90 ],
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
    $saved['highlevel'] = wp_parse_args( $saved['highlevel'] ?? [], [ 'enabled' => 0 ] );
    return $saved;
}

// Migrate from v1.1.0 flat format or v1.2.0 format (which had gf_css_class).
add_action( 'plugins_loaded', 'trace_ids_maybe_migrate' );
function trace_ids_maybe_migrate() {
    $saved = get_option( 'trace_ids_settings', [] );
    if ( empty( $saved ) || ! isset( $saved['gf_parameter'] ) ) return;

    $new          = trace_ids_tracker_defaults();
    $new['gclid'] = [
        'enabled'       => (int) ( $saved['enabled'] ?? 1 ),
        'param'         => sanitize_key( $saved['gf_parameter'] ?? 'gclid' ),
        'cookie_expiry' => absint( $saved['cookie_expiry'] ?? 90 ),
    ];
    update_option( 'trace_ids_settings', $new );
}

// --- Admin page ---

add_action( 'admin_menu', 'trace_ids_admin_menu' );
function trace_ids_admin_menu() {
    add_options_page( 'Trace IDs', 'Trace IDs', 'manage_options', 'trace-ids', 'trace_ids_settings_page' );
}

add_filter( 'plugin_action_links_' . TRACE_IDS_PLUGIN_FILE, 'trace_ids_plugin_action_links' );
function trace_ids_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=trace-ids' ) ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
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
        ];
    }
    $output['highlevel'] = [ 'enabled' => ! empty( $input['highlevel']['enabled'] ) ? 1 : 0 ];
    return $output;
}

function trace_ids_settings_page() {
    $s      = trace_ids_settings();
    $labels = [
        'gclid'  => 'GCLID <span style="font-weight:normal;color:#646970;">(Google Ads)</span>',
        'fbclid' => 'fbclid <span style="font-weight:normal;color:#646970;">(Facebook / Meta)</span>',
    ];
    ?>
    <div class="wrap">
        <h1>Trace IDs</h1>

        <?php trace_ids_render_gf_notice(); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'trace_ids_options' ); ?>

            <h2>Gravity Forms</h2>

            <?php foreach ( $labels as $key => $label ) :
                $t = $s[ $key ];
            ?>
            <h3><?php echo $label; ?></h3>
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
                        <p class="description">Matches the <strong>Allow field to be populated dynamically</strong> parameter name on your GF hidden field.</p>
                    </td>
                </tr>
            </table>
            <?php endforeach; ?>

            <h2>HighLevel Iframes</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable</th>
                    <td>
                        <label>
                            <input type="checkbox" name="trace_ids_settings[highlevel][enabled]" value="1" <?php checked( 1, $s['highlevel']['enabled'] ); ?>>
                            Active
                        </label>
                        <p class="description">Appends tracked parameters to HighLevel iframe URLs on page load. Detects iframes from <code>gohighlevel.com</code>, <code>leadconnectorhq.com</code>, and <code>msgsndr.com</code>. Compatible with WP Rocket's lazy load.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2>Gravity Forms Maintenance</h2>
        <?php trace_ids_render_gf_maintenance(); ?>
    </div>
    <?php
}

function trace_ids_render_gf_notice() {
    if ( ! isset( $_GET['trace_ids_notice'] ) ) return;

    $count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
    $notice = sanitize_key( wp_unslash( $_GET['trace_ids_notice'] ) );

    if ( $notice === 'added_gclid' ) {
        $message = $count === 1
            ? 'Added a gclid field to 1 form.'
            : "Added a gclid field to {$count} forms.";
    } elseif ( $notice === 'added_fbclid' ) {
        $message = $count === 1
            ? 'Added an fbclid field to 1 form.'
            : "Added an fbclid field to {$count} forms.";
    } elseif ( $notice === 'converted' ) {
        $message = $count === 1
            ? 'Converted the HandL gclid field on 1 form.'
            : "Converted the HandL gclid field on {$count} forms.";
    } else {
        return;
    }
    ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
    <?php
}

function trace_ids_render_gf_maintenance() {
    $gf = trace_ids_gf_status();

    if ( ! $gf['available'] ) {
        echo '<p>Gravity Forms is not active.</p>';
        return;
    }
    ?>
    <p>
        <?php if ( $gf['missing']['gclid'] > 0 ) : ?>
            <?php echo esc_html( $gf['missing']['gclid'] ); ?> form(s) do not have a gclid field yet.
        <?php else : ?>
            All forms already have a gclid field.
        <?php endif; ?>
    </p>

    <?php if ( $gf['missing']['gclid'] > 0 ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
        <?php wp_nonce_field( 'trace_ids_gf_action' ); ?>
        <input type="hidden" name="action" value="trace_ids_add_gclid_fields">
        <?php submit_button( 'Add gclid field to all forms', 'secondary', 'submit', false ); ?>
    </form>
    <?php endif; ?>

    <p>
        <?php if ( $gf['missing']['fbclid'] > 0 ) : ?>
            <?php echo esc_html( $gf['missing']['fbclid'] ); ?> form(s) do not have an fbclid field yet.
        <?php else : ?>
            All forms already have an fbclid field.
        <?php endif; ?>
    </p>

    <?php if ( $gf['missing']['fbclid'] > 0 ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
        <?php wp_nonce_field( 'trace_ids_gf_action' ); ?>
        <input type="hidden" name="action" value="trace_ids_add_fbclid_fields">
        <?php submit_button( 'Add fbclid field to all forms', 'secondary', 'submit', false ); ?>
    </form>
    <?php endif; ?>

    <?php if ( $gf['handl_forms'] > 0 ) : ?>
    <div class="notice notice-warning inline">
        <p>
            Found <?php echo esc_html( $gf['handl_fields'] ); ?> gclid field(s) on <?php echo esc_html( $gf['handl_forms'] ); ?> form(s) created by the HandL UTM Grabber plugin.
            These use a legacy field type and won't populate correctly.
        </p>
    </div>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'trace_ids_gf_action' ); ?>
        <input type="hidden" name="action" value="trace_ids_convert_handl_fields">
        <?php submit_button( 'Convert HandL gclid fields to standard hidden fields', 'secondary', 'submit', false ); ?>
    </form>
    <?php endif; ?>
    <?php
}

// --- Gravity Forms field management ---

function trace_ids_gf_available() {
    return class_exists( 'GFAPI' ) && class_exists( 'GF_Fields' );
}

function trace_ids_field_is_handl( $field ) {
    // Older HandL Free versions registered a custom GF field type.
    if ( strpos( (string) $field->type, 'handlfree_' ) === 0 ) return true;
    if ( ! empty( $field->inputType ) && strpos( (string) $field->inputType, 'handlfree_' ) === 0 ) return true;

    // Current HandL UTM Grabber: a standard "hidden" field, but labeled
    // "HandL ( param )" and left with a stray, empty inputType property.
    if ( preg_match( '/^HandL\s*\(/i', (string) $field->label ) ) return true;

    return false;
}

function trace_ids_gf_status() {
    $status = [
        'available'    => trace_ids_gf_available(),
        'missing'      => [ 'gclid' => 0, 'fbclid' => 0 ],
        'handl_forms'  => 0,
        'handl_fields' => 0,
    ];
    if ( ! $status['available'] ) return $status;

    $s      = trace_ids_settings();
    $params = [ 'gclid' => $s['gclid']['param'], 'fbclid' => $s['fbclid']['param'] ];

    foreach ( GFAPI::get_forms() as $form ) {
        $has_param      = [ 'gclid' => false, 'fbclid' => false ];
        $form_has_handl = false;

        foreach ( $form['fields'] as $field ) {
            if ( empty( $field->inputName ) ) continue;

            foreach ( $params as $key => $param ) {
                if ( $field->inputName !== $param ) continue;
                $has_param[ $key ] = true;
                if ( $key === 'gclid' && trace_ids_field_is_handl( $field ) ) {
                    $form_has_handl = true;
                    $status['handl_fields']++;
                }
            }
        }

        foreach ( $params as $key => $param ) {
            if ( ! $has_param[ $key ] ) $status['missing'][ $key ]++;
        }
        if ( $form_has_handl ) $status['handl_forms']++;
    }
    return $status;
}

function trace_ids_add_missing_tracker_fields( $tracker_key ) {
    if ( ! trace_ids_gf_available() ) return 0;

    $param   = trace_ids_settings()[ $tracker_key ]['param'];
    $updated = 0;

    foreach ( GFAPI::get_forms() as $form ) {
        $has_param = false;
        foreach ( $form['fields'] as $field ) {
            if ( ! empty( $field->inputName ) && $field->inputName === $param ) {
                $has_param = true;
                break;
            }
        }
        if ( $has_param ) continue;

        $next_id = class_exists( 'GFFormsModel' )
            ? GFFormsModel::get_next_field_id( $form['fields'] )
            : ( (int) array_reduce( $form['fields'], fn( $max, $f ) => max( $max, (int) $f->id ), 0 ) + 1 );

        $form['fields'][] = GF_Fields::create( [
            'type'              => 'hidden',
            'id'                => $next_id,
            'formId'            => $form['id'],
            'label'             => $param,
            'inputName'         => $param,
            'allowsPrepopulate' => true,
        ] );

        GFAPI::update_form( $form );
        $updated++;
    }
    return $updated;
}

function trace_ids_convert_handl_gclid_fields() {
    if ( ! trace_ids_gf_available() ) return 0;

    $param   = trace_ids_settings()['gclid']['param'];
    $updated = 0;

    foreach ( GFAPI::get_forms() as $form ) {
        $changed = false;

        foreach ( $form['fields'] as $field ) {
            if ( empty( $field->inputName ) || $field->inputName !== $param ) continue;
            if ( ! trace_ids_field_is_handl( $field ) ) continue;

            $field->type              = 'hidden';
            $field->label             = $param;
            $field->allowsPrepopulate = true;
            if ( isset( $field->inputType ) ) unset( $field->inputType );
            $changed = true;
        }

        if ( $changed ) {
            GFAPI::update_form( $form );
            $updated++;
        }
    }
    return $updated;
}

add_action( 'admin_post_trace_ids_add_gclid_fields', 'trace_ids_handle_add_gclid_fields' );
function trace_ids_handle_add_gclid_fields() {
    trace_ids_handle_add_tracker_fields( 'gclid' );
}

add_action( 'admin_post_trace_ids_add_fbclid_fields', 'trace_ids_handle_add_fbclid_fields' );
function trace_ids_handle_add_fbclid_fields() {
    trace_ids_handle_add_tracker_fields( 'fbclid' );
}

function trace_ids_handle_add_tracker_fields( $tracker_key ) {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
    check_admin_referer( 'trace_ids_gf_action' );

    $updated = trace_ids_add_missing_tracker_fields( $tracker_key );
    wp_safe_redirect( add_query_arg(
        [ 'page' => 'trace-ids', 'trace_ids_notice' => 'added_' . $tracker_key, 'count' => $updated ],
        admin_url( 'options-general.php' )
    ) );
    exit;
}

add_action( 'admin_post_trace_ids_convert_handl_fields', 'trace_ids_handle_convert_handl_fields' );
function trace_ids_handle_convert_handl_fields() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
    check_admin_referer( 'trace_ids_gf_action' );

    $updated = trace_ids_convert_handl_gclid_fields();
    wp_safe_redirect( add_query_arg(
        [ 'page' => 'trace-ids', 'trace_ids_notice' => 'converted', 'count' => $updated ],
        admin_url( 'options-general.php' )
    ) );
    exit;
}

// --- Core functionality ---

add_action( 'init', 'trace_ids_init' );
function trace_ids_init() {
    $s       = trace_ids_settings();
    $enabled = array_filter(
        $s,
        fn( $t, $k ) => $k !== 'highlevel' && ! empty( $t['enabled'] ),
        ARRAY_FILTER_USE_BOTH
    );
    if ( empty( $enabled ) ) return;

    if ( ! is_admin() ) {
        add_action( 'wp_head', 'trace_ids_script', 1 );
    }

    add_filter( 'gform_field_content', 'trace_ids_inject_data_attr', 10, 2 );

    foreach ( $enabled as $tracker ) {
        $param = $tracker['param'];
        add_filter( "gform_field_value_{$param}", function () use ( $param ) {
            return isset( $_COOKIE[ $param ] ) ? sanitize_text_field( $_COOKIE[ $param ] ) : '';
        } );
    }
}

function trace_ids_inject_data_attr( $content, $field ) {
    if ( empty( $field->inputName ) ) return $content;

    $s      = trace_ids_settings();
    $params = array_column( array_values( array_filter(
        $s,
        fn( $k ) => $k !== 'highlevel',
        ARRAY_FILTER_USE_KEY
    ) ), 'param' );
    if ( ! in_array( $field->inputName, $params, true ) ) return $content;

    return preg_replace(
        '/<input\b/',
        '<input data-trace-param="' . esc_attr( $field->inputName ) . '"',
        $content,
        1
    );
}

function trace_ids_script() {
    $s      = trace_ids_settings();
    $hl     = $s['highlevel'];
    $active = [];
    foreach ( $s as $key => $tracker ) {
        if ( $key === 'highlevel' ) continue;
        if ( ! empty( $tracker['enabled'] ) ) {
            $active[] = [ 'param' => $tracker['param'], 'expiry' => (int) $tracker['cookie_expiry'] ];
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

    // Capture URL params into cookies
    trackers.forEach(function (t) {
        var val = getParam(t.param);
        if (val) setCookie(t.param, val, t.expiry);
    });

    // Populate Gravity Forms hidden fields
    function populateGF() {
        trackers.forEach(function (t) {
            var stored = getCookie(t.param);
            if (!stored) return;
            document.querySelectorAll('input[data-trace-param="' + t.param + '"]').forEach(function (el) {
                el.value = stored;
            });
        });
    }
    document.addEventListener('DOMContentLoaded', populateGF);
    document.addEventListener('gform_post_render', populateGF);

<?php if ( ! empty( $hl['enabled'] ) ) : ?>
    // Append tracked params to HighLevel iframes.
    // Checks src, data-lazy-src (WP Rocket), and data-src so lazy-loaded
    // iframes are handled before WP Rocket swaps the attribute to src.
    var HL_DOMAINS = ['gohighlevel.com', 'leadconnectorhq.com', 'msgsndr.com'];

    function isHLSrc(src) {
        if (!src || src === 'about:blank') return false;
        try {
            var host = new URL(src).hostname;
            return HL_DOMAINS.some(function (d) {
                return host === d || host.slice(-(d.length + 1)) === '.' + d;
            });
        } catch (e) { return false; }
    }

    function getHLAttr(el) {
        var src = el.getAttribute('src');
        if (isHLSrc(src)) return 'src';
        var lazy = el.getAttribute('data-lazy-src');
        if (isHLSrc(lazy)) return 'data-lazy-src';
        var ds = el.getAttribute('data-src');
        if (isHLSrc(ds)) return 'data-src';
        return null;
    }

    function injectHL(el) {
        if (el.tagName !== 'IFRAME') return;
        var attr = getHLAttr(el);
        if (!attr) return;
        var src  = el.getAttribute(attr);
        var parts = [];
        trackers.forEach(function (t) {
            if (src.indexOf(t.param + '=') !== -1) return;
            var val = getCookie(t.param);
            if (val) parts.push(encodeURIComponent(t.param) + '=' + encodeURIComponent(val));
        });
        if (!parts.length) return;
        el.setAttribute(attr, src + (src.indexOf('?') !== -1 ? '&' : '?') + parts.join('&'));
    }

    function processHL() {
        document.querySelectorAll('iframe').forEach(injectHL);
    }

    document.addEventListener('DOMContentLoaded', processHL);

    // Catch iframes injected after page load (page builders, lazy rendering)
    var hlObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.tagName === 'IFRAME') { injectHL(node); return; }
                if (node.querySelectorAll) node.querySelectorAll('iframe').forEach(injectHL);
            });
        });
    });
    hlObserver.observe(document.documentElement, { childList: true, subtree: true });
<?php endif; ?>

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
        'version'      => $latest,
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
