<?php
/**
 * AI 对话框模板
 * 
 * @package aether
 */

defined('ABSPATH') || exit;
?>

<!-- AI 对话框 -->
<div id="aether-ai-dialog" class="aether-dialog">
    <div class="aether-dialog__backdrop"></div>
    <div class="aether-dialog__content">
        <div class="aether-dialog__header">
            <h3 class="aether-dialog__title"><?php _e('AI 代码编辑', 'aether'); ?></h3>
            <button type="button" id="aether-ai-close" class="aether-dialog__close">&times;</button>
        </div>
        
        <div class="aether-dialog__body">
            <div class="aether-ai-selected-code mb-4">
                <label class="d-block mb-2"><?php _e('选中的代码：', 'aether'); ?></label>
                <pre id="aether-ai-selected-preview" class="aether-code-preview"></pre>
            </div>
            
            <div class="aether-ai-input-wrapper">
                <label for="aether-ai-input" class="d-block mb-2"><?php _e('告诉 AI 你想要做什么：', 'aether'); ?></label>
                <input type="text" id="aether-ai-input" class="aether-input w-100 mb-2"
                    placeholder="<?php esc_attr_e('例如：将按钮改为红色背景 或 粘贴图片链接', 'aether'); ?>">
                <div class="aether-ai-hint">
                    <small class="text-muted"><?php _e('提示：按回车键发送指令。可粘贴图片链接让 AI 处理图片', 'aether'); ?></small>
                </div>
            </div>
        </div>
        
        <div class="aether-dialog__footer">
            <button type="button" id="aether-ai-cancel" class="aether-btn aether-btn--ghost">
                <?php _e('取消', 'aether'); ?>
            </button>
            <button type="button" id="aether-ai-submit" class="aether-btn aether-btn--primary">
                <?php _e('生成代码', 'aether'); ?>
            </button>
        </div>
    </div>
</div>