<?php
defined('ABSPATH') || exit;

class WordExpress_Autoloader
{
    const PREFIX = 'WordExpress_';

    private static $class_map = [
        // Core classes
        'WordExpress_Base' => 'includes/core/class-base.php',
        'WordExpress_Assets' => 'includes/core/class-assets.php',
        'WordExpress_Vite_Loader' => 'includes/core/class-vite-loader.php',
        'WordExpress_Unified_Proxy' => 'includes/core/class-unified-proxy.php',

        // Main classes
        'WordExpress_Editor' => 'includes/class-editor.php',
        'WordExpress_Settings' => 'includes/class-settings.php',
        'WordExpress_Template_Override' => 'includes/class-template-override.php',

        // Editor module classes
        'WordExpress_Editor_UI' => 'includes/editor/class-editor-ui.php',
        'WordExpress_Editor_Gutenberg' => 'includes/editor/class-editor-gutenberg.php',
        'WordExpress_Editor_Content' => 'includes/editor/class-editor-content.php',
        'WordExpress_Editor_Page' => 'includes/editor/class-editor-page.php',
        'WordExpress_Editor_Page_Base' => 'includes/editor/class-editor-page-base.php',
        'WordExpress_Editor_Page_React' => 'includes/editor/class-editor-page-react.php',
        'WordExpress_Editor_Ajax' => 'includes/editor/class-editor-ajax.php',
        'WordExpress_Template_Editor_Page' => 'includes/editor/class-template-editor-page.php',

        // API Route classes
        'WordExpress_API_Routes_Base' => 'includes/api/class-api-routes-base.php',
        'WordExpress_API_Routes_Content' => 'includes/api/class-api-routes-content.php',
        'WordExpress_API_Routes_Media' => 'includes/api/class-api-routes-media.php',
        'WordExpress_API_Routes_Settings' => 'includes/api/class-api-routes-settings.php',
        'WordExpress_API_Routes_Templates' => 'includes/api/class-api-routes-templates.php',
        'WordExpress_API_Routes_Preview' => 'includes/api/class-api-routes-preview.php',
        'WordExpress_API_Routes_CSS' => 'includes/api/class-api-routes-css.php',
        'WordExpress_API_Routes_Fonts' => 'includes/api/class-api-routes-fonts.php',
        'WordExpress_API_Routes_AI' => 'includes/api/class-api-routes-ai.php',
        'WordExpress_API_Routes_Fields' => 'includes/api/class-api-routes-fields.php',
        'WordExpress_API_Routes_Submissions' => 'includes/api/class-api-routes-submissions.php',
        'WordExpress_API_Routes_Notifications' => 'includes/api/class-api-routes-notifications.php',
        'WordExpress_API_Routes_Optimization' => 'includes/api/class-api-routes-optimization.php',
        'WordExpress_API_Routes_HTMX' => 'includes/api/class-api-routes-htmx.php',
        'WordExpress_Unified_Proxy_Routes' => 'includes/api/class-unified-proxy-routes.php',

        // Service classes
        'WordExpress_Settings_Service' => 'includes/services/class-settings-service.php',
        'WordExpress_Permission_Service' => 'includes/services/class-permission-service.php',
        'WordExpress_Template_Service' => 'includes/services/template/class-template-service.php',
        'WordExpress_Template_Preview' => 'includes/services/template/class-template-preview.php',
        'WordExpress_Template_Type_Detector' => 'includes/services/template/class-template-type-detector.php',
        'WordExpress_Template_Validator' => 'includes/services/template/class-template-validator.php',
        'WordExpress_Smart_CSS_Compiler_Service' => 'includes/services/css/class-smart-css-compiler-service.php',
        'WordExpress_CSS_Storage_Service' => 'includes/services/css/class-css-storage-service.php',
        'WordExpress_CSS_Compile_Strategy_Service' => 'includes/services/css/class-css-compile-strategy-service.php',
        'WordExpress_CSS_Injection_Manager' => 'includes/services/css/class-css-injection-manager.php',
        'WordExpress_HTML_Optimization_Service' => 'includes/services/html/class-html-optimization-service.php',
        'WordExpress_HTML_Render_Filter' => 'includes/services/html/class-html-render-filter.php',
        'WordExpress_Contact_Form_Service' => 'includes/services/class-contact-form-service.php',
    ];

    public static function register()
    {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    public static function autoload($class_name)
    {
        if (strpos($class_name, 'WordExpress\\') === 0) {
            self::autoload_namespaced($class_name);
            return;
        }

        if ($class_name === null || !is_string($class_name) || strpos($class_name, self::PREFIX) !== 0) {
            return;
        }

        if (isset(self::$class_map[$class_name])) {
            $file = WORDEXPRESS_PATH . self::$class_map[$class_name];
            if (file_exists($file)) {
                require_once $file;
            }
        } else {
            self::autoload_dynamic($class_name);
        }
    }

    private static function autoload_namespaced($class_name)
    {
        $parts = explode('\\', $class_name);
        if ($parts[0] !== 'WordExpress' || count($parts) < 3) {
            return;
        }
        array_shift($parts);
        $subdir = strtolower($parts[0]);
        array_shift($parts);
        $class_file = 'class-' . strtolower(str_replace('_', '-', implode('_', $parts))) . '.php';
        $file = WORDEXPRESS_PATH . 'includes/' . $subdir . '/' . $class_file;
        if (file_exists($file)) {
            require_once $file;
        }
    }

    private static function autoload_dynamic($class_name)
    {
        $class = substr($class_name, strlen(self::PREFIX));
        $class = ($class !== null && is_string($class)) ? strtolower(str_replace('_', '-', $class)) : '';

        $paths = [
            'includes/class-' . $class . '.php',
            'includes/services/class-' . $class . '.php',
            'includes/services/api/class-' . $class . '.php',
            'includes/services/core/class-' . $class . '.php',
            'includes/services/css/class-' . $class . '.php',
            'includes/services/template/class-' . $class . '.php',
            'includes/services/html/class-' . $class . '.php',
            'includes/admin/class-' . $class . '.php',
            'includes/core/class-' . $class . '.php',
            'includes/editor/class-' . $class . '.php',
            'includes/api/class-' . $class . '.php',
        ];

        foreach ($paths as $path) {
            $file = WORDEXPRESS_PATH . $path;
            if (file_exists($file)) {
                require_once $file;
                break;
            }
        }
    }

    public static function load_class($class_name)
    {
        if (class_exists($class_name)) {
            return true;
        }
        self::autoload($class_name);
        return class_exists($class_name);
    }
}
