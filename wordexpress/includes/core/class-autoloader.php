<?php
/**
 * 自动加载器
 * 
 * 处理插件内所有类的自动加载
 *
 * @package zeroy
 * @subpackage Core
 */

defined('ABSPATH') || exit;

/**
 * ZeroY 自动加载器类
 */
class ZeroY_Autoloader
{

    /**
     * 类前缀
     */
    const PREFIX = 'ZeroY_';

    /**
     * 类映射
     * 
     * @var array
     */
    private static $class_map = [
        // Core classes
        'ZeroY_Base' => 'includes/core/class-base.php',
        'ZeroY_Assets' => 'includes/core/class-assets.php',
        'ZeroY_Autoloader' => 'includes/core/class-autoloader.php',
        'ZeroY_Vite_Loader' => 'includes/core/class-vite-loader.php',
        'ZeroY_Unified_Proxy' => 'includes/core/class-unified-proxy.php',
        'ZeroY_Proxy_Middleware' => 'includes/core/proxy/interface-middleware.php',
        'ZeroY_Proxy_Auth_Middleware' => 'includes/core/proxy/class-auth-middleware.php',
        'ZeroY_Proxy_Cache_Middleware' => 'includes/core/proxy/class-cache-middleware.php',
        'ZeroY_Proxy_Log_Middleware' => 'includes/core/proxy/class-log-middleware.php',

        // Main classes
        'ZeroY_Editor' => 'includes/class-editor.php',
        'ZeroY_API' => 'includes/class-api.php',
        'ZeroY_Settings' => 'includes/class-settings.php',
        'ZeroY_Templates' => 'includes/class-templates.php',
        'ZeroY_Template_Override' => 'includes/class-template-override.php',
        'ZeroY_Dynamic_Template_Types' => 'includes/class-dynamic-template-types.php',
        'ZeroY_Template_Editor_Page' => 'includes/editor/class-template-editor-page.php',

        // API Route classes
        'ZeroY_API_Routes_Base' => 'includes/api/class-api-routes-base.php',
        'ZeroY_API_Routes_Content' => 'includes/api/class-api-routes-content.php',
        'ZeroY_API_Routes_Media' => 'includes/api/class-api-routes-media.php',
        'ZeroY_API_Routes_Settings' => 'includes/api/class-api-routes-settings.php',
        'ZeroY_API_Routes_Templates' => 'includes/api/class-api-routes-templates.php',
        'ZeroY_API_Routes_Preview' => 'includes/api/class-api-routes-preview.php',
        'ZeroY_API_Routes_CSS' => 'includes/api/class-api-routes-css.php',
        'ZeroY_API_Routes_Fonts' => 'includes/api/class-api-routes-fonts.php',
        'ZeroY_API_Routes_AI' => 'includes/api/class-api-routes-ai.php',
        'ZeroY_API_Routes_Fields' => 'includes/api/class-api-routes-fields.php',
        'ZeroY_Unified_Proxy_Routes' => 'includes/api/class-unified-proxy-routes.php',
        'ZeroY_AI_Stream_Handler' => 'includes/api/class-ai-stream-handler.php',

        // Service classes
        'ZeroY_WordPress_Context_Service' => 'includes/services/class-wordpress-context-service.php',
        'ZeroY_ACF_Integration_Service' => 'includes/services/core/class-acf-integration-service.php',
        'ZeroY_Content_API' => 'includes/services/api/class-content-api.php',
        'ZeroY_Media_API' => 'includes/services/api/class-media-api.php',
        'ZeroY_Template_API' => 'includes/services/api/class-template-api.php',
        'ZeroY_Permission_Service' => 'includes/services/core/class-permission-service.php',
        'ZeroY_Settings_Service' => 'includes/services/class-settings-service.php',
        'ZeroY_Template_Service' => 'includes/services/template/class-template-service.php',
        'ZeroY_PHP_Runtime_Cache_Policy' => 'includes/services/core/class-php-runtime-cache-policy.php',
        'ZeroY_Field_Discovery_API' => 'includes/services/api/class-field-discovery-api.php',
        'ZeroY_Font_Manager_Service' => 'includes/services/font/class-font-manager-service.php',
        'ZeroY_Font_Downloader_Service' => 'includes/services/font/class-font-downloader-service.php',
        'ZeroY_Font_CSS_Generator_Service' => 'includes/services/font/class-font-css-generator-service.php',
        'ZeroY_Template_Preview' => 'includes/services/template/class-template-preview.php',
        'ZeroY_Template_Type_Detector' => 'includes/services/template/class-template-type-detector.php',
        'ZeroY_Template_Validator' => 'includes/services/template/class-template-validator.php',
        'ZeroY_Template_Field_Filter_Service' => 'includes/services/template/class-template-field-filter-service.php',
        'ZeroY_Smart_CSS_Compiler_Service' => 'includes/services/css/class-smart-css-compiler-service.php',
        'ZeroY_Production_Mode_Manager' => 'includes/services/core/class-production-mode-manager.php',
        'ZeroY_CSS_Storage_Service' => 'includes/services/css/class-css-storage-service.php',
        'ZeroY_CSS_Compile_Strategy_Service' => 'includes/services/css/class-css-compile-strategy-service.php',
        'ZeroY_Safelist_Service' => 'includes/services/css/class-safelist-service.php',
        'ZeroY_CSS_Injection_Manager' => 'includes/services/css/class-css-injection-manager.php',
        'ZeroY_Design_System_Extractor' => 'includes/services/design/class-design-system-extractor.php',

        // Optimization classes
        'ZeroY_Global_Leader_Service' => 'includes/services/optimization/class-global-leader-service.php',

        // Image optimization classes
        'ZeroY_Image_Sizes' => 'includes/services/image/class-image-sizes.php',
        'ZeroY_Image_Environment' => 'includes/services/image/class-image-environment.php',
        'ZeroY_Image_Processor' => 'includes/services/image/class-image-processor.php',
        'ZeroY_Image_Optimizer' => 'includes/services/image/class-image-optimizer.php',
        'ZeroY_Image_Optimization_Service' => 'includes/services/image/class-image-optimization-service.php',
        'ZeroY_Image_Cleanup_Service' => 'includes/services/image/class-image-cleanup-service.php',
        'ZeroY_Image_Process_API' => 'includes/services/api/class-image-process-api.php',
        'ZeroY_Media_Upload_API' => 'includes/services/api/class-media-upload-api.php',

        // HTML optimization classes
        'ZeroY_HTML_Optimization_Service' => 'includes/services/html/class-html-optimization-service.php',
        'ZeroY_HTML_Render_Filter' => 'includes/services/html/class-html-render-filter.php',

        // Editor module classes
        'ZeroY_Editor_UI' => 'includes/editor/class-editor-ui.php',
        'ZeroY_Editor_Gutenberg' => 'includes/editor/class-editor-gutenberg.php',
        'ZeroY_Editor_Content' => 'includes/editor/class-editor-content.php',
        'ZeroY_Editor_Page' => 'includes/editor/class-editor-page.php',
        'ZeroY_Editor_Page_Base' => 'includes/editor/class-editor-page-base.php',
        'ZeroY_Editor_Page_React' => 'includes/editor/class-editor-page-react.php',
        'ZeroY_Editor_Ajax' => 'includes/editor/class-editor-ajax.php',
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
        // 处理命名空间类 (ZeroY\Core\Class_Name)
        if (strpos($class_name, 'ZeroY\\') === 0) {
            self::autoload_namespaced($class_name);
            return;
        }

        // 检查是否是我们的类
        if ($class_name === null || !is_string($class_name) || strpos($class_name, self::PREFIX) !== 0) {
            return;
        }

        // 检查类映射
        if (isset(self::$class_map[$class_name])) {
            $file = ZEROY_PATH . self::$class_map[$class_name];
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
        // ZeroY\Core\Swiper_Loader -> includes/core/class-swiper-loader.php
        $parts = explode('\\', $class_name);

        if ($parts[0] !== 'ZeroY' || count($parts) < 3) {
            return;
        }

        // 移除 ZeroY 前缀
        array_shift($parts);

        // 获取子目录 (Core -> core)
        $subdir = strtolower($parts[0]);
        array_shift($parts);

        // 获取类名并转换为文件名 (Swiper_Loader -> class-swiper-loader.php)
        $class_file = 'class-' . strtolower(str_replace('_', '-', implode('_', $parts))) . '.php';

        $file = ZEROY_PATH . 'includes/' . $subdir . '/' . $class_file;

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
            $file = ZEROY_PATH . $path;
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
