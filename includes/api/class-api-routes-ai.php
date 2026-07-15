<?php
/**
 * AI API Routes
 * 
 * Handles AI-related REST API routes
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * AI API Routes Class
 */
class Aether_API_Routes_AI extends Aether_API_Routes_Base
{

    /**
     * Register AI routes
     */
    public function register_routes()
    {
        // AI 模板生成路由
        $this->register_route('/ai/generate-template', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_generate_template'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'request' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'session_id' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'conversation' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => []
                ]
            ],
        ]);

        // AI 请求路由（用于编辑器中的 AI 助手）
        $this->register_route('/ai/request', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_ai_request'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // AI 模型列表路由
        $this->register_route('/ai/models', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_get_models'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'base_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw'
                ],
                'api_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // AI 验证路由
        $this->register_route('/ai/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_validate'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'base_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw'
                ],
                'api_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'model' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
    }

    /**
     * Handle AI template generation
     */
    public function handle_generate_template($request)
    {
        $settings = Aether_Settings_Service::get_all();

        // 获取字段上下文
        require_once AETHER_PATH . 'includes/services/api/class-field-discovery-api.php';
        $field_api = new Aether_Field_Discovery_API();
        $context = $field_api->get_ai_context();

        // 使用统一代理发送请求到 AI template 端点
        $proxy = Aether_Unified_Proxy::get_instance();
        $result = $proxy->request('ai', 'template', [
            'data' => [
                'type' => 'template_generator',
                'config' => [
                    'api_key' => $settings['api_key'] ?? '',
                    'base_url' => $settings['base_url'] ?? 'https://api.openai.com/v1',
                    'model' => $settings['model'] ?? 'gpt-4.1'
                ],
                'context' => [
                    'post_types' => $context['post_types'],
                    'fields_by_type' => $context['fields_by_type'],
                    'template_type' => $request->get_param('template_type') ?? ''
                ],
                'request' => [
                    'request' => $request->get_param('request'),
                    'template_type' => $request->get_param('template_type') ?? '',
                    'selected_code' => $request->get_param('selected_code') ?? '',
                    'conversation' => $request->get_param('conversation') ?? [],
                    'additional_context' => $settings['additional_context'] ?? '',
                    'design_system_html' => $settings['design_system_html'] ?? ''
                ]
            ]
        ]);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message());
        }


        // 检查响应状态
        if (!$result['success']) {
            $error_message = $result['data']['message'] ?? $result['data']['error'] ?? 'AI 模板生成失败';
            return $this->error_response($error_message);
        }

        // 处理成功的响应 - 直接返回 data 部分
        return $this->success_response($result['data']);
    }

    /**
     * Handle AI request (for editor) - using workflow
     */
    public function handle_ai_request($request)
    {
        $settings = Aether_Settings_Service::get_all();

        // 检查 AI 设置
        if (empty($settings['base_url'])) {
            return $this->error_response('AI 功能未配置。请先在设置中配置 Workflow API URL。');
        }

        // 获取请求数据
        $messages = $request->get_param('messages');
        $model = $request->get_param('model') ?? $settings['model'] ?? 'gpt-4.1';

        // 构建 assistant workflow 请求
        $proxy = Aether_Unified_Proxy::get_instance();

        // 从 messages 数组中提取内容
        $selected_code = '';
        $user_prompt = '';

        if (!empty($messages)) {
            // 查找 system 消息中的 context code
            foreach ($messages as $message) {
                if ($message['role'] === 'system' && strpos($message['content'], 'Context code:') !== false) {
                    // 提取 Context code 后面的内容
                    $parts = explode('Context code:', $message['content']);
                    if (count($parts) > 1) {
                        $selected_code = trim($parts[1]);
                    }
                }
                if ($message['role'] === 'user') {
                    $user_prompt = $message['content'];
                }
            }
        }

        $result = $proxy->request('ai', 'assistant_workflow', [
            'data' => [
                'type' => 'assistant',
                'config' => [
                    'api_key' => $settings['api_key'] ?? '',
                    'base_url' => $settings['base_url'] ?? 'https://api.openai.com/v1',
                    'model' => $model
                ],
                'request' => [
                    'selected_code' => $selected_code,
                    'user_prompt' => $user_prompt
                ]
            ]
        ]);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message());
        }


        // 检查响应状态
        if (!$result['success']) {
            $error_message = $result['data']['message'] ?? $result['data']['error'] ?? 'AI 请求失败';
            return $this->error_response($error_message);
        }

        // 处理成功的响应数据
        $response_content = '';

        // 尝试不同的响应格式
        if (isset($result['data']['outputs'])) {
            $response_content = $result['data']['outputs']['answer'] ??
                $result['data']['outputs']['text'] ??
                $result['data']['outputs']['result'] ?? '';
        } elseif (isset($result['data']['answer'])) {
            $response_content = $result['data']['answer'];
        } elseif (isset($result['data']['text'])) {
            $response_content = $result['data']['text'];
        } elseif (isset($result['data']['result'])) {
            $response_content = $result['data']['result'];
        } elseif (isset($result['data']['workflowId'])) {
            // 处理异步模式 - 需要轮询状态
            $workflow_id = $result['data']['workflowId'];
            $poll_url = $result['data']['pollUrl'] ?? null;

            // 如果没有提供 pollUrl，构建默认的
            if (!$poll_url) {
                $base_url = $proxy->get_proxy_config('ai')['base_url'];
                if (is_array($base_url)) {
                    $is_dev = defined('AETHER_DEV_MODE') && AETHER_DEV_MODE;
                    $env = $is_dev ? 'development' : 'production';
                    $base_url = $base_url[$env] ?? $base_url['production'];
                }
                $poll_url = rtrim($base_url, '/') . '/aether-ai/assistant/' . $workflow_id . '/status';
            }

            // 轮询获取结果 (最多尝试 30 次，每次间隔 2 秒)
            $max_attempts = 30;
            $attempt = 0;
            $response_content = '';

            while ($attempt < $max_attempts) {
                sleep(2); // 等待 2 秒
                $attempt++;

                // 直接使用 wp_remote_get 轮询状态
                $poll_response = wp_remote_get($poll_url, [
                    'timeout' => 10,
                    'sslverify' => apply_filters('aether_proxy_verify_ssl', true)
                ]);

                if (is_wp_error($poll_response)) {
                    continue; // 继续尝试
                }

                $poll_body = wp_remote_retrieve_body($poll_response);
                $poll_data = json_decode($poll_body, true);


                if (isset($poll_data['status'])) {
                    if ($poll_data['status'] === 'completed') {
                        // 获取结果
                        if (isset($poll_data['result'])) {
                            // result 可能是对象或字符串
                            if (is_array($poll_data['result']) && isset($poll_data['result']['content'])) {
                                $response_content = $poll_data['result']['content'];
                            } elseif (is_string($poll_data['result'])) {
                                $response_content = $poll_data['result'];
                            }
                            break;
                        } elseif (isset($poll_data['outputs'])) {
                            $response_content = $poll_data['outputs']['answer'] ??
                                $poll_data['outputs']['text'] ??
                                $poll_data['outputs']['result'] ?? '';
                            break;
                        }
                    } elseif ($poll_data['status'] === 'failed' || $poll_data['status'] === 'error') {
                        $error_msg = $poll_data['error'] ?? 'Workflow 执行失败';
                        return $this->error_response($error_msg);
                    }
                    // 如果状态是 running，继续轮询
                }
            }

            if (empty($response_content)) {
                return $this->error_response('AI 请求超时，请稍后重试');
            }
        }

        if (empty($response_content)) {
            return $this->error_response('AI 响应为空，请检查 workflow 配置');
        }

        // 转换为 AI 响应格式
        $response_data = [
            'choices' => [
                [
                    'message' => [
                        'content' => $response_content
                    ]
                ]
            ]
        ];

        return $this->success_response($response_data);
    }

    /**
     * Handle get AI models request
     */
    public function handle_get_models($request)
    {
        $base_url = $request->get_param('base_url');
        $api_key = $request->get_param('api_key');


        // 使用统一代理获取模型列表
        $proxy = Aether_Unified_Proxy::get_instance();
        $result = $proxy->request('ai', 'models', [
            'data' => [
                'api_key' => $api_key,
                'base_url' => $base_url
            ]
        ]);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message());
        }

        // 检查是否有错误
        if (isset($result['success']) && !$result['success']) {
            $error_message = $result['message'] ?? $result['error'] ?? '获取模型列表失败';
            return $this->error_response($error_message);
        }

        // 代理返回的数据在 data 字段中
        $api_response = $result['data'] ?? [];

        // 提取模型列表
        $models = [];
        if (isset($api_response['models'])) {
            // 本地 API 格式
            $models = $api_response['models'];
        } elseif (isset($api_response['data'])) {
            // OpenAI 格式
            $models = array_map(function ($model) {
                return [
                    'id' => $model['id'] ?? '',
                    'name' => $model['name'] ?? $model['id'] ?? '',
                    'created' => $model['created'] ?? null,
                    'owned_by' => $model['owned_by'] ?? 'unknown',
                    'description' => $model['description'] ?? ''
                ];
            }, $api_response['data']);
        }

        return $this->success_response([
            'models' => $models,
            'cached' => $api_response['cached'] ?? false
        ]);
    }

    /**
     * Handle validate AI credentials request
     */
    public function handle_validate($request)
    {
        $base_url = $request->get_param('base_url');
        $api_key = $request->get_param('api_key');
        $model = $request->get_param('model');

        // 构建验证数据
        $validate_data = [
            'api_key' => $api_key,
            'base_url' => $base_url
        ];

        // 如果提供了模型，添加到验证数据中
        if (!empty($model)) {
            $validate_data['model'] = $model;
        }

        // 使用统一代理验证凭据
        $proxy = Aether_Unified_Proxy::get_instance();
        $result = $proxy->request('ai', 'validate', [
            'data' => $validate_data
        ]);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message());
        }

        // 检查是否有错误
        if (isset($result['success']) && !$result['success']) {
            $error_message = $result['data']['message'] ?? $result['data']['error'] ?? '验证失败';
            return $this->error_response($error_message);
        }

        // 代理返回的数据在 data 字段中
        $api_response = $result['data'] ?? [];

        return $this->success_response([
            'valid' => $api_response['valid'] ?? false,
            'provider' => $api_response['provider'] ?? '',
            'message' => $api_response['message'] ?? '验证失败'
        ]);
    }
}