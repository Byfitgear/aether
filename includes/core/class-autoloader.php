<?php
/**
 * 自动加载器
 * 
 * 处理插件内所有类的自动加载
 *
 * @package aether
 * @subpackage Core
 */

defined('ABSPATH') || exit;

/**
 * Aether 自动加载器类
 */
class Aether_Autoloader
{

    /**
     * 类前缀
     */
    const PREFIX = 'Aether_';

    /**
     * 类映射
     * 
     * @var array
     */
    private static $class_map = [
        // Core classes
        'Aether_Base' => 'includes/core/class-base.php',
        'Aether_Assets' => 'includes/core/class-assets.php',
        'Aether_Autoloader' => 'includes/core/class-autoloader.php',
        'Aether_Vite_Loader' => 'includes/core/class-vite-loader.php',
        'Aether_Unified_Proxy' => 'includes/core/class-unified-proxy.php',
        'Aether_Proxy_Middleware' => 'includes/core/proxy/interface-middleware.php',
        'Aether_Proxy_Auth_Middleware' => 'includes/core/proxy/class-auth-middleware.php',
        'Aether_Proxy_Cache_Middleware' => 'includes/core/proxy/class-cache-middleware.php',
        'Aether_Proxy_Log_Middleware' => 'includes/core/proxy/class-log-middleware.php',

        // Main classes
        'Aether_Editor' => 'includes/class-editor.php',
        'Aether_API' => 'includes/class-api.php',
        'Aether_Settings' => 'includes/class-settings.php',
        'Aether_Templates' => 'includes/class-templates.php',
        'Aether_Template_Override' => 'includes/class-template-override.php',
        'Aether_Dynamic_Template_Types' => 'includes/class-dynamic-template-types.php',
        'Aether_Template_Editor_Page' => 'includes/editor/class-template-editor-page.php',

        // API Route classes
        'Aether_API_Routes_Base' => 'includes/api/class-api-routes-base.php',
        'Aether_API_Routes_Content' => 'includes/api/class-api-routes-content.php',
        'Aether_API_Routes_Media' => 'includes/api/class-api-routes-media.php',
        'Aether_API_Routes_Settings' => 'includes/api/class-api-routes-settings.php',
        'Aether_API_Routes_Templates' => 'includes/api/class-api-routes-templates.php',
        'Aether_API_Routes_Preview' => 'includes/api/class-api-routes-preview.php',
        'Aether_API_Routes_CSS' => 'includes/api/class-api-routes-css.php',
        'Aether_API_Routes_Fonts' => 'includes/api/class-api-routes-fonts.php',
        'Aether_API_Routes_AI' => 'includes/api/class-api-routes-ai.php',
        'Aether_API_Routes_Fields' => 'includes/api/class-api-routes-fields.php',
        'Aether_Unified_Proxy_Routes' => 'includes/api/class-unified-proxy-routes.php',
        'Aether_AI_Stream_Handler' => 'includes/api/class-ai-stream-handler.php',

        // Service classes
        'Aether_WordPress_Context_Service' => 'includes/services/class-wordpress-context-service.php',
        'Aether_ACF_Integration_Service' => 'includes/services/core/class-acf-integration-service.php',
        'Aether_Content_API' => 'includes/services/api/class-content-api.php',
        'Aether_Media_API' => 'includes/services/api/class-media-api.php',
        'Aether_Template_API' => 'includes/services/api/class-template-api.php',
        'Aether_Permission_Service' => 'includes/services/core/class-permission-service.php',
        'Aether_Settings_Service' => 'includes/services/class-settings-service.php',
        'Aether_Template_Service' => 'includes/services/template/class-template-service.php',
        'Aether_PHP_Runtime_Cache_Policy' => 'includes/services/core/class-php-runtime-cache-policy.php',
        'Aether_Field_Discovery_API' => 'includes/services/api/class-field-discovery-api.php',
        'Aether_Font_Manager_Service' => 'includes/services/font/class-font-manager-service.php',
        'Aether_Font_Downloader_Service' => 'includes/services/font/class-font-downloader-service.php',
        'Aether_Font_CSS_Generator_Service' => 'includes/services/font/class-font-css-generator-service.php',
        'Aether_Template_Preview' => 'includes/services/template/class-template-preview.php',
        'Aether_Template_Type_Detector' => 'includes/services/template/class-template-type-detector.php',
        'Aether_Template_Validator' => 'includes/services/template/class-template-validator.php',
        'Aether_Template_Field_Filter_Service' => 'includes/services/template/class-template-field-filter-service.php',
        'Aether_Smart_CSS_Compiler_Service' => 'includes/services/css/class-smart-css-compiler-service.php',
        'Aether_Production_Mode_Manager' => 'includes/services/core/class-production-mode-manager.php',
        'Aether_CSS_Storage_Service' => 'includes/services/css/class-css-storage-service.php',
        'Aether_CSS_Compile_Strategy_Service' => 'includes/services/css/class-css-compile-strategy-service.php',
        'Aether_Safelist_Service' => 'includes/services/css/class-safelist-service.php',
        'Aether_CSS_Injection_Manager' => 'includes/services/css/class-css-injection-manager.php',
        'Aether_Design_System_Extractor' => 'includes/services/design/class-design-system-extractor.php',

        // Optimization classes
        'Aether_Global_Leader_Service' => 'includes/services/optimization/class-global-leader-service.php',

        // Image optimization classes
        'Aether_Image_Sizes' => 'includes/services/image/class-image-sizes.php',
        'Aether_Image_Environment' => 'includes/services/image/class-image-environment.php',
        'Aether_Image_Processor' => 'includes/services/image/class-image-processor.php',
        'Aether_Image_Optimizer' => 'includes/services/image/class-image-optimizer.php',
        'Aether_Image_Optimization_Service' => 'includes/services/image/class-image-optimization-service.php',
        'Aether_Image_Cleanup_Service' => 'includes/services/image/class-image-cleanup-service.php',
        'Aether_Image_Process_API' => 'includes/services/api/class-image-process-api.php',
        'Aether_Media_Upload_API' => 'includes/services/api/class-media-upload-api.php',

        // HTML optimization classes
        'Aether_HTML_Optimization_Service' => 'includes/services/html/class-html-optimization-service.php',
        'Aether_HTML_Render_Filter' => 'includes/services/html/class-html-render-filter.php',

        // Editor module classes
        'Aether_Editor_UI' => 'includes/editor/class-editor-ui.php',
        'Aether_Editor_Gutenberg' => 'includes/editor/class-editor-gutenberg.php',
        'Aether_Editor_Content' => 'includes/editor/class-editor-content.php',
        'Aether_Editor_Page' => 'includes/editor/class-editor-page.php',
        'Aether_Editor_Page_Base' => 'includes/editor/class-editor-page-base.php',
        'Aether_Editor_Page_React' => 'includes/editor/class-editor-page-react.php',
        'Aether_Editor_Ajax' => 'includes/editor/class-editor-ajax.php',
    ];

    /**
     * 注册自动加载器
     */
    public static function register()
    {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    /**
     * 自动加载回调
     *
     * @param string $class_name 类名
     */
    public static function autoload($class_name)
    {
        // 处理命名空间类 (Aether\Core\Class_Name)
        if (strpos($class_name, 'Aether\\') === 0) {
            self::autoload_namespaced($class_name);
            return;
        }

        // 检查是否是我们的类
        if ($class_name === null || !is_string($class_name) || strpos($class_name, self::PREFIX) !== 0) {
            return;
        }

        // 检查类映射
        if (isset(self::$class_map[$class_name])) {
            $file = AETHER_PATH . self::$class_map[$class_name];
            if (file_exists($file)) {
                require_once $file;
            }
        } else {
            // 尝试动态解析路径
            self::autoload_dynamic($class_name);
        }
    }

    /**
     * 加载命名空间类
     *
     * @param string $class_name 完整类名（含命名空间）
     */
    private static function autoload_namespaced($class_name)
    {
        // Aether\Core\Swiper_Loader -> includes/core/class-swiper-loader.php
        $parts = explode('\\', $class_name);

        if ($parts[0] !== 'Aether' || count($parts) < 3) {
            return;
        }

        // 移除 Aether 前缀
        array_shift($parts);

        // 获取子目录 (Core -> core)
        $subdir = strtolower($parts[0]);
        array_shift($parts);

        // 获取类名并转换为文件名 (Swiper_Loader -> class-swiper-loader.php)
        $class_file = 'class-' . strtolower(str_replace('_', '-', implode('_', $parts))) . '.php';

        $file = AETHER_PATH . 'includes/' . $subdir . '/' . $class_file;

        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * 动态解析类路径
     * 
     * @param string $class_name 类名
     */
    private static function autoload_dynamic($class_name)
    {
        // 移除前缀
        $class = substr($class_name, strlen(self::PREFIX));

        // 转换为文件路径
        $class = ($class !== null && is_string($class)) ? strtolower(str_replace('_', '-', $class)) : '';

        // 尝试不同的路径模式
        $paths = [
            'includes/class-' . $class . '.php',
            'includes/services/class-' . $class . '.php',
            'includes/services/api/class-' . $class . '.php',
            'includes/services/core/class-' . $class . '.php',
            'includes/services/css/class-' . $class . '.php',
            'includes/services/font/class-' . $class . '.php',
            'includes/services/template/class-' . $class . '.php',
            'includes/services/design/class-' . $class . '.php',
            'includes/services/image/class-' . $class . '.php',
            'includes/services/html/class-' . $class . '.php',
            'includes/services/optimization/class-' . $class . '.php',
            'includes/admin/class-' . $class . '.php',
            'includes/core/class-' . $class . '.php',
            'includes/editor/class-' . $class . '.php',
            'includes/api/class-' . $class . '.php',
        ];

        foreach ($paths as $path) {
            $file = AETHER_PATH . $path;
            if (file_exists($file)) {
                require_once $file;
                break;
            }
        }
    }

    /**
     * 手动加载一个类
     * 
     * @param string $class_name 类名
     * @return bool
     */
    public static function load_class($class_name)
    {
        if (class_exists($class_name)) {
            return true;
        }

        self::autoload($class_name);
        return class_exists($class_name);
    }

    /**
     * 获取所有已注册的类
     * 
     * @return array
     */
    public static function get_registered_classes()
    {
        return array_keys(self::$class_map);
    }
}
