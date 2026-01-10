<?php
/**
 * Plugin Name: YarnGPT TTS
 * Plugin URI: https://example.com/yarngpt-tts
 * Description: A Nigerian Accent Text-to-Speech plugin compatible with Paid Memberships Pro.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: yarngpt-tts
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YARNGPT_TTS_VERSION', '1.0.0');
define('YARNGPT_TTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YARNGPT_TTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once YARNGPT_TTS_PLUGIN_DIR . 'admin/class-yarngpt-admin.php';
require_once YARNGPT_TTS_PLUGIN_DIR . 'includes/class-yarngpt-shortcode.php';

// Initialize the plugin
function yarngpt_tts_init() {
    $admin = new YarnGPT_Admin();
    $admin->init();

    $shortcode = new YarnGPT_Shortcode();
    $shortcode->init();
}
add_action('plugins_loaded', 'yarngpt_tts_init');
