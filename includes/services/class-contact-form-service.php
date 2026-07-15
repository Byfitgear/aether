<?php
namespace Aether\Services;
if (!defined('ABSPATH')) { exit; }
class Aether_Contact_Form_Service {
    const TABLE_NAME = 'aether_contact_submissions';
    const SHORTCODE  = 'aether_contact_form';
    public static function init() {
        add_shortcode(self::SHORTCODE, [self::class, 'render_form']);
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_post_aether_submit_contact', [self::class, 'process_submission']);
        add_action('admin_init', [self::class, 'register_settings']);
    }
    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, subject VARCHAR(500) DEFAULT '', message TEXT NOT NULL, ip_address VARCHAR(45) DEFAULT '', status VARCHAR(20) DEFAULT 'new', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY status (status)) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    public static function register_settings() {
        register_setting('aether_contact_form','aether_cf_from_email',['type'=>'string','default'=>get_option('admin_email'),'sanitize_callback'=>'sanitize_email']);
        register_setting('aether_contact_form','aether_cf_from_name',['type'=>'string','default'=>get_option('blogname'),'sanitize_callback'=>'sanitize_text_field']);
        register_setting('aether_contact_form','aether_cf_spam_protect',['type'=>'boolean','default'=>true]);
        register_setting('aether_contact_form','aether_cf_success_message',['type'=>'string','default'=>__('Thank you! Your message has been sent.','aether'),'sanitize_callback'=>'wp_kses_post']);
    }
    public static function render_form($atts) {
        $atts = shortcode_atts(['title'=>__('Contact Us','aether'),'submit'=>__('Send Message','aether')],$atts,self::SHORTCODE);
        ob_start();
        ?>
        <div class="aether-contact-form">
        <?php if(isset($_GET['cf_sent'])): ?><div class="aether-form-success"><p><?php echo esc_html(get_option('aether_cf_success_message',__('Thank you! Your message has been sent.','aether'))); ?></p></div>
        <?php elseif(isset($_GET['cf_error'])): ?><div class="aether-form-error"><p><?php echo esc_html($_GET['cf_error']); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aether-contact-form-inner">
            <input type="hidden" name="action" value="aether_submit_contact">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            <div class="aether-form-row"><label for="cf-name"><?php esc_html_e('Name','aether');?> <span class="required">*</span></label><input type="text" id="cf-name" name="cf_name" required value="<?php echo isset($_POST['cf_name'])?esc_attr($_POST['cf_name']):''; ?>"></div>
            <div class="aether-form-row"><label for="cf-email"><?php esc_html_e('Email','aether');?> <span class="required">*</span></label><input type="email" id="cf-email" name="cf_email" required value="<?php echo isset($_POST['cf_email'])?esc_attr($_POST['cf_email']):''; ?>"></div>
            <div class="aether-form-row"><label for="cf-subject"><?php esc_html_e('Subject','aether');?></label><input type="text" id="cf-subject" name="cf_subject" value="<?php echo isset($_POST['cf_subject'])?esc_attr($_POST['cf_subject']):''; ?>"></div>
            <div class="aether-form-row"><label for="cf-message"><?php esc_html_e('Message','aether');?> <span class="required">*</span></label><textarea id="cf-message" name="cf_message" rows="6" required><?php echo isset($_POST['cf_message'])?esc_textarea($_POST['cf_message']):''; ?></textarea></div>
            <?php if(get_option('aether_cf_spam_protect',true)): ?><div class="aether-honeypot" style="display:none;"><label><?php esc_html_e('Leave this empty:','aether');?></label><input type="text" name="cf_website" tabindex="-1" autocomplete="off"></div><?php endif; ?>
            <div class="aether-form-submit"><button type="submit"><?php echo esc_html($atts['submit']); ?></button></div>
        </form></div>
        <?php return ob_get_clean();
    }
    public static function process_submission() {
        if(!isset($_POST['action'])||$_POST['action']!=='aether_submit_contact'){wp_safe_redirect(add_query_arg('cf_error',urlencode(__('Invalid request.','aether')),wp_get_referer()));exit;}
        check_admin_referer('aether_submit_contact');
        if(!empty($_POST['cf_website'])){wp_safe_redirect(add_query_arg('cf_error',urlencode(__('Spam detected.','aether')),wp_get_referer()));exit;}
        $name=sanitize_text_field($_POST['cf_name']??'');$email=sanitize_email($_POST['cf_email']??'');$subject=sanitize_text_field($_POST['cf_subject']??'');$message=wp_kses_post($_POST['cf_message']??'');
        if(empty($name)||empty($email)||!is_email($email)||empty($message)){wp_safe_redirect(add_query_arg('cf_error',urlencode(__('Please fill all required fields.','aether')),wp_get_referer()));exit;}
        global $wpdb;$wpdb->insert($wpdb->prefix.self::TABLE_NAME,['name'=>$name,'email'=>$email,'subject'=>$subject,'message'=>$message,'ip_address'=>$_SERVER['REMOTE_ADDR']??'','status'=>'new']);
        wp_mail(get_option('aether_cf_from_email',get_option('admin_email')),sprintf('[%s] %s',get_option('aether_cf_from_name',get_option('blogname')),$subject?:__('New Contact Message','aether')),sprintf("Name: %s\nEmail: %s\nSubject: %s\n\nMessage:\n%s",$name,$email,$subject?:'-',$message),['Content-Type: text/plain; charset=UTF-8']);
        wp_safe_redirect(add_query_arg('cf_sent','1',wp_get_referer()));exit;
    }
    public static function add_admin_menu(){add_submenu_page('aether-settings',__('Form Submissions','aether'),__('Submissions','aether'),'manage_options','aether-submissions',[self::class,'render_admin_page']);}
    public static function render_admin_page() {
        global $wpdb;$table=$wpdb->prefix.self::TABLE_NAME;
        if(isset($_POST['cf_update_status'])&&isset($_POST['cf_id'])){check_admin_referer('aether_cf_update_status');$wpdb->update($table,['status'=>sanitize_text_field($_POST['cf_status'])],['id'=>intval($_POST['cf_id'])]);set_transient('aether_cf_updated',true,30);}
        if(isset($_POST['cf_delete'])&&isset($_POST['cf_id'])){check_admin_referer('aether_cf_delete');$wpdb->delete($table,['id'=>intval($_POST['cf_id'])]);set_transient('aether_cf_deleted',true,30);}
        $pp=20;$page=max(1,intval($_GET['paged']??1));$offset=($page-1)*$pp;$total=$wpdb->get_var("SELECT COUNT(*) FROM $table");$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",$pp,$offset));$pages=ceil($total/$pp);
        ?><div class="wrap"><h1><?php esc_html_e('Contact Form Submissions','aether');?></h1>
        <?php if(get_transient('aether_cf_updated')):?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Status updated.','aether');?></p></div><?php delete_transient('aether_cf_updated');endif;?>
        <?php if(get_transient('aether_cf_deleted')):?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Deleted.','aether');?></p></div><?php delete_transient('aether_cf_deleted');endif;?>
        <?php if($rows):?><table class="wp-list-table widefat fixed striped"><thead><tr><th>#</th><th><?php esc_html_e('Name','aether');?></th><th><?php esc_html_e('Email','aether');?></th><th><?php esc_html_e('Subject','aether');?></th><th><?php esc_html_e('Status','aether');?></th><th><?php esc_html_e('Date','aether');?></th><th><?php esc_html_e('Actions','aether');?></th></tr></thead><tbody>
        <?php foreach($rows as $row):?><tr><td><?php echo esc_html($row->id);?></td><td><?php echo esc_html($row->name);?></td><td><a href="mailto:<?php echo esc_attr($row->email);?>"><?php echo esc_html($row->email);?></a></td><td><?php echo esc_html($row->subject?:'-');?></td><td><form method="post" style="display:inline;"><?php wp_nonce_field('aether_cf_update_status');?><input type="hidden" name="cf_id" value="<?php echo $row->id;?>"><select name="cf_status" onchange="this.form.submit()"><option value="new"<?php selected($row->status,'new');?>>New</option><option value="read"<?php selected($row->status,'read');?>>Read</option><option value="replied"<?php selected($row->status,'replied');?>>Replied</option><option value="spam"<?php selected($row->status,'spam');?>>Spam</option></select></form></td><td><?php echo esc_html(date_i18n('Y-m-d H:i',strtotime($row->created_at)));?></td><td><button type="button" class="button button-small" onclick="alert('<?php echo esc_js(wp_unslash($row->message));?>')"><?php esc_html_e('View','aether');?></button> <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_html_e('Delete?','aether');?>')"><?php wp_nonce_field('aether_cf_delete');?><input type="hidden" name="cf_id" value="<?php echo $row->id;?>"><input type="hidden" name="cf_delete" value="1"><button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete','aether');?></button></form></td></tr><?php endforeach;?></tbody></table>
        <?php if($pages>1)echo paginate_links(['total'=>$pages,'current'=>$page]);endif;else:echo '<p>'.esc_html__('No submissions yet.','aether').'</p>';endif;?></div><?php
    }
}
