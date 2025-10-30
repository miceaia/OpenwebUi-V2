<?php
/**
 * Plugin Name: OpenWebUI User Sync
 * Plugin URI: https://miceanou.com
 * Description: Sincroniza usuarios de WordPress con OpenWebUI de forma segura con sincronización bilateral.
 * Version: 1.9
 * Author: miceanou
 * Author URI: https://miceanou.com
 * Text Domain: openwebui-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
if (!defined('OPENWEBUI_SYNC_VERSION')) {
    define('OPENWEBUI_SYNC_VERSION', '1.9');
}

if (!defined('OPENWEBUI_SYNC_PLUGIN_FILE')) {
    define('OPENWEBUI_SYNC_PLUGIN_FILE', __FILE__);
}

if (!defined('OPENWEBUI_SYNC_PLUGIN_BASENAME')) {
    define('OPENWEBUI_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

if (!defined('OPENWEBUI_SYNC_PLUGIN_DIR')) {
    define('OPENWEBUI_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('OPENWEBUI_SYNC_PLUGIN_URL')) {
    define('OPENWEBUI_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('OPENWEBUI_MAX_SYNC_ATTEMPTS')) {
    define('OPENWEBUI_MAX_SYNC_ATTEMPTS', 3);
}

if (!defined('OPENWEBUI_API_TIMEOUT')) {
    define('OPENWEBUI_API_TIMEOUT', 10);
}

if (!defined('OPENWEBUI_MAX_RETRIES')) {
    define('OPENWEBUI_MAX_RETRIES', 2);
}

if (!defined('OPENWEBUI_RATE_LIMIT_WINDOW')) {
    define('OPENWEBUI_RATE_LIMIT_WINDOW', 60);
}

if (!defined('OPENWEBUI_MAX_REQUESTS_PER_WINDOW')) {
    define('OPENWEBUI_MAX_REQUESTS_PER_WINDOW', 10);
}

require_once OPENWEBUI_SYNC_PLUGIN_DIR . 'includes/class-openwebui-user-sync.php';
require_once OPENWEBUI_SYNC_PLUGIN_DIR . 'includes/micea-owui-course-mappings.php';

/**
 * Bootstrap the plugin once all plugins are loaded.
 */
function openwebui_user_sync_init() {
    OpenWebUI_User_Sync::get_instance();
}
add_action('plugins_loaded', 'openwebui_user_sync_init');

// ⭐ SEGURIDAD 32: Activación con validación.
register_activation_hook(__FILE__, 'openwebui_user_sync_activate');
/**
 * Plugin activation handler.
 */
function openwebui_user_sync_activate() {
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(OPENWEBUI_SYNC_PLUGIN_BASENAME);
        wp_die(esc_html__('Este plugin requiere PHP 7.2 o superior.', 'openwebui-sync'));
    }

    add_option('openwebui_api_url', '', '', 'no');
    add_option('openwebui_api_key', '', '', 'no');
    add_option('openwebui_send_emails', 'yes', '', 'no');
    add_option('openwebui_sync_logs', array(), '', 'no');

    flush_rewrite_rules();
}

// ⭐ SEGURIDAD 33: Desactivación limpia.
register_deactivation_hook(__FILE__, 'openwebui_user_sync_deactivate');
/**
 * Plugin deactivation handler.
 */
function openwebui_user_sync_deactivate() {
    global $wpdb;

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_syncing_user_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_syncing_user_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_openwebui_rate_limit_%'");

    flush_rewrite_rules();
}

// ⭐ SEGURIDAD 34: Desinstalación completa.
register_uninstall_hook(__FILE__, 'openwebui_user_sync_uninstall');
/**
 * Plugin uninstall handler.
 */
function openwebui_user_sync_uninstall() {
    delete_option('openwebui_api_url');
    delete_option('openwebui_api_key');
    delete_option('openwebui_send_emails');
    delete_option('openwebui_sync_logs');

    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_openwebui_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_syncing_user_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_syncing_user_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_openwebui_rate_limit_%'");
}
