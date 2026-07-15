<?php
/**
 * Settings API Routes
 * 
 * Thin controller for settings-related REST API endpoints
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Settings API Routes Class
 */
class Aether_API_Routes_Settings extends Aether_API_Routes_Base {
    
    /**
     * Register settings routes
     */
    public function register_routes() {
        // 设置相关路由
        $this->register_route('/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
        ]);

        $this->register_route('/settings/reset', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'reset_settings'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
        ]);

        $this->register_route('/settings/verify-token', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'verify_token'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
        ]);

        // 速度优化设置
        $this->register_route('/settings/speed', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_speed_settings'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_speed_settings'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
        ]);

        // 图片优化设置
        $this->register_route('/settings/image-optimization', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_image_optimization_settings'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_image_optimization_settings'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
        ]);

        // Design system routes
        $this->register_route('/settings/design-system', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_design_system'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_design_system'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'clear_design_system'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
        ]);
    }
    
    /**
     * Get settings
     */
    public function get_settings($request) {
        return $this->success_response([
            'settings' => Aether_Settings_Service::get_all(),
            'postTypes' => Aether_Settings_Service::get_available_post_types()
        ]);
    }

    /**
     * Update settings
     */
    public function update_settings($request) {
        $settings = $request->get_json_params();
        
        if (empty($settings)) {
            return $this->error_response(__('无效的设置数据', 'aether'));
        }
        
        $result = Aether_Settings_Service::save($settings);
        
        if ($result === true) {
            return $this->success_response([
                'settings' => Aether_Settings_Service::get_all()
            ], __('设置已保存', 'aether'));
        } elseif ($result === 'unchanged') {
            return $this->success_response([
                'settings' => Aether_Settings_Service::get_all(),
                'unchanged' => true
            ], __('设置未发生变化', 'aether'));
        } else {
            return $this->error_response(__('保存失败，请重试', 'aether'), 500);
        }
    }

    /**
     * Reset settings
     */
    public function reset_settings($request) {
        if (Aether_Settings_Service::reset()) {
            return $this->success_response([
                'settings' => Aether_Settings_Service::get_all()
            ], __('设置已重置为默认值', 'aether'));
        } else {
            return $this->error_response(__('重置失败，请重试', 'aether'), 500);
        }
    }

    /**
     * Verify API Token
     */
    /**
     * Verify API Token - Free version always succeeds
     */
    public function verify_token($request) {
        return $this->success_response([
            'valid' => true,
            'message' => __('Token 验证通过（免费版无需验证）', 'aether')
        ]);
    }
    /**
     * Get design system HTML
     */
    public function get_design_system($request) {
        $design_system_html = Aether_Settings_Service::get('design_system_html', '');
        
        return $this->success_response([
            'html' => $design_system_html,
            'hasContent' => !empty($design_system_html)
        ]);
    }

    /**
     * Update design system HTML
     */
    public function update_design_system($request) {
        $data = $request->get_json_params();
        
        if (!isset($data['html'])) {
            return $this->error_response(__('HTML 内容不能为空', 'aether'));
        }
        
        // Get current settings and update only design_system_html
        $settings = Aether_Settings_Service::get_all();
        $settings['design_system_html'] = $data['html'];
        
        // 提取 Tailwind 配置和样式
        if (class_exists('Aether_Design_System_Extractor')) {
            $settings = Aether_Design_System_Extractor::process_design_system($settings);
        }
        
        // 提取的字体信息
        $extracted_fonts = isset($settings['extracted_font_imports']) ? $settings['extracted_font_imports'] : [];
        $font_count = count($extracted_fonts);
        
        // 自动处理字体
        $fonts_result = false;
        if (class_exists('Aether_Design_System_Font_Processor')) {
            $fonts_result = Aether_Design_System_Font_Processor::process_fonts_from_design_system($data['html']);
        }
        
        $result = Aether_Settings_Service::save($settings);

        if ($result === true) {
            $message = __('设计系统已保存', 'aether');

            // 添加字体处理信息到消息
            if (is_array($fonts_result) && $fonts_result['fonts_count'] > 0) {
                $message .= sprintf(
                    __('，已处理 %d 个字体，下载 %d 个新字体文件', 'aether'),
                    $fonts_result['fonts_count'],
                    $fonts_result['fonts_downloaded']
                );
            }

            // 保存成功后：设计系统变更，需要清理所有编译 CSS 并触发重编译
            try {
                // 自动关闭速度优化（设计系统变更后需要重新编译）
                update_option('aether_speed_optimization_enabled', false);

                if (class_exists('Aether_CSS_Storage_Service')) {
                    // 清理所有已编译的 CSS 产物
                    Aether_CSS_Storage_Service::get_instance()->clear_all_css();
                }

                // 清除设计系统哈希缓存，确保下次编译使用新配置
                if (class_exists('Aether_CSS_Hash_Service')) {
                    Aether_CSS_Hash_Service::get_instance()->clear_all_hash_cache();
                }

                // 调度整站重编译
                if (class_exists('Aether_Template_Compile_Manager')) {
                    Aether_Template_Compile_Manager::schedule_recompile_all([
                        'trigger' => 'design_system_changed',
                        'user_id' => get_current_user_id()
                    ]);
                }
            } catch (Exception $e) {
                // 忽略失效/调度中的错误，不影响保存成功
                error_log('Aether design system post-save actions failed: ' . $e->getMessage());
            }

            // 注意：页面缓存已由 Aether_Settings_Service::save() 清理，无需重复调用

            return $this->success_response([
                'html' => $data['html'],
                'hasContent' => !empty($data['html']),
                'fontsExtracted' => $font_count,
                'fontsResult' => $fonts_result
            ], $message);
        } else {
            return $this->error_response(__('保存失败，请重试', 'aether'), 500);
        }
    }

    /**
     * Clear design system HTML
     */
    public function clear_design_system($request) {
        // Get current settings and clear design_system_html
        $settings = Aether_Settings_Service::get_all();
        $settings['design_system_html'] = '';
        $settings['extracted_tailwind_config'] = '';
        $settings['extracted_custom_css'] = '';
        $settings['extracted_global_custom_css'] = '';
        $settings['extracted_font_imports'] = [];

        // 自动清理字体缓存
        if (class_exists('Aether_Font_Manager_Service')) {
            Aether_Font_Manager_Service::clear_font_cache();
        }

        $result = Aether_Settings_Service::save($settings);

        // 注意：页面缓存已由 Aether_Settings_Service::save() 清理，无需重复调用

        if ($result === true) {
            // 自动关闭速度优化（设计系统清空后需要重新配置）
            update_option('aether_speed_optimization_enabled', false);

            // 清理所有编译 CSS
            try {
                if (class_exists('Aether_CSS_Storage_Service')) {
                    Aether_CSS_Storage_Service::get_instance()->clear_all_css();
                }

                // 清除所有哈希缓存
                if (class_exists('Aether_CSS_Hash_Service')) {
                    Aether_CSS_Hash_Service::get_instance()->clear_all_hash_cache();
                }
            } catch (Exception $e) {
                error_log('Aether design system clear post-actions failed: ' . $e->getMessage());
            }

            return $this->success_response([
                'html' => '',
                'hasContent' => false
            ], __('设计系统已清空', 'aether'));
        } else {
            return $this->error_response(__('清空失败，请重试', 'aether'), 500);
        }
    }

    /**
     * 获取速度优化设置
     */
    public function get_speed_settings($request)
    {
        $enabled = get_option('aether_speed_optimization_enabled', false);

        return $this->success_response([
            'enabled' => (bool) $enabled
        ]);
    }

    /**
     * 更新速度优化设置
     */
    public function update_speed_settings($request)
    {
        $enabled = $request->get_param('enabled');

        if ($enabled !== null) {
            $previous_enabled = (bool) get_option('aether_speed_optimization_enabled', false);
            $requested_enabled = (bool) $enabled;

            // 检查设计系统是否存在，给予警告而非阻止
            $warning = null;
            if ($requested_enabled && !$previous_enabled) {
                $design_system_html = Aether_Settings_Service::get('design_system_html', '');
                if (empty($design_system_html)) {
                    $warning = __('提示：未导入设计系统，将使用默认 Tailwind 配置。如需最佳效果，建议先导入设计系统 HTML', 'aether');
                }
            }

            update_option('aether_speed_optimization_enabled', $requested_enabled);

            // 从关闭到开启：需要触发整站编译
            $should_compile = !$previous_enabled && $requested_enabled;

            // 从开启到关闭：清理所有已编译的 CSS
            if ($previous_enabled && !$requested_enabled) {
                try {
                    if (class_exists('Aether_CSS_Storage_Service')) {
                        Aether_CSS_Storage_Service::get_instance()->clear_all_css();
                    }

                    if (class_exists('Aether_CSS_Hash_Service')) {
                        Aether_CSS_Hash_Service::get_instance()->clear_all_hash_cache();
                    }
                } catch (Exception $e) {
                    error_log('Aether speed settings clear CSS failed: ' . $e->getMessage());
                }
            }

            $response_data = [
                'enabled' => $requested_enabled,
                'success' => true,
                'should_compile' => $should_compile
            ];

            // 如果有警告，添加到响应中
            if ($warning) {
                $response_data['warning'] = $warning;
            }

            return $this->success_response($response_data);
        }

        return $this->success_response([
            'enabled' => (bool) get_option('aether_speed_optimization_enabled', false),
            'success' => true
        ]);
    }

    /**
     * 获取图片优化设置
     */
    public function get_image_optimization_settings($request)
    {
        $service = Aether_HTML_Optimization_Service::get_instance();

        return $this->success_response([
            'enabled' => $service->is_optimization_enabled()
        ]);
    }

    /**
     * 更新图片优化设置
     */
    public function update_image_optimization_settings($request)
    {
        $enabled = $request->get_param('enabled');

        if ($enabled === null) {
            return $this->error_response(__('缺少 enabled 参数', 'aether'));
        }

        $service = Aether_HTML_Optimization_Service::get_instance();
        $previous_enabled = $service->is_optimization_enabled();
        $requested_enabled = (bool) $enabled;

        // 保存新状态
        $service->set_optimization_enabled($requested_enabled);

        // 如果从"开启"切换到"关闭"，清理所有优化后的 HTML
        if ($previous_enabled && !$requested_enabled) {
            try {
                $service->clear_all_optimized_html();
            } catch (Exception $e) {
                error_log('Aether image optimization clear failed: ' . $e->getMessage());
            }
        }

        return $this->success_response([
            'enabled' => $requested_enabled,
            'success' => true,
            'message' => $requested_enabled
                ? __('图片优化已开启', 'aether')
                : __('图片优化已关闭', 'aether')
        ]);
    }
}
