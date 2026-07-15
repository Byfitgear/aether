<?php
/**
 * Contact Form Service for Aether
 *
 * @package Aether
 */

namespace Aether\Services;

class Aether_Contact_Form_Service {

    const TABLE_NAME = 'wp_aether_contact_forms';
    const OPTION_NAME = 'aether_contact_forms';

    /**
     * Activate: create table if needed.
     */
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(150) NOT NULL,
            subject varchar(255) NOT NULL,
            message text NOT NULL,
            ip varchar(45) DEFAULT '' NOT NULL,
            user_agent varchar(500) DEFAULT '' NOT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email(100)),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Default settings
        if (get_option(self::OPTION_NAME) === false) {
            add_option(self::OPTION_NAME, [
                'to_email' => get_option('admin_email'),
                'from_name' => get_option('blogname'),
                'success_message' => 'Thank you! Your message has been sent successfully.',
                'honeypet_field' => '',
                'enabled' => true,
            ]);
        }
    }

    /**
     * Deactivate: optionally clean up.
     */
    public static function deactivate() {
        // Keep data on deactivation
    }

    /**
     * Get settings.
     */
    public static function get_settings() {
        return get_option(self::OPTION_NAME, []);
    }

    /**
     * Save settings.
     */
    public static function save_settings($settings) {
        update_option(self::OPTION_NAME, $settings);
    }

    /**
     * Submit a contact form entry.
     */
    public static function submit($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $defaults = [
            'name'         => '',
            'email'        => '',
            'subject'      => '',
            'message'      => '',
            'honeypet'     => '',
        ];
        $data = wp_parse_args($data, $defaults);

        // Honeypet spam check
        if (!empty($data['honeypet'])) {
            return ['success' => false, 'message' => 'Spam detected.'];
        }

        // Validate
        if (empty($data['name']) || empty($data['email']) || empty($data['message'])) {
            return ['success' => false, 'message' => 'Please fill in all required fields.'];
        }

        if (!is_email($data['email'])) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }

        $settings = self::get_settings();
        $to_email = !empty($settings['to_email']) ? $settings['to_email'] : get_option('admin_email');

        $headers = [
            'From: ' . sanitize_text_field($data['name']) . ' <' . sanitize_email($data['email']) . '>',
            'Reply-To: ' . sanitize_email($data['email']),
            'Content-Type: text/html; charset=UTF-8',
        ];

        $subject = sanitize_text_field($data['subject']) ?: 'New contact form submission';
        $body = sprintf(
            '<h3>New Contact Form Submission</h3>
            <p><strong>Name:</strong> %s</p>
            <p><strong>Email:</strong> %s</p>
            <p><strong>Subject:</strong> %s</p>
            <p><strong>Message:</strong></p>
            <p>%s</p>',
            esc_html($data['name']),
            esc_html($data['email']),
            esc_html($subject),
            nl2br(esc_html($data['message']))
        );

        wp_mail($to_email, $subject, $body, $headers);

        // Store in database
        $wpdb->insert(
            $table_name,
            [
                'name'         => sanitize_text_field($data['name']),
                'email'        => sanitize_email($data['email']),
                'subject'      => sanitize_text_field($data['subject']),
                'message'      => wp_kses_post($data['message']),
                'ip'           => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent'   => sanitize_text_field(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)),
                'is_read'      => 0,
            ]
        );

        return ['success' => true, 'message' => $settings['success_message'] ?? 'Thank you! Your message has been sent.'];
    }

    /**
     * Get submissions (paginated).
     */
    public static function get_submissions($page = 1, $per_page = 20, $filter_read = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where = '1=1';
        if ($filter_read === 'unread') {
            $where .= ' AND is_read = 0';
        } elseif ($filter_read === 'read') {
            $where .= ' AND is_read = 1';
        }

        $offset = ($page - 1) * $per_page;
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE {$where}");
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        return ['data' => $rows, 'total' => (int) $total, 'page' => $page];
    }

    /**
     * Mark as read.
     */
    public static function mark_read($id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE_NAME,
            ['is_read' => 1],
            ['id' => (int) $id]
        );
    }

    /**
     * Delete submission.
     */
    public static function delete_submission($id) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            ['id' => (int) $id]
        );
    }

    /**
     * Shortcode: [aether_contact_form]
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'title' => 'Contact Us',
            'placeholder_name' => 'Your Name',
            'placeholder_email' => 'Your Email',
            'placeholder_subject' => 'Subject',
            'placeholder_message' => 'Your Message',
            'button_text' => 'Send Message',
        ], $atts, 'aether_contact_form');

        // Handle submission
        $output = '';
        if (isset($_POST['aether_contact_nonce']) &&
            isset($_POST['aether_contact_action']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aether_contact_nonce'])), 'aether_contact_action')) {

            $result = self::submit([
                'name'       => wp_unslash($_POST['cf_name'] ?? ''),
                'email'      => wp_unslash($_POST['cf_email'] ?? ''),
                'subject'    => wp_unslash($_POST['cf_subject'] ?? ''),
                'message'    => wp_unslash($_POST['cf_message'] ?? ''),
                'honeypet'   => wp_unslash($_POST['cf_honeypet'] ?? ''),
            ]);

            if ($result['success']) {
                $output .= '<div class="aether-contact-success">' . esc_html($result['message']) . '</div>';
            } else {
                $output .= '<div class="aether-contact-error">' . esc_html($result['message']) . '</div>';
            }
        }

        $nonce = wp_create_nonce('aether_contact_action');
        $honeypet_field = self::get_settings()['honeypet_field'] ?? '';

        $output .= '<form class="aether-contact-form" method="post" action="">';
        $output .= '<input type="hidden" name="aether_contact_nonce" value="' . esc_attr($nonce) . '">';
        $output .= '<input type="hidden" name="aether_contact_action" value="submit">';

        if (!empty($honeypet_field)) {
            $output .= '<input type="text" name="cf_honeypet" style="display:none!important" tabindex="-1" autocomplete="off">';
        }

        $output .= '<div class="aether-contact-title">' . esc_html($atts['title']) . '</div>';
        $output .= '<div class="aether-contact-row">';
        $output .= '<input type="text" name="cf_name" placeholder="' . esc_attr($atts['placeholder_name']) . '" required value="' . esc_attr($_POST['cf_name'] ?? '') . '">';
        $output .= '<input type="email" name="cf_email" placeholder="' . esc_attr($atts['placeholder_email']) . '" required value="' . esc_attr($_POST['cf_email'] ?? '') . '">';
        $output .= '</div>';
        $output .= '<div class="aether-contact-row">';
        $output .= '<input type="text" name="cf_subject" placeholder="' . esc_attr($atts['placeholder_subject']) . '" value="' . esc_attr($_POST['cf_subject'] ?? '') . '">';
        $output .= '</div>';
        $output .= '<textarea name="cf_message" rows="6" placeholder="' . esc_attr($atts['placeholder_message']) . '" required>' . esc_textarea($_POST['cf_message'] ?? '') . '</textarea>';
        $output .= '<button type="submit">' . esc_html($atts['button_text']) . '</button>';
        $output .= '</form>';

        return $output;
    }

    /**
     * Admin settings page.
     */
    public static function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $settings = self::get_settings();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aether_contact_save'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aether_contact_settings_nonce'] ?? '')), 'aether_contact_settings')) {
                wp_die('Security check failed.');
            }
            $settings['to_email'] = sanitize_email(wp_unslash($_POST['cf_to_email'] ?? ''));
            $settings['from_name'] = sanitize_text_field(wp_unslash($_POST['cf_from_name'] ?? ''));
            $settings['success_message'] = sanitize_textarea_field(wp_unslash($_POST['cf_success_message'] ?? ''));
            $settings['honeypet_field'] = !empty($_POST['cf_honeypet_enable']) ? '1' : '';
            $settings['enabled'] = isset($_POST['cf_enabled']);
            self::save_settings($settings);
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $nonce = wp_create_nonce('aether_contact_settings');
        ?>
        <div class="wrap">
            <h1>Contact Form Settings</h1>
            <form method="post">
                <?php wp_nonce_field('aether_contact_settings', 'aether_contact_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="cf_enabled">Enabled</label></th>
                        <td><input type="checkbox" id="cf_enabled" name="cf_enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="cf_to_email">Receive Emails To</label></th>
                        <td><input type="email" id="cf_to_email" name="cf_to_email" value="<?php echo esc_attr($settings['to_email'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="cf_from_name">Sender Name</label></th>
                        <td><input type="text" id="cf_from_name" name="cf_from_name" value="<?php echo esc_attr($settings['from_name'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="cf_success_message">Success Message</label></th>
                        <td><textarea id="cf_success_message" name="cf_success_message" rows="3" class="large-text"><?php echo esc_textarea($settings['success_message'] ?? ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="cf_honeypet_enable">Honeypet Spam Protection</label></th>
                        <td><input type="checkbox" id="cf_honeypet_enable" name="cf_honeypet_enable" value="1" <?php checked(!empty($settings['honeypet_field'])); ?>> <span class="description">Hidden field to catch bots</span></td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="aether_contact_save" value="Save Settings" class="button button-primary"></p>
            </form>
            <hr>
            <h2>Shortcode</h2>
            <p>Use this shortcode in any page or post:</p>
            <p><code>[aether_contact_form]</code></p>
            <h2>Submissions</h2>
            <?php self::admin_submissions_list(); ?>
        </div>
        <?php
    }

    /**
     * List submissions in admin.
     */
    private static function admin_submissions_list() {
        global $pagenow;
        if ($pagenow !== 'options-general.php') return;

        $page = isset($_GET['cf_page']) ? max(1, (int) $_GET['cf_page']) : 1;
        $filter = $_GET['cf_filter'] ?? '';
        $result = self::get_submissions($page, 20, $filter);

        if (!$result['data']) {
            echo '<p>No submissions yet.</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>Name</th><th>Email</th><th>Subject</th><th>Date</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($result['data'] as $row) {
            $status = $row->is_read ? '' : 'style="font-weight:bold;"';
            echo '<tr ' . $status . '>';
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>' . esc_html($row->email) . '</td>';
            echo '<td>' . esc_html($row->subject) . '</td>';
            echo '<td>' . esc_html(mysql2date('Y-m-d H:i', $row->created_at)) . '</td>';
            echo '<td>';
            if (!$row->is_read) {
                echo '<a href="' . esc_url(add_query_arg(['cf_action' => 'read', 'cf_id' => $row->id, 'cf_nonce' => wp_create_nonce('cf_read')])) . '" class="button button-small">Mark Read</a>';
            }
            echo ' <a href="' . esc_url(add_query_arg(['cf_action' => 'delete', 'cf_id' => $row->id, 'cf_nonce' => wp_create_nonce('cf_delete')])) . '" class="button button-small button-link-delete">Delete</a>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        $total_pages = ceil($result['total'] / 20);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(['total' => $total_pages, 'current' => $page]);
            echo '</div></div>';
        }

        // Handle actions
        if (isset($_GET['cf_action']) && isset($_GET['cf_id'])) {
            $action = sanitize_text_field($_GET['cf_action']);
            $id = (int) $_GET['cf_id'];
            if ($action === 'read' && wp_verify_nonce($_GET['cf_nonce'] ?? '', 'cf_read')) {
                self::mark_read($id);
                wp_redirect(add_query_arg(['cf_page' => $page, 'cf_filter' => $filter]));
                exit;
            }
            if ($action === 'delete' && wp_verify_nonce($_GET['cf_nonce'] ?? '', 'cf_delete')) {
                self::delete_submission($id);
                wp_redirect(add_query_arg(['cf_page' => min($page, $total_pages), 'cf_filter' => $filter]));
                exit;
            }
        }
    }

    /**
     * Register admin menu.
     */
    public static function register_menu() {
        add_submenu_page(
            'aether',
            'Contact Forms',
            'Contact Forms',
            'manage_options',
            'aether-contact-forms',
            [__CLASS__, 'admin_page']
        );
    }
}
