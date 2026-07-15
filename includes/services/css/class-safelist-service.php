<?php
/**
 * Safelist 服务 - 统一的 Tailwind safelist 生成
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safelist 服务类
 */
class Aether_Safelist_Service
{
    /**
     * 获取 safelist HTML
     *
     * @return string Safelist 占位 HTML
     */
    public static function get_html(): string
    {
        $classes = self::get_classes();
        $class_attr = esc_attr(implode(' ', $classes));
        $html = '<div style="display:none!important" data-aether="safelist" class="' . $class_attr . '"></div>';

        /**
         * 允许开发者通过过滤器追加 safelist
         *
         * @param string $html 当前 safelist 占位 HTML
         */
        return apply_filters('aether_tailwind_safelist_html', $html);
    }

    /**
     * 获取 safelist 类名数组
     *
     * @return array<string> 类名数组
     */
    public static function get_classes(): array
    {
        // 基础显示/布局类（合并两个原始集合）
        $base = [
            'hidden', 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'contents'
        ];

        // 常用布局/对齐/间距（合并两个原始集合）
        $layout = [
            'items-start', 'items-center', 'items-end',
            'justify-start', 'justify-center', 'justify-between', 'justify-end',
            'gap-1', 'gap-2', 'gap-3', 'gap-4', 'gap-6', 'gap-8', 'gap-x-2', 'gap-y-2',
            'space-x-2', 'space-y-2', 'p-2', 'p-4', 'px-2', 'px-4', 'py-2', 'py-4',
        ];

        // 交互/状态变体（合并两个原始集合）
        $state_variants = ['hover:', 'focus:', 'active:', 'disabled:', 'group-hover:', 'aria-expanded:'];
        $state_targets = ['block', 'hidden', 'inline-block', 'flex', 'underline', 'no-underline', 'opacity-0', 'opacity-100'];

        // 响应式断点
        $breakpoints = ['sm:', 'md:', 'lg:'];
        $bp_targets = ['block', 'hidden', 'flex', 'grid'];

        $classes = array_merge($base, $layout);

        // 生成状态变体类
        foreach ($state_variants as $v) {
            foreach ($state_targets as $t) {
                $classes[] = $v . $t;
            }
        }

        // 生成断点变体类
        foreach ($breakpoints as $bp) {
            foreach ($bp_targets as $t) {
                $classes[] = $bp . $t;
            }
        }

        return array_unique($classes);
    }
}
