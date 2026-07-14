<?php
defined('ABSPATH') || exit;
$settings = $settings ?? [];
$post_types = $post_types ?? [];
?>
<div class="wrap">
    <h1><?php _e('WordExpress 设置', 'wordexpress'); ?></h1>

    <form id="wordexpress-settings-form" method="post">
        <?php wp_nonce_field('wordexpress-settings', 'nonce'); ?>
        <table class="form-table">
            <tr>
                <th><label for="we-enabled"><?php _e('启用编辑器', 'wordexpress'); ?></label></th>
                <td>
                    <label><input type="checkbox" id="we-enabled" name="settings[enabled]" value="1" <?php checked($settings['enabled'] ?? true); ?>> <?php _e('启用 WordExpress 可视化编辑器', 'wordexpress'); ?></label>
                </td>
            </tr>
            <tr>
                <th><label for="we-api-token"><?php _e('API Token', 'wordexpress'); ?></label></th>
                <td>
                    <input type="password" id="we-api-token" name="settings[api_token]" value="<?php echo esc_attr($settings['api_token'] ?? ''); ?>" style="width:100%;max-width:500px;">
                    <p class="description"><?php _e('连接后端服务所需的 API Token。留空表示仅使用本地编辑器。', 'wordexpress'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('启用的文章类型', 'wordexpress'); ?></label></th>
                <td>
                    <?php foreach ($post_types as $slug => $pt): ?>
                        <?php if (in_array($slug, ['attachment', 'wordexpress_template'])) continue; ?>
                        <label style="display:block;margin:4px 0;">
                            <input type="checkbox" name="settings[post_types][]" value="<?php echo esc_attr($slug); ?>"
                                   <?php checked(in_array($slug, $settings['post_types'] ?? [])); ?>>
                            <?php echo esc_html($pt->label ?: $slug); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php _e('选择可以使用 WordExpress 编辑的文章类型。默认全部启用。', 'wordexpress'); ?></p>
                </td>
            </tr>

            <hr>
            <th colspan="2"><h3><?php _e('联系表单设置', 'wordexpress'); ?></h3></th>

            <tr>
                <th><label for="we-cf-title"><?php _e('表单标题', 'wordexpress'); ?></label></th>
                <td>
                    <input type="text" id="we-cf-title" name="settings[contact_form_title]" value="<?php echo esc_attr($settings['contact_form_title'] ?? __('联系我们', 'wordexpress')); ?>" style="width:100%;max-width:400px;">
                </td>
            </tr>
            <tr>
                <th><label for="we-cf-email"><?php _e('通知邮箱', 'wordexpress'); ?></label></th>
                <td>
                    <input type="email" id="we-cf-email" name="settings[contact_form_email]" value="<?php echo esc_attr($settings['contact_form_email'] ?? get_option('admin_email')); ?>" style="width:100%;max-width:400px;">
                </td>
            </tr>
            <tr>
                <th><label for="we-cf-success"><?php _e('成功提示', 'wordexpress'); ?></label></th>
                <td>
                    <input type="text" id="we-cf-success" name="settings[contact_form_success_message]" value="<?php echo esc_attr($settings['contact_form_success_message'] ?? __('感谢您的留言，我们会尽快回复您！', 'wordexpress')); ?>" style="width:100%;max-width:500px;">
                </td>
            </tr>
            <tr>
                <th><label><?php _e('表单字段', 'wordexpress'); ?></label></th>
                <td>
                    <div id="we-cf-fields"></div>
                    <button type="button" id="we-add-field" class="button" style="margin-top:8px;"><?php _e('+ 添加字段', 'wordexpress'); ?></button>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="submit" class="button button-primary button-large" value="<?php _e('保存设置', 'wordexpress'); ?>">
            <button type="button" id="we-reset-settings" class="button button-secondary" style="margin-left:12px;"><?php _e('重置为默认值', 'wordexpress'); ?></button>
        </p>
    </form>

    <div id="wordexpress-settings-msg" style="display:none;padding:12px;margin:16px 0;border-radius:4px;"></div>
</div>

<script>
(function($) {
    var fieldsContainer = $('#we-cf-fields');
    var savedFields = <?php echo json_encode($settings['contact_form_fields'] ?? []); ?>;

    function renderFields() {
        fieldsContainer.empty();
        savedFields.forEach(function(f, i) {
            fieldsContainer.append(createFieldHTML(i, f));
        });
    }

    function createFieldHTML(i, f) {
        return '<div class="we-cf-field-row" style="border:1px solid #ddd;padding:12px;margin-bottom:8px;border-radius:4px;">' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' +
            '<select name="settings[contact_form_fields]['+i+'][type]" style="padding:6px;">' +
            '<option value="text"'+(f.type==='text'?' selected':'')+'>文本</option>' +
            '<option value="email"'+(f.type==='email'?' selected':'')+'>邮箱</option>' +
            '<option value="tel"'+(f.type==='tel'?' selected':'')+'>电话</option>' +
            '<option value="textarea"'+(f.type==='textarea'?' selected':'')+'>多行文本</option>' +
            '</select>' +
            '<input type="text" name="settings[contact_form_fields]['+i+'][name]" placeholder="字段名" value="'+(f.name||'')+'" style="width:100px;padding:6px;">' +
            '<input type="text" name="settings[contact_form_fields]['+i+'][label]" placeholder="标签" value="'+(f.label||'')+'" style="width:120px;padding:6px;">' +
            '<label style="white-space:nowrap;"><input type="checkbox" name="settings[contact_form_fields]['+i+'][required]" '+(f.required?'checked':'')+'> 必填</label>' +
            '<button type="button" class="we-remove-field button button-link-delete" data-idx="'+i+'" style="color:#c62828;">删除</button>' +
            '</div></div>';
    }

    $('#we-add-field').on('click', function() {
        savedFields.push({name:'', label:'新字段', type:'text', required:false});
        renderFields();
    });

    $(document).on('click', '.we-remove-field', function() {
        var idx = parseInt($(this).data('idx'));
        savedFields.splice(idx, 1);
        renderFields();
    });

    renderFields();

    // Save
    $('#wordexpress-settings-form').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serializeArray();
        var settings = {};
        formData.forEach(function(f) {
            var parts = f.name.match(/^settings\[(.+?)\](?:\[(\d+)\])?\[(.+?)\]?$/);
            if (parts) {
                var base = parts[1], key = parts[3], idx = parts[2];
                if (!settings[base]) settings[base] = {};
                if (idx !== undefined) {
                    if (!Array.isArray(settings[base][key])) settings[base][key] = [];
                    settings[base][key][parseInt(idx)] = f.value;
                } else {
                    settings[base][key] = f.value;
                }
            } else if (f.name === 'settings[enabled]') {
                settings.enabled = f.value === '1';
            } else if (f.name.startsWith('settings[post_types][]')) {
                if (!settings.post_types) settings.post_types = [];
                settings.post_types.push(f.value);
            }
        });

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'wordexpress_save_settings', nonce: wordexpressSettings.nonce, settings: settings },
            success: function(r) {
                var msg = $('#wordexpress-settings-msg');
                if (r.success) {
                    msg.css({background:'#e8f5e9',color:'#2e7d32'}).text(r.data.message).show();
                } else {
                    msg.css({background:'#ffebee',color:'#c62828'}).text(r.data.message).show();
                }
                setTimeout(function(){msg.hide();}, 3000);
            }
        });
    });

    // Reset
    $('#we-reset-settings').on('click', function() {
        if (confirm('<?php echo esc_js(__('确定要重置所有设置为默认值吗？', 'wordexpress')); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'wordexpress_reset_settings', nonce: wordexpressSettings.nonce },
                success: function(r) {
                    var msg = $('#wordexpress-settings-msg');
                    if (r.success) {
                        msg.css({background:'#e8f5e9',color:'#2e7d32'}).text(r.data.message).show();
                        setTimeout(function(){location.reload();}, 1500);
                    } else {
                        msg.css({background:'#ffebee',color:'#c62828'}).text(r.data.message).show();
                    }
                }
            });
        }
    });
})(jQuery);
</script>
