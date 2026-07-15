<?php
/**
 * Template Editor Page
 * 
 * Handles the aether editor for dynamic templates
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Template_Editor_Page extends Aether_Editor_Page_Base
{

    /**
     * Get the page slug
     */
    protected function get_page_slug()
    {
        return 'aether-template-editor';
    }

    /**
     * Get the page title
     */
    protected function get_page_title()
    {
        return __('aether 模板编辑器', 'aether');
    }

    /**
     * Get the required capability
     */
    protected function get_capability()
    {
        return 'manage_options';
    }

    /**
     * Get the URL parameter name
     */
    protected function get_param_name()
    {
        return 'template_id';
    }

    /**
     * Get content ID from request
     */
    protected function get_content_id_from_request()
    {
        return isset($_GET['template_id']) ? intval($_GET['template_id']) : null;
    }

    /**
     * Get body CSS class
     */
    protected function get_body_class()
    {
        return 'aether-editor-page';
    }
    
    /**
     * Validate content and get content object
     */
    protected function validate_content($content_id)
    {
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限编辑此模板。', 'aether'));
        }
        
        // 获取模板
        $template = get_post($content_id);
        if (!$template || $template->post_type !== 'aether_template') {
            wp_die(__('模板不存在。', 'aether'));
        }
        
        return $template;
    }
    
    /**
     * Get localized data for the editor
     */
    protected function get_localized_data($content_id, $content)
    {
        // 获取模板内容
        $template_content = get_post_meta($content_id, Aether_Templates::CONTENT_META_KEY, true);
        $template_type = get_post_meta($content_id, '_aether_template_type', true);
        
        // 获取预览 URL
        $preview_url = '';
        $template_types_manager = Aether_Dynamic_Template_Types::getInstance();
        $preview_data = $template_types_manager->get_preview_url($template_type);
        if ($preview_data && $preview_data['available'] && !empty($preview_data['url'])) {
            $preview_url = $preview_data['url'];
        }
        
        // 标记模板正在被编辑
        update_post_meta($content_id, '_aether_edited', '1');
        
        return [
            'postId' => $content_id,
            'postTitle' => $content->post_title,
            'postContent' => $template_content ?: '',
            'postType' => 'aether_template',
            'isTemplate' => true,
            'templateType' => $template_type,
            'previewUrl' => $preview_url,
            'homeUrl' => home_url(),
            'exitUrl' => admin_url('admin.php?page=aether#templates'),
            'l10n' => [
                'editor' => [
                    'title' => __('aether 模板编辑器', 'aether'),
                    'saving' => __('保存中...', 'aether'),
                    'saved' => __('已保存', 'aether'),
                    'error' => __('保存失败', 'aether'),
                    'exit' => __('退出编辑器', 'aether'),
                    'preview' => __('预览', 'aether'),
                    'exitConfirm' => __('确定要退出编辑器吗？未保存的更改将丢失。', 'aether'),
                    'errorLoadingContent' => __('加载内容失败', 'aether'),
                ],
            ],
        ];
    }
}
