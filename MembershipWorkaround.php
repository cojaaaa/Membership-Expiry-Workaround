<?php
/**
 * Plugin Name: Workaround za istek - NE DIRAJ!!!!!!!
 * Description: 
 * Author: cojv
 * Author URI: https://github.com/cojaaaa
 */


if (!defined('ABSPATH')) exit;

class UR_Membership_Automation {
    const CRON_HOOK = 'ur_membership_automation_daily';
    const LOCK_KEY  = 'ur_membership_automation_lock';
    const LOG_TABLE_SUFFIX = 'ur_membership_cron_log';

    /**
     * CONFIG
     * - Reminders will be sent when DATE(next_billing_date) == today + offset
     * - Example: [7, 1] means send 7 days before and 1 day before
     */
    private array $reminder_offsets_days = [7, 1];

    /**
     * On expiry (DATE(next_billing_date) < today) we mark usermeta as expired
     * Optional role removal is OFF by default.
     */
    private bool $remove_role_on_expiry = false;
    private string $role_to_remove = 'subscriber';

    /**
     * Also call UR built-in reminder hook (uses UR templates/settings) — ON by default.
     * It will only send if option user_registration_membership_renewal_reminder_user_email is enabled.
     */
    private bool $also_call_ur_builtin_reminder_hook = true;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'bootstrap_ur_emails'], 20);
        add_action('init', [$this, 'schedule_daily']);
        add_action(self::CRON_HOOK, [$this, 'run_daily']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_ur_membership_automation_run_now', [$this, 'admin_run_now']);
        add_action('admin_post_ur_membership_automation_send_test_email', [$this, 'admin_send_test_email']);
    }

    /**
     * Fix for your setup: EmailSettings exists but isn't instantiated automatically,
     * so the hook urm_daily_membership_renewal_check has no callbacks.
     */
    public function bootstrap_ur_emails(): void {
        if (class_exists('\WPEverest\URMembership\Emails\EmailSettings')) {
            static $booted = false;
            if (!$booted) {
                new \WPEverest\URMembership\Emails\EmailSettings();
                $booted = true;
            }
        }
    }

    public function schedule_daily(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON_HOOK); // +5 minutes then daily
        }
        $this->maybe_create_log_table();
    }

    public function run_daily(): void {
        if ($this->is_locked()) {
            $this->log('lock', 0, '', 'Skipped: already running');
            return;
        }
        $this->lock();

        try {
            $this->maybe_create_log_table();

            // 1) Call UR built-in reminder logic (optional)
            if ($this->also_call_ur_builtin_reminder_hook) {
                do_action('urm_daily_membership_renewal_check');
                $this->log('builtin_hook', 0, '', 'Called do_action(urm_daily_membership_renewal_check)');
            }

            // 2) Custom multi-reminders (7 days, 1 day, etc.)
            foreach ($this->reminder_offsets_days as $offset_days) {
                $this->send_reminders_for_offset((int)$offset_days);
            }

            // 3) Expire memberships past due
            $this->expire_past_due_memberships();

        } catch (\Throwable $e) {
            $this->log('error', 0, '', 'Exception: ' . $e->getMessage());
        } finally {
            $this->unlock();
        }
    }

    private function send_reminders_for_offset(int $days_before): void {
        if ($days_before <= 0) return;

        $target = (new DateTime('today', wp_timezone()))
            ->modify("+{$days_before} day")
            ->format('Y-m-d');

        $subs = $this->get_subscriptions_by_next_date($target, 'active');

        if (empty($subs)) {
            $this->log('reminder_none', 0, $target, "No active subscriptions for offset={$days_before}");
            return;
        }

        foreach ($subs as $sub) {
            $user_id = (int)$sub['member_id'];
            $next_billing_date = (string)$sub['next_billing_date'];
            $next_norm = substr($next_billing_date, 0, 10);

            // Dedup per offset
            $meta_key = "ur_reminder_sent_{$days_before}d_for_date";
            $already = (string)get_user_meta($user_id, $meta_key, true);
            if ($already === $next_norm) {
                continue;
            }

            $user = get_user_by('id', $user_id);
            if (!$user || empty($user->user_email)) {
                $this->log('reminder_skip', $user_id, $next_norm, "No user/email");
                continue;
            }

            $subject = sprintf('Your membership renews in %d day(s)', $days_before);
            $message = $this->render_reminder_email($user, $days_before, $next_billing_date);

            $sent = wp_mail($user->user_email, $subject, $message);

            if ($sent) {
                update_user_meta($user_id, $meta_key, $next_norm);
                $this->log('reminder_sent', $user_id, $next_norm, "Sent offset={$days_before} to {$user->user_email}");
            } else {
                $this->log('reminder_fail', $user_id, $next_norm, "wp_mail failed offset={$days_before}");
            }
        }
    }

    private function expire_past_due_memberships(): void {
        $today = (new DateTime('today', wp_timezone()))->format('Y-m-d');

        $subs = $this->get_subscriptions_before_date($today, 'active');
        if (empty($subs)) {
            $this->log('expire_none', 0, $today, 'No past-due active subscriptions');
            return;
        }

        foreach ($subs as $sub) {
            $user_id = (int)$sub['member_id'];
            $next_billing_date = (string)$sub['next_billing_date'];
            $next_norm = substr($next_billing_date, 0, 10);

            // Dedup expiry once per billing date
            $meta_key = 'ur_membership_expired_for_date';
            $already = (string)get_user_meta($user_id, $meta_key, true);
            if ($already === $next_norm) continue;

            // Mark expired (non-destructive)
            update_user_meta($user_id, 'ur_membership_status', 'expired');
            update_user_meta($user_id, $meta_key, $next_norm);

            // Optional role removal
            if ($this->remove_role_on_expiry) {
                $user = get_user_by('id', $user_id);
                if ($user instanceof WP_User) {
                    if (in_array($this->role_to_remove, (array)$user->roles, true)) {
                        $user->remove_role($this->role_to_remove);
                    }
                }
            }

            // Send expiry email
            $user = get_user_by('id', $user_id);
            if ($user && !empty($user->user_email)) {
                $subject = 'Your membership has expired';
                $message = $this->render_expired_email($user, $next_billing_date);
                $sent = wp_mail($user->user_email, $subject, $message);
                $this->log($sent ? 'expired_sent' : 'expired_fail', $user_id, $next_norm, 'Expired + email attempted');
            } else {
                $this->log('expired_marked', $user_id, $next_norm, 'Expired marked (no email)');
            }
        }
    }

    // =========================
    // DB access (CONFIRMED TABLE)
    // =========================

    private function subscriptions_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ur_membership_subscriptions';
    }

    private function get_subscriptions_by_next_date(string $date_ymd, string $status): array {
        global $wpdb;
        $table = $this->subscriptions_table();

        $sql = $wpdb->prepare(
            "SELECT member_id, next_billing_date, status
             FROM {$table}
             WHERE DATE(next_billing_date) = %s
               AND status = %s",
            $date_ymd,
            $status
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function get_subscriptions_before_date(string $date_ymd, string $status): array {
        global $wpdb;
        $table = $this->subscriptions_table();

        $sql = $wpdb->prepare(
            "SELECT member_id, next_billing_date, status
             FROM {$table}
             WHERE DATE(next_billing_date) < %s
               AND status = %s",
            $date_ymd,
            $status
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Fetch a subscription record for a given user ID.
     * We try "active" first, then any status if none found.
     */
    private function get_subscription_for_user(int $user_id): ?array {
        global $wpdb;
        $table = $this->subscriptions_table();

        // Try active first
        $sql_active = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE member_id = %d
             ORDER BY id DESC
             LIMIT 1",
            $user_id
        );

        // Some schemas may not have "id". If this fails, fallback to no ORDER BY.
        $row = $wpdb->get_row($sql_active, ARRAY_A);

        if (is_array($row) && !empty($row)) {
            return $row;
        }

        $sql_fallback = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE member_id = %d
             LIMIT 1",
            $user_id
        );
        $row2 = $wpdb->get_row($sql_fallback, ARRAY_A);

        return (is_array($row2) && !empty($row2)) ? $row2 : null;
    }

    // =========================
    // Email templates
    // =========================

    private function render_reminder_email(WP_User $user, int $days_before, string $next_billing_date): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return "Hello {$user->display_name},\n\n"
            . "This is a test/automation reminder email.\n"
            . "Next billing/expiry date recorded in the system: {$next_billing_date}\n"
            . "Reminder offset: {$days_before} day(s)\n\n"
            . "— {$site}\n";
    }

    private function render_expired_email(WP_User $user, string $next_billing_date): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return "Hello {$user->display_name},\n\n"
            . "Your membership is marked as expired (billing date was: {$next_billing_date}).\n\n"
            . "— {$site}\n";
    }

    private function render_test_email(WP_User $user, array $sub): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $lines = [];

        $lines[] = "Hello {$user->display_name},";
        $lines[] = "";
        $lines[] = "This is a TEST email from UR Membership Automation.";
        $lines[] = "We verified that your user has a subscription record.";
        $lines[] = "";

        // Print some useful fields if present
        $interesting = [
            'member_id', 'status', 'plan_id', 'membership_id',
            'start_date', 'created_at', 'expires_at', 'expiry_date',
            'next_billing_date', 'end_date'
        ];

        $lines[] = "Subscription fields:";
        foreach ($interesting as $k) {
            if (array_key_exists($k, $sub) && $sub[$k] !== null && $sub[$k] !== '') {
                $lines[] = "- {$k}: {$sub[$k]}";
            }
        }

        $lines[] = "";
        $lines[] = "— {$site}";

        return implode("\n", $lines);
    }

    // =========================
    // Logging
    // =========================

    private function maybe_create_log_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        if ($exists) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            action VARCHAR(50) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ref_date VARCHAR(20) NOT NULL DEFAULT '',
            message TEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY action (action),
            KEY user_id (user_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private function log(string $action, int $user_id, string $ref_date, string $message): void {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        if (!$exists) return;

        $wpdb->insert($table, [
            'created_at' => current_time('mysql'),
            'action'     => $action,
            'user_id'    => $user_id,
            'ref_date'   => $ref_date,
            'message'    => $message,
        ]);
    }

    // =========================
    // Locking
    // =========================

    private function is_locked(): bool {
        return (bool) get_transient(self::LOCK_KEY);
    }

    private function lock(): void {
        set_transient(self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS);
    }

    private function unlock(): void {
        delete_transient(self::LOCK_KEY);
    }

    // =========================
    // Admin UI + Actions
    // =========================

    public function admin_menu(): void {
        add_management_page(
            'UR Membership Automation',
            'UR Membership Automation',
            'manage_options',
            'ur-membership-automation',
            [$this, 'admin_page']
        );
    }

    public function admin_run_now(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ur_membership_automation_run_now');

        $this->run_daily();

        wp_safe_redirect(admin_url('tools.php?page=ur-membership-automation&ran=1'));
        exit;
    }

    public function admin_send_test_email(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ur_membership_automation_send_test_email');

        $user_id = isset($_POST['ur_test_user_id']) ? (int) $_POST['ur_test_user_id'] : 0;

        if ($user_id <= 0) {
            wp_safe_redirect(admin_url('tools.php?page=ur-membership-automation&test=bad_user'));
            exit;
        }

        $user = get_user_by('id', $user_id);
        if (!$user || empty($user->user_email)) {
            $this->log('test_skip', $user_id, '', 'User not found or no email');
            wp_safe_redirect(admin_url('tools.php?page=ur-membership-automation&test=no_user'));
            exit;
        }

        $sub = $this->get_subscription_for_user($user_id);
        if (!$sub) {
            $this->log('test_skip', $user_id, '', 'No subscription found in table');
            wp_safe_redirect(admin_url('tools.php?page=ur-membership-automation&test=no_sub'));
            exit;
        }

        $subject = '[TEST] Membership automation email';
        $message = $this->render_test_email($user, $sub);

        $sent = wp_mail($user->user_email, $subject, $message);

        $this->log($sent ? 'test_sent' : 'test_fail', $user_id, '', $sent ? 'Test email sent' : 'wp_mail failed');

        wp_safe_redirect(admin_url('tools.php?page=ur-membership-automation&test=' . ($sent ? 'sent' : 'fail')));
        exit;
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;

        $ran  = isset($_GET['ran']) ? (int)$_GET['ran'] : 0;
        $test = isset($_GET['test']) ? sanitize_text_field(wp_unslash($_GET['test'])) : '';

        $run_now_url = wp_nonce_url(
            admin_url('admin-post.php?action=ur_membership_automation_run_now'),
            'ur_membership_automation_run_now'
        );

        $send_test_action = admin_url('admin-post.php?action=ur_membership_automation_send_test_email');
        $send_test_nonce  = wp_create_nonce('ur_membership_automation_send_test_email');

        echo '<div class="wrap">';
        echo '<h1>UR Membership Automation</h1>';

        if ($ran) {
            echo '<div class="notice notice-success"><p>Automation ran successfully.</p></div>';
        }

        if ($test) {
            $msg = match ($test) {
                'sent'    => 'Test email sent.',
                'fail'    => 'Test email FAILED (wp_mail returned false).',
                'no_sub'  => 'No subscription found for that user ID.',
                'no_user' => 'User not found or user has no email.',
                'bad_user'=> 'Invalid user ID.',
                default   => 'Test action finished.',
            };
            $cls = in_array($test, ['sent'], true) ? 'success' : 'warning';
            echo '<div class="notice notice-' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
        }

        echo '<p><a class="button button-primary" href="' . esc_url($run_now_url) . '">Run now</a></p>';

        echo '<h2>Send test email by User ID</h2>';
        echo '<form method="post" action="' . esc_url($send_test_action) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($send_test_nonce) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="ur_test_user_id">User ID</label></th>';
        echo '<td><input name="ur_test_user_id" id="ur_test_user_id" type="number" min="1" class="regular-text" required />';
        echo '<p class="description">Enter a WordPress user ID. The plugin will check if a subscription exists in <code>' . esc_html($this->subscriptions_table()) . '</code> and send a test email to the user.</p></td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button">Send test email</button></p>';
        echo '</form>';

        echo '<h2>Status</h2>';
        echo '<ul>';
        echo '<li>UR hook registered (urm_daily_membership_renewal_check): <strong>' . (has_action('urm_daily_membership_renewal_check') ? 'YES' : 'NO') . '</strong></li>';
        echo '<li>Next run: <strong>' . esc_html($this->next_run_human()) . '</strong></li>';
        echo '<li>Subscriptions table: <code>' . esc_html($this->subscriptions_table()) . '</code></li>';
        echo '</ul>';

        echo '<h2>Recent logs</h2>';
        $this->render_logs_table();

        echo '</div>';
    }

    private function next_run_human(): string {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if (!$ts) return 'Not scheduled';
        return date_i18n('Y-m-d H:i:s', $ts);
    }

    private function render_logs_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        if (!$exists) {
            echo '<p>Log table not created yet.</p>';
            return;
        }

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 50", ARRAY_A);
        if (empty($rows)) {
            echo '<p>No logs yet.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Time</th><th>Action</th><th>User</th><th>Ref date</th><th>Message</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $user = $r['user_id'] ? get_user_by('id', (int)$r['user_id']) : null;
            $user_label = $user ? esc_html($user->user_login . " (#{$r['user_id']})") : ($r['user_id'] ? ("#".(int)$r['user_id']) : '-');

            echo '<tr>';
            echo '<td>' . esc_html($r['created_at']) . '</td>';
            echo '<td>' . esc_html($r['action']) . '</td>';
            echo '<td>' . $user_label . '</td>';
            echo '<td>' . esc_html($r['ref_date']) . '</td>';
            echo '<td>' . esc_html(wp_trim_words((string)$r['message'], 20)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

new UR_Membership_Automation();