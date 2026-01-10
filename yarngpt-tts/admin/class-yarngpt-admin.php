<?php

class YarnGPT_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_admin_menu() {
        add_options_page(
            'YarnGPT TTS Settings',
            'YarnGPT TTS',
            'manage_options',
            'yarngpt-tts',
            array($this, 'settings_page_html')
        );
    }

    public function register_settings() {
        register_setting('yarngpt_tts_options', 'yarngpt_tts_allowed_levels');
    }

    public function settings_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('yarngpt_tts_messages', 'yarngpt_tts_message', __('Settings Saved', 'yarngpt-tts'), 'updated');
        }

        settings_errors('yarngpt_tts_messages');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('yarngpt_tts_options');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Allowed Membership Levels', 'yarngpt-tts'); ?></th>
                        <td>
                            <?php
                            if (function_exists('pmpro_getAllLevels')) {
                                $levels = pmpro_getAllLevels(true, true);
                                $allowed_levels = get_option('yarngpt_tts_allowed_levels', array());
                                if (!is_array($allowed_levels)) {
                                    $allowed_levels = array();
                                }

                                if (!empty($levels)) {
                                    foreach ($levels as $level) {
                                        ?>
                                        <label>
                                            <input type="checkbox" name="yarngpt_tts_allowed_levels[]" value="<?php echo esc_attr($level->id); ?>" <?php checked(in_array($level->id, $allowed_levels)); ?> />
                                            <?php echo esc_html($level->name); ?>
                                        </label><br>
                                        <?php
                                    }
                                } else {
                                    echo '<p>' . __('No membership levels found.', 'yarngpt-tts') . '</p>';
                                }
                            } else {
                                echo '<p class="description">' . __('Paid Memberships Pro is not active or no levels are defined.', 'yarngpt-tts') . '</p>';
                            }
                            ?>
                            <p class="description"><?php _e('Select the membership levels that are allowed to use the TTS feature. If none are selected, it might be open to everyone depending on your implementation preference.', 'yarngpt-tts'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }
}
