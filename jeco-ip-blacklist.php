<?php
/*
Plugin Name: Jeco IP Blacklist
Description: Automatically updates .htaccess with the latest IP blacklist to block spam bots.
Version: 2.0.0
Author: Jesus Carrero
Requires PHP: 8.4
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    die('Kangaroos cannot jump here');
}

if (!class_exists('JECO_IPBL')) {

    /**
     * Class JECO_IPBL
     *
     * Handles all functionality for the Jeco IP Blacklist plugin.
     * Uses WordPress-native APIs for HTTP requests, .htaccess manipulation,
     * cron scheduling, and admin settings.
     */
    class JECO_IPBL
    {
        /**
         * Plugin version number.
         */
        public string $version = '2.0.0';

        /**
         * Marker used by insert_with_markers() to delimit the blacklist block in .htaccess.
         */
        private const HTACCESS_MARKER = 'Jeco IP Blacklist';

        /**
         * WP option key that stores the log entries.
         */
        private const OPTION_LOG = 'jeco_ipbl_log';

        /**
         * WP option key that stores the configured cron time.
         */
        private const OPTION_CRON_HOUR = 'jeco_ipbl_cron_hour';

        /**
         * WP cron hook name.
         */
        private const CRON_HOOK = 'jeco_ipbl_script';

        /**
         * Blacklist source URLs (provided by Myip.ms).
         */
        private const BLACKLIST_URL_MAIN = 'http://myip.ms/files/blacklist/htaccess/latest_blacklist.txt';
        private const BLACKLIST_URL_USERS = 'http://myip.ms/files/blacklist/htaccess/latest_blacklist_users_submitted.txt';

        /**
         * Maximum number of log entries to retain.
         */
        private const MAX_LOG_ENTRIES = 10;

        // -----------------------------------------------------------------------
        // Bootstrap
        // -----------------------------------------------------------------------

        public function __construct()
        {
            $this->define('JECO_IPBL', true);
            $this->define('JECO_IPBL_NAME', 'JECO_IP_BLACKLIST');
            $this->define('JECO_IPBL_VERSION', $this->version);

            $this->init_hooks();
        }

        /**
         * Register all hooks.
         */
        public function init_hooks(): void
        {
            register_activation_hook(__FILE__, [$this, 'wpcron_activation']);
            register_deactivation_hook(__FILE__, [$this, 'wpcron_deactivation']);

            add_action('admin_menu', [$this, 'add_menu_page']);
            add_action(self::CRON_HOOK, [$this, 'run_blacklist_update']);

            // Ajax handler for the "Update Now" button
            add_action('wp_ajax_jeco_ipbl_manual_update', [$this, 'ajax_manual_update']);
        }

        // -----------------------------------------------------------------------
        // Cron Scheduling
        // -----------------------------------------------------------------------

        /**
         * Activate: schedule the daily cron job.
         */
        public function wpcron_activation(): void
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                $cron_hour = get_option(self::OPTION_CRON_HOUR, '00:00');
                $timestamp = strtotime($cron_hour) ?: time();
                wp_schedule_event($timestamp, 'daily', self::CRON_HOOK);
            }
        }

        /**
         * Deactivate: remove the scheduled cron job.
         */
        public function wpcron_deactivation(): void
        {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }
        }

        /**
         * Reschedule the cron job with a new time.
         */
        private function reschedule_cron(string $cron_hour): void
        {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }

            $new_timestamp = strtotime($cron_hour) ?: time();
            wp_schedule_event($new_timestamp, 'daily', self::CRON_HOOK);
        }

        // -----------------------------------------------------------------------
        // Core: Blacklist Download & .htaccess Update
        // -----------------------------------------------------------------------

        /**
         * Entry point for the WP cron job hook.
         * Only runs in a proper cron context.
         */
        public function run_blacklist_update(): void
        {
            if (!defined('DOING_CRON') && !defined('DOING_AJAX')) {
                return;
            }

            $this->download_and_update_rules();
        }

        /**
         * Fetches the latest IP blacklists and writes them into .htaccess
         * using WordPress's insert_with_markers() for safe, delimited injection.
         *
         * @return array{success: bool, message: string}
         */
        private function download_and_update_rules(): array
        {
            // --- 1. Download main blacklist ---
            $main_response = wp_remote_get(self::BLACKLIST_URL_MAIN, [
                'timeout' => 30,
                'user-agent' => 'WordPress/Jeco-IP-Blacklist',
            ]);

            if (is_wp_error($main_response)) {
                return $this->log_result(false, 'Failed to download main blacklist: ' . $main_response->get_error_message());
            }

            $main_body = wp_remote_retrieve_body($main_response);
            if (empty($main_body)) {
                return $this->log_result(false, 'Main blacklist response was empty.');
            }

            // --- 2. Download user-submitted blacklist ---
            $users_response = wp_remote_get(self::BLACKLIST_URL_USERS, [
                'timeout' => 30,
                'user-agent' => 'WordPress/Jeco-IP-Blacklist',
            ]);

            $users_body = '';
            if (!is_wp_error($users_response)) {
                $users_body = wp_remote_retrieve_body($users_response);
            }

            // --- 3. Parse and combine the rules ---
            $rules = $this->parse_blacklist_rules($main_body, $users_body);

            if (empty($rules)) {
                return $this->log_result(false, 'No valid rules parsed from blacklist files.');
            }

            // --- 4. Backup current .htaccess ---
            $this->backup_htaccess();

            // --- 5. Write to .htaccess using WP's safe marker system ---
            $htaccess_file = get_home_path() . '.htaccess';

            if (!function_exists('insert_with_markers')) {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
            }

            $result = insert_with_markers($htaccess_file, self::HTACCESS_MARKER, $rules);

            if (!$result) {
                return $this->log_result(false, 'Could not write to .htaccess. Check file permissions.');
            }

            return $this->log_result(true, sprintf(
                '.htaccess updated successfully with %d rules from Myip.ms.',
                count($rules)
            ));
        }

        /**
         * Parses the raw text bodies from Myip.ms into an array of .htaccess directive lines.
         * Strips copyright/header comment blocks and keeps only the deny rules.
         *
         * @param string $main_body
         * @param string $users_body
         * @return string[]
         */
        private function parse_blacklist_rules(string $main_body, string $users_body): array
        {
            $rules = [];

            $bodies = array_filter([$main_body, $users_body]);

            foreach ($bodies as $body) {
                $lines = explode("\n", $body);
                foreach ($lines as $line) {
                    $line = trim($line);
                    // Keep only lines that are actual directives (deny from ...) or related
                    if (str_starts_with($line, 'deny from') || str_starts_with($line, 'Deny from')) {
                        $rules[] = $line;
                    }
                }
            }

            // Prepend the order directive so the block is self-contained
            if (!empty($rules)) {
                array_unshift($rules, 'order allow,deny');
                $rules[] = 'allow from all';
            }

            return $rules;
        }

        /**
         * Creates a timestamped backup of the current .htaccess file.
         */
        private function backup_htaccess(): void
        {
            $htaccess_file = get_home_path() . '.htaccess';

            if (!file_exists($htaccess_file)) {
                return;
            }

            $backup_file = get_home_path() . '.htaccess_jeco_backup_' . date('Ymd_His') . '.bak';
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @copy($htaccess_file, $backup_file);
        }

        // -----------------------------------------------------------------------
        // Logging
        // -----------------------------------------------------------------------

        /**
         * Records a result entry into the plugin log stored in WP options.
         *
         * @param bool   $success
         * @param string $message
         * @return array{success: bool, message: string}
         */
        private function log_result(bool $success, string $message): array
        {
            $log = get_option(self::OPTION_LOG, []);

            array_unshift($log, [
                'time' => current_time('mysql'),
                'success' => $success,
                'message' => $message,
            ]);

            // Keep only the most recent entries
            $log = array_slice($log, 0, self::MAX_LOG_ENTRIES);
            update_option(self::OPTION_LOG, $log);

            return ['success' => $success, 'message' => $message];
        }

        // -----------------------------------------------------------------------
        // Ajax Handler
        // -----------------------------------------------------------------------

        /**
         * Handles the manual "Update Now" AJAX action from the admin settings page.
         */
        public function ajax_manual_update(): void
        {
            check_ajax_referer('jeco_ipbl_manual_update_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
            }

            // Allow the core update to run even outside of a cron/ajax check
            if (!defined('DOING_AJAX')) {
                define('DOING_AJAX', true);
            }

            $result = $this->download_and_update_rules();

            if ($result['success']) {
                wp_send_json_success(['message' => esc_html($result['message'])]);
            } else {
                wp_send_json_error(['message' => esc_html($result['message'])]);
            }
        }

        // -----------------------------------------------------------------------
        // Admin Menu & Settings Page
        // -----------------------------------------------------------------------

        /**
         * Registers the admin menu page.
         */
        public function add_menu_page(): void
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            add_menu_page(
                'Jeco IP Blacklist',
                'Jeco Blacklist',
                'manage_options',
                'jeco-auto-ip-blacklist',
                [$this, 'display_settings_page'],
                'dashicons-shield-alt',
                100
            );
        }

        /**
         * Renders the settings page HTML.
         */
        public function display_settings_page(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            // Handle settings save
            if (isset($_POST['submit'])) {
                check_admin_referer('jeco_ipbl_settings_save');
                $cron_hour = sanitize_text_field($_POST['jeco_ipbl_cron_hour']);
                update_option(self::OPTION_CRON_HOUR, $cron_hour);
                $this->reschedule_cron($cron_hour);
                echo '<div class="notice notice-success is-dismissible"><p>Settings saved and cron job rescheduled.</p></div>';
            }

            $cron_hour = get_option(self::OPTION_CRON_HOUR, '00:00');
            $next_run = wp_next_scheduled(self::CRON_HOOK);
            $log = get_option(self::OPTION_LOG, []);
            $last_entry = $log[0] ?? null;

            $manual_nonce = wp_create_nonce('jeco_ipbl_manual_update_nonce');
            $ajax_url = admin_url('admin-ajax.php');

            ?>
                        <div class="wrap" id="jeco-ipbl-wrap">
                            <h1><span class="dashicons dashicons-shield-alt" style="font-size:28px;vertical-align:middle;margin-right:8px;"></span> Jeco IP Blacklist</h1>
                            <p class="description">Automatically downloads and injects the latest spam-bot IP blacklists from <a href="https://myip.ms/" target="_blank" rel="noopener">Myip.ms</a> into your <code>.htaccess</code> file.</p>
                            <hr>

                            <!-- Status Bar -->
                            <div style="display:flex;gap:24px;flex-wrap:wrap;margin:16px 0;">
                                <div class="jeco-status-card">
                                    <strong>Next Scheduled Run</strong><br>
                                    <?php echo $next_run
                                        ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s') . ' (local)')
                                        : '<span style="color:#d63638;">Not scheduled</span>'; ?>
                                </div>
                                <div class="jeco-status-card">
                                    <strong>Last Update</strong><br>
                                    <?php if ($last_entry): ?>
                                            <?php echo $last_entry['success']
                                                ? '<span style="color:#00a32a;">&#10003; Success</span>'
                                                : '<span style="color:#d63638;">&#10007; Failed</span>'; ?>
                                            &mdash; <?php echo esc_html($last_entry['time']); ?>
                                    <?php else: ?>
                                            <em>No updates run yet.</em>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Manual Update Button -->
                            <div style="margin-bottom:24px;">
                                <button id="jeco-manual-update" class="button button-primary" data-nonce="<?php echo esc_attr($manual_nonce); ?>" data-ajax="<?php echo esc_url($ajax_url); ?>">
                                    <span class="dashicons dashicons-update" style="vertical-align:middle;"></span> Update Now
                                </button>
                                <span id="jeco-update-status" style="margin-left:12px;font-style:italic;"></span>
                            </div>

                            <!-- Settings Form -->
                            <form method="POST" action="">
                                <?php wp_nonce_field('jeco_ipbl_settings_save'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="jeco_ipbl_cron_hour">Cron Job Start Time (HH:MM)</label></th>
                                        <td>
                                            <input type="time" id="jeco_ipbl_cron_hour" name="jeco_ipbl_cron_hour" value="<?php echo esc_attr($cron_hour); ?>" />
                                            <p class="description">The time each day when the blacklist will be automatically downloaded and applied.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit"><input type="submit" name="submit" class="button-primary" value="Save Changes"></p>
                            </form>

                            <hr>

                            <!-- Activity Log -->
                            <h2>Activity Log <span style="font-size:13px;font-weight:normal;">(last <?php echo self::MAX_LOG_ENTRIES; ?> runs)</span></h2>
                            <?php if (empty($log)): ?>
                                    <p><em>No activity recorded yet.</em></p>
                            <?php else: ?>
                                    <table class="widefat striped" style="max-width:800px;">
                                        <thead>
                                            <tr>
                                                <th>Date / Time</th>
                                                <th>Status</th>
                                                <th>Message</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($log as $entry): ?>
                                                    <tr>
                                                        <td><?php echo esc_html($entry['time']); ?></td>
                                                        <td>
                                                            <?php echo $entry['success']
                                                                ? '<span style="color:#00a32a;font-weight:600;">&#10003; Success</span>'
                                                                : '<span style="color:#d63638;font-weight:600;">&#10007; Failed</span>'; ?>
                                                        </td>
                                                        <td><?php echo esc_html($entry['message']); ?></td>
                                                    </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                            <?php endif; ?>
                        </div>

                        <style>
                            .jeco-status-card {
                                background: #fff;
                                border: 1px solid #c3c4c7;
                                border-radius: 4px;
                                padding: 12px 20px;
                                min-width: 220px;
                                line-height: 2;
                            }
                        </style>

                        <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const btn    = document.getElementById('jeco-manual-update');
                            const status = document.getElementById('jeco-update-status');

                            btn.addEventListener('click', function () {
                                btn.disabled = true;
                                status.textContent = 'Running update…';
                                status.style.color = '#555';

                                const formData = new FormData();
                                formData.append('action', 'jeco_ipbl_manual_update');
                                formData.append('nonce', btn.dataset.nonce);

                                fetch(btn.dataset.ajax, { method: 'POST', body: formData })
                                    .then(res => res.json())
                                    .then(data => {
                                        if (data.success) {
                                            status.textContent = '✔ ' + data.data.message;
                                            status.style.color = '#00a32a';
                                        } else {
                                            status.textContent = '✘ ' + data.data.message;
                                            status.style.color = '#d63638';
                                        }
                                    })
                                    .catch(() => {
                                        status.textContent = '✘ An unexpected error occurred.';
                                        status.style.color = '#d63638';
                                    })
                                    .finally(() => {
                                        btn.disabled = false;
                                        // Reload the log table after a short delay
                                        setTimeout(() => location.reload(), 2500);
                                    });
                            });
                        });
                        </script>
                        <?php
        }

        // -----------------------------------------------------------------------
        // Utility
        // -----------------------------------------------------------------------

        /**
         * Safely defines a constant.
         *
         * @param string $name
         * @param mixed  $value
         */
        public function define(string $name, mixed $value = true): void
        {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    /**
     * Returns the singleton instance of JECO_IPBL.
     */
    function jeco_ipbl(): JECO_IPBL
    {
        global $jeco_ipbl;

        if (!isset($jeco_ipbl)) {
            $jeco_ipbl = new JECO_IPBL();
        }

        return $jeco_ipbl;
    }

    // Bootstrap the plugin
    jeco_ipbl();
}
