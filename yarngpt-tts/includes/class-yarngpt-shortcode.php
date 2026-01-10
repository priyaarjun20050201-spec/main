<?php

class YarnGPT_Shortcode {

    public function init() {
        add_shortcode('yarngpt_tts', array($this, 'render_shortcode'));
    }

    public function render_shortcode($atts) {
        // Enqueue assets
        wp_enqueue_style('yarngpt-tts-style', YARNGPT_TTS_PLUGIN_URL . 'assets/css/style.css', array(), YARNGPT_TTS_VERSION);
        wp_enqueue_script('yarngpt-tts-script', YARNGPT_TTS_PLUGIN_URL . 'assets/js/script.js', array(), YARNGPT_TTS_VERSION, true);

        // Check PMPro access
        if (!$this->check_access()) {
            return $this->get_access_denied_message();
        }

        ob_start();
        ?>
        <div id="yarngpt-wrapper" class="yarngpt-wrapper">
            <div class="yarngpt-container">
                <h1 class="yarngpt-title">Nigerian Accent Text-to-Speech</h1>
                <p class="yarngpt-subtitle">Enter text to hear it in a natural Nigerian-accented English voice.</p>
                <textarea id="yarngpt-text-input" class="yarngpt-textarea" placeholder="Enter English text here..."></textarea>
                <div class="yarngpt-controls">
                    <button id="yarngpt-speak-btn" class="yarngpt-button">Speak</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function check_access() {
        // If PMPro is not active, return true (allow access)
        if (!function_exists('pmpro_hasMembershipLevel')) {
            return true;
        }

        $allowed_levels = get_option('yarngpt_tts_allowed_levels', array());

        // If no levels are set, assume it is open to everyone
        if (empty($allowed_levels)) {
            return true;
        }

        // Check if current user has any of the allowed levels
        if (pmpro_hasMembershipLevel($allowed_levels)) {
            return true;
        }

        return false;
    }

    private function get_access_denied_message() {
        $login_url = wp_login_url(get_permalink());
        return '<div class="yarngpt-tts-restricted">' .
               __('This content is restricted to members only. Please <a href="' . esc_url($login_url) . '">log in</a> or upgrade your membership.', 'yarngpt-tts') .
               '</div>';
    }
}
