<?php
/**
 * Plugin Name: SC Podcasts
 * Plugin URI: https://warontherocks.com
 * Description: Supporting Cast integrated podcast management with parallel mode for safe transition from Increment
 * Version: 1.0.0
 * Author: War on the Rocks
 * Text Domain: sc-podcasts
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('WPINC')) {
    die;
}

define('SC_PODCASTS_VERSION', '1.0.0');
define('SC_PODCASTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SC_PODCASTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Determine operating mode
function sc_podcasts_init_mode() {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    
    if (is_plugin_active('increment-core/increment-core.php') || get_option('sc_parallel_mode', true)) {
        define('SC_POST_TYPE', 'sc_episode');
        define('SC_TAXONOMY', 'sc_premium');
        define('SC_PARALLEL_MODE', true);
    } else {
        define('SC_POST_TYPE', 'ncrmnt_episode');
        define('SC_TAXONOMY', 'premium');
        define('SC_PARALLEL_MODE', false);
    }
}
add_action('plugins_loaded', 'sc_podcasts_init_mode', 5);

// Core includes - load after constants are defined
add_action('plugins_loaded', 'sc_podcasts_load_includes', 10);
function sc_podcasts_load_includes() {
    require_once SC_PODCASTS_PLUGIN_DIR . 'includes/class-sc-podcasts-cpt.php';
    require_once SC_PODCASTS_PLUGIN_DIR . 'includes/class-sc-podcasts-api.php';
    require_once SC_PODCASTS_PLUGIN_DIR . 'includes/class-sc-podcasts-sync.php';
    require_once SC_PODCASTS_PLUGIN_DIR . 'includes/class-sc-podcasts-admin.php';
    require_once SC_PODCASTS_PLUGIN_DIR . 'includes/class-sc-podcasts-migration.php';
}

// Activation/Deactivation
register_activation_hook(__FILE__, 'sc_podcasts_activate');
register_deactivation_hook(__FILE__, 'sc_podcasts_deactivate');

function sc_podcasts_activate() {
    // Define constants for activation
    if (!defined('SC_POST_TYPE')) {
        define('SC_POST_TYPE', 'sc_episode');
    }
    if (!defined('SC_TAXONOMY')) {
        define('SC_TAXONOMY', 'sc_premium');
    }
    
    // Load CPT class for activation
    require_once plugin_dir_path(__FILE__) . 'includes/class-sc-podcasts-cpt.php';
    
    SC_Podcasts_CPT::instance()->register_post_type();
    SC_Podcasts_CPT::instance()->register_taxonomy();
    flush_rewrite_rules();
    
    if (!wp_next_scheduled('sc_podcasts_sync_episodes')) {
        wp_schedule_event(time(), 'every_fifteen_minutes', 'sc_podcasts_sync_episodes');
    }
    
    update_option('sc_parallel_mode', true);
    set_transient('sc_podcasts_activated', true, 60);
}

function sc_podcasts_deactivate() {
    flush_rewrite_rules();
    wp_clear_scheduled_hook('sc_podcasts_sync_episodes');
}

// Custom cron schedule
add_filter('cron_schedules', 'sc_podcasts_cron_schedules');
function sc_podcasts_cron_schedules($schedules) {
    $schedules['every_fifteen_minutes'] = array(
        'interval' => 900,
        'display'  => __('Every 15 minutes', 'sc-podcasts')
    );
    return $schedules;
}

// Initialize components
add_action('plugins_loaded', function() {
    if (defined('SC_POST_TYPE') && defined('SC_TAXONOMY')) {
        SC_Podcasts_CPT::instance();
        SC_Podcasts_Admin::instance();
        SC_Podcasts_Sync::instance();
    }
}, 20);

// Handle redirects in transition mode
add_action('template_redirect', 'sc_podcasts_handle_redirects');
function sc_podcasts_handle_redirects() {
    if (!defined('SC_PARALLEL_MODE') || !SC_PARALLEL_MODE) {
        return;
    }
    
    global $wp_query;
    
    if (get_option('sc_enable_redirects') && is_singular('ncrmnt_episode')) {
        $post_id = get_the_ID();
        $sc_post_id = get_post_meta($post_id, '_sc_migrated_id', true);
        
        if ($sc_post_id) {
            $new_url = get_permalink($sc_post_id);
            wp_redirect($new_url, 301);
            exit;
        }
    }
}
