<?php
/**
 * Plugin Name: WS40 Broadcaster
 * Plugin URI: https://github.com/pasqualinodemarco-create/ws40-broadcaster
 * Description: Bollettini meteorologici da WeatherStation40 verso Telegram, con selezione automatica della stazione.
 * Version: 1.3.2
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: WS40
 * Author URI: https://www.weatherstation40.live/
 * License: GPL-2.0-or-later
 * Text Domain: ws40-broadcaster
 * Domain Path: /languages
 */
if (!defined('ABSPATH')) { exit; }

define('WS40_BROADCASTER_FILE', __FILE__);
add_action('init', static function () {
    load_plugin_textdomain('ws40-broadcaster', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
spl_autoload_register(static function ($class) {
    $prefix = 'WS40\\Broadcaster\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) { return; }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) { require $file; }
});
// Compatibility aliases for integrations referencing the historical class names.
foreach (array('CMT_Telegram'=>'Telegram','CMT_Forecast'=>'Forecast','CMT_Webcam'=>'Webcam','CMT_Guide'=>'Guide','WS40B_Banner'=>'Banner') as $old => $new) {
    class_alias('WS40\\Broadcaster\\' . $new, $old);
}
add_action('admin_enqueue_scripts', array('WS40\Broadcaster\Telegram', 'admin_assets'));
\WS40\Broadcaster\Telegram::boot();
\WS40\Broadcaster\Forecast::boot();
\WS40\Broadcaster\Webcam::boot();
\WS40\Broadcaster\Banner::boot();
register_activation_hook(__FILE__, array('WS40\Broadcaster\Telegram', 'ensure_cron'));
register_deactivation_hook(__FILE__, array('WS40\Broadcaster\Telegram', 'deactivate'));
