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
     */
    private array $reminder_offsets_days = [7, 1];

    private bool $remove_role_on_expiry = false;
    private string $role_to_remove = 'subscriber';

    private bool $also_call_ur_builtin_reminder_hook = true;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'bootstrap_ur_emails'], 20);
        add_action('init', [$this, 'schedule_daily']);
        add_action(self::CRON_HOOK, [$this, 'run_daily']);

        add_action('admin_post_ur_membership_debug_7day_window',[$this, 'admin_debug_7day_window']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_ur_membership_automation_run_now', [$this, 'admin_run_now']);
        add_action('admin_post_ur_membership_automation_send_test_email', [$this, 'admin_send_test_email']);
    }

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
            wp_schedule_event(time() + 300, 'daily', self::CRON_HOOK);
        }
        $this->maybe_create_log_table();
    }

    public function admin_debug_7day_window(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('ur_membership_debug_7day_window');

        $this->debug_users_who_should_get_7day_notice_next_7_days();

        wp_safe_redirect(
            admin_url('tools.php?page=ur-membership-automation&debug7=1')
        );
        exit;
    }


    private function debug_users_who_should_get_7day_notice_next_7_days(): void {
        global $wpdb;

        $days_before = 7;

        $tz = wp_timezone();
        $today = new DateTime('today', $tz);

        $from = (clone $today)->modify("+{$days_before} day")->format('Y-m-d');          // today+7
        $to   = (clone $today)->modify("+".($days_before + 6)." day")->format('Y-m-d'); // today+13

        $table = $this->subscriptions_table();

        $subs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                FROM {$table}
                WHERE DATE(next_billing_date) BETWEEN %s AND %s
                AND status = %s
                ORDER BY next_billing_date ASC",
                $from,
                $to,
                'active'
            ),
            ARRAY_A
        );

        if (empty($subs)) {
            error_log("URM 7DAY WINDOW: Niko nema isteke u periodu {$from} → {$to} (status=active).");
            return;
        }

        error_log("URM 7DAY WINDOW: Expiry range {$from} → {$to}. Kandidata (active) = " . count($subs));

        $eligible = 0;
        $already_sent = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $user_id = $this->get_user_id_from_subscription_row($sub);
            $next_billing_date = (string)($sub['next_billing_date'] ?? '');

            if ($user_id <= 0) {
                $skipped++;
                error_log("URM 7DAY WINDOW: SKIP (no user_id detected). next_billing_date={$next_billing_date}");
                continue;
            }

            $next_norm = $next_billing_date ? substr($next_billing_date, 0, 10) : '';
            if ($next_norm === '') {
                $skipped++;
                error_log("URM 7DAY WINDOW: SKIP (empty next_billing_date) user_id={$user_id}");
                continue;
            }

            $notice_day = (new DateTime($next_norm, $tz))->modify("-{$days_before} day")->format('Y-m-d');

            $meta_key = "ur_reminder_sent_{$days_before}d_for_date";
            $already  = (string) get_user_meta($user_id, $meta_key, true);

            $user = get_user_by('id', (int)$user_id);
            $email = ($user && !empty($user->user_email)) ? $user->user_email : '(email not found)';
            $login = ($user && !empty($user->user_login)) ? $user->user_login : '(login not found)';

            if ($already === $next_norm) {
                $already_sent++;
                error_log("URM 7DAY WINDOW: NOT ELIGIBLE (already sent) user_id={$user_id}, login={$login}, email={$email}, expiry={$next_billing_date}, 7day_notice_on={$notice_day}, meta={$meta_key}={$already}");
                continue;
            }

            $eligible++;
            error_log("URM 7DAY WINDOW: ✅ ELIGIBLE user_id={$user_id}, login={$login}, email={$email}, expiry={$next_billing_date}, 7day_notice_on={$notice_day}, meta={$meta_key}={$already}");
        }

        error_log("URM 7DAY WINDOW: Summary eligible={$eligible}, already_sent={$already_sent}, skipped={$skipped}, total=" . count($subs));
    }

    
    public function run_daily(): void {
        if ($this->is_locked()) {
            $this->log('lock', 0, '', 'Skipped: already running');
            return;
        }

        $this->lock();

        try {
            $this->maybe_create_log_table();

            // ✅ NEW: clear old logs so they don't repeat
            $this->clear_logs();

            $this->log('start', 0, '', 'Run started');

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

            $this->log('finish', 0, '', 'Run finished');

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
            // ✅ NEW: robust user id extraction (not only member_id)
            $user_id = $this->get_user_id_from_subscription_row($sub);
            if ($user_id <= 0) {
                $this->log('reminder_skip', 0, $target, 'Could not detect user id column/value in subscription row');
                continue;
            }

            $next_billing_date = (string)($sub['next_billing_date'] ?? '');
            $next_norm = $next_billing_date ? substr($next_billing_date, 0, 10) : $target;

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

            // ✅ NEW: sends to user + BCC admin
            $sent = $this->send_mail_with_admin_bcc($user->user_email, $subject, $message);

            if ($sent) {
                update_user_meta($user_id, $meta_key, $next_norm);
                $this->log('reminder_sent', $user_id, $next_norm, "Sent offset={$days_before} to {$user->user_email} (+admin BCC)");
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
            // ✅ NEW: robust user id extraction
            $user_id = $this->get_user_id_from_subscription_row($sub);
            if ($user_id <= 0) {
                $this->log('expired_skip', 0, $today, 'Could not detect user id column/value in subscription row');
                continue;
            }

            $next_billing_date = (string)($sub['next_billing_date'] ?? '');
            $next_norm = $next_billing_date ? substr($next_billing_date, 0, 10) : $today;

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

                // ✅ NEW: sends to user + BCC admin
                $sent = $this->send_mail_with_admin_bcc($user->user_email, $subject, $message);

                $this->log($sent ? 'expired_sent' : 'expired_fail', $user_id, $next_norm, 'Expired + email attempted (+admin BCC)');
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

        // keep SELECT * so we can detect id columns in row
        $sql = $wpdb->prepare(
            "SELECT *
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
            "SELECT *
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
     * Auto-detect ID column.
     */
    private function get_subscription_for_user(int $user_id): ?array {
        global $wpdb;
        $table = $this->subscriptions_table();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || empty($columns)) {
            $this->log('test_fail', $user_id, '', 'Could not read table columns');
            return null;
        }

        $candidates = ['member_id', 'user_id', 'customer_id', 'wp_user_id', 'user'];
        $id_col = null;
        foreach ($candidates as $c) {
            if (in_array($c, $columns, true)) {
                $id_col = $c;
                break;
            }
        }

        if (!$id_col) {
            $this->log('test_fail', $user_id, '', 'No user id column found. Columns: ' . implode(',', $columns));
            return null;
        }

        $order_by = in_array('id', $columns, true) ? 'ORDER BY id DESC' : '';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$id_col} = %d {$order_by} LIMIT 1",
            $user_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (is_array($row) && !empty($row)) {
            $this->log('test_debug', $user_id, '', "Matched using column={$id_col}");
            return $row;
        }

        $this->log('test_debug', $user_id, '', "No rows matched using column={$id_col}");
        return null;
    }

    /**
     * ✅ NEW: extract user id from a subscription row (no assumption it's member_id)
     */
    private function get_user_id_from_subscription_row(array $sub): int {
        foreach (['member_id', 'user_id', 'wp_user_id', 'customer_id', 'user'] as $k) {
            if (isset($sub[$k]) && is_numeric($sub[$k]) && (int)$sub[$k] > 0) {
                return (int)$sub[$k];
            }
        }
        return 0;
    }

    // =========================
    // Email templates
    // =========================
    private function render_reminder_email(WP_User $user, int $days_before, string $next_billing_date): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        return "Poštovani {$user->display_name},\n\n"
            . "Ovim putem Vas obaveštavamo da Vaše članstvo ističe za {$days_before} "
            . ($days_before === 1 ? 'dan' : 'dana') . ".\n\n"
            . "Datum isteka članstva: {$next_billing_date}\n\n"
            . "Ukoliko želite da produžite članstvo, molimo Vas da to učinite pre isteka.\n\n"
            . "Srdačan pozdrav,\n"
            . "{$site}\n";
    }

    private function render_expired_email(WP_User $user, string $next_billing_date): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        return "Poštovani {$user->display_name},\n\n"
            . "Obaveštavamo Vas da je Vaše članstvo isteklo.\n\n"
            . "Datum isteka članstva: {$next_billing_date}\n\n"
            . "Ukoliko želite da ponovo aktivirate članstvo, "
            . "molimo Vas da se prijavite na svoj nalog i izvršite obnovu.\n\n"
            . "Srdačan pozdrav,\n"
            . "{$site}\n";
    }


    private function render_test_email(WP_User $user, array $sub): string {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $lines = [];
        $lines[] = "Poštovani {$user->display_name},";
        $lines[] = "";
        $lines[] = "Ovo je TEST mejl za proveru sistema obaveštavanja o članstvu.";
        $lines[] = "Sistem je uspešno pronašao aktivnu subskripciju za Vaš nalog.";
        $lines[] = "";

        $lines[] = "Detalji subskripcije:";
        $fields = [
            'status' => 'Status',
            'next_billing_date' => 'Datum isteka',
            'start_date' => 'Datum početka',
            'created_at' => 'Kreirano',
            'membership_id' => 'ID članstva',
            'plan_id' => 'ID plana'
        ];

        foreach ($fields as $key => $label) {
            if (!empty($sub[$key])) {
                $lines[] = "- {$label}: {$sub[$key]}";
            }
        }

        $lines[] = "";
        $lines[] = "Ovaj mejl je informativnog karaktera.";
        $lines[] = "";
        $lines[] = "Srdačan pozdrav,";
        $lines[] = "{$site}";

        return implode("\n", $lines);
    }


    // =========================
    // ✅ NEW: Mail helper (user + admin BCC)
    // =========================

    private function send_mail_with_admin_bcc(string $to, string $subject, string $message): bool {
        $admin_email = get_option('admin_email');
        $headers = [];

        if (!empty($admin_email) && is_email($admin_email)) {
            $headers[] = 'Bcc: ' . $admin_email;
        }

        return wp_mail($to, $subject, $message, $headers);
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

    /**
     * ✅ NEW: clear logs so they don't repeat (TRUNCATE = fastest)
     */
    private function clear_logs(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        if (!$exists) return;

        $wpdb->query("TRUNCATE TABLE {$table}");
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

        // ✅ NEW: send to user + admin BCC
        $sent = $this->send_mail_with_admin_bcc($user->user_email, $subject, $message);

        $this->log($sent ? 'test_sent' : 'test_fail', $user_id, '', $sent ? 'Test email sent (+admin BCC)' : 'wp_mail failed');

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
            echo '<div class="notice notice-success"><p>Automation ran successfully (logs cleared, new logs added).</p></div>';
        }

        if ($test) {
            $msg = match ($test) {
                'sent'    => 'Test email sent (admin also received via BCC).',
                'fail'    => 'Test email FAILED (wp_mail returned false).',
                'no_sub'  => 'No subscription found for that user ID.',
                'no_user' => 'User not found or user has no email.',
                'bad_user'=> 'Invalid user ID.',
                default   => 'Test action finished.',
            };
            $cls = in_array($test, ['sent'], true) ? 'success' : 'warning';
            echo '<div class="notice notice-' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
        }
        $debug7_url = wp_nonce_url(
            admin_url('admin-post.php?action=ur_membership_debug_7day_window'),
            'ur_membership_debug_7day_window'
        );
        echo '<p><a class="button button-primary" href="' . esc_url($run_now_url) . '">Run now</a></p>';
        echo '<p><a class="button" href="' . esc_url($debug7_url) . '">🔍 Debug: ko dobija 7-day notice (narednih 7 dana)</a></p>';
        if (isset($_GET['debug7'])){
            echo '<div class="notice notice-info"><p>Debug za 7-day notice je izvršen. Proveri <code>error_log</code>.</p></div>';
        }
        echo '<h2>Send test email by User ID</h2>';
        echo '<form method="post" action="' . esc_url($send_test_action) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($send_test_nonce) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="ur_test_user_id">User ID</label></th>';
        echo '<td><input name="ur_test_user_id" id="ur_test_user_id" type="number" min="1" class="regular-text" required />';
        echo '<p class="description">Sends test email to user and BCC admin. Also prints useful subscription fields in email.</p></td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button">Send test email</button></p>';
        echo '</form>';

        echo '<h2>Status</h2>';
        echo '<ul>';
        echo '<li>UR hook registered (urm_daily_membership_renewal_check): <strong>' . (has_action('urm_daily_membership_renewal_check') ? 'YES' : 'NO') . '</strong></li>';
        echo '<li>Next run: <strong>' . esc_html($this->next_run_human()) . '</strong></li>';
        echo '<li>Subscriptions table: <code>' . esc_html($this->subscriptions_table()) . '</code></li>';
        echo '<li>Admin email (BCC): <code>' . esc_html(get_option('admin_email')) . '</code></li>';
        echo '</ul>';

        echo '<h2>Logs (last run only)</h2>';
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

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A);
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
            echo '<td>' . esc_html($r['message']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

new UR_Membership_Automation();
