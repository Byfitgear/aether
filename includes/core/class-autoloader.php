<?php
defined('ABSPATH') || exit;

class Aether_Autoloader
{
    const PREFIX = 'Aether_';

    private static $class_map = [
        // Core classes
        'Aether_Base' => 'includes/core/class-base.php',
        'Aether_Assets' => 'includes/core/class-assets.php',
        'Aether_Vite_Loader' => 'includes/core/class-vite-loader.php',
        'Aether_Unified_Proxy' => 'includes/core/class-unified-proxy.php',

        // Main classes
        'Aether_Editor' => 'includes/class-editor.php',
        'Aether_Settings' => 'includes/class-settings.php',
        'Aether_Template_Override' => 'includes/class-template-override.php',

        // Editor module classes
        'Aether_Editor_UI' => 'includes/editor/class-editor-ui.php',
        'Aether_Editor_Gutenberg' => 'includes/editor/class-editor-gutenberg.php',
        'Aether_Editor_Content' => 'includes/editor/class-editor-content.php',
        'Aether_Editor_Page' => 'includes/editor/class-editor-page.php',
        'Aether_Editor_Page_Base' => 'includes/editor/class-editor-page-base.php',
        'Aether_Editor_Page_React' => 'includes/editor/class-editor-page-react.php',
        'Aether_Editor_Ajax' => 'includes/editor/class-editor-ajax.php',
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
        'Aether_API_Routes_Submissions' => 'includes/api/class-api-routes-submissions.php',
        'Aether_API_Routes_Notifications' => 'includes/api/class-api-routes-notifications.php',
        'Aether_API_Routes_Optimization' => 'includes/api/class-api-routes-optimization.php',
        'Aether_API_Routes_HTMX' => 'includes/api/class-api-routes-htmx.php',
        'Aether_Unified_Proxy_Routes' => 'includes/api/class-unified-proxy-routes.php',

        // Service classes
        'Aether_Settings_Service' => 'includes/services/class-settings-service.php',
        'Aether_Permission_Service' => 'includes/services/class-permission-service.php',
        'Aether_Template_Service' => 'includes/services/template/class-template-service.php',
        'Aether_Template_Preview' => 'includes/services/template/class-template-preview.php',
        'Aether_Template_Type_Detector' => 'includes/services/template/class-template-type-detector.php',
        'Aether_Template_Validator' => 'includes/services/template/class-template-validator.php',
        'Aether_Smart_CSS_Compiler_Service' => 'includes/services/css/class-smart-css-compiler-service.php',
        'Aether_CSS_Storage_Service' => 'includes/services/css/class-css-storage-service.php',
        'Aether_CSS_Compile_Strategy_Service' => 'includes/services/css/class-css-compile-strategy-service.php',
        'Aether_CSS_Injection_Manager' => 'includes/services/css/class-css-injection-manager.php',
        'Aether_HTML_Optimization_Service' => 'includes/services/html/class-html-optimization-service.php',
        'Aether_HTML_Render_Filter' => 'includes/services/html/class-html-render-filter.php',
        'Aether_Contact_Form_Service' => 'includes/services/class-contact-form-service.php',
    ];

    public static function register()
    {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    public static function autoload($class_name)
    {
        if (strpos($class_name, 'Aether\\') === 0) {
            self::autoload_namespaced($class_name);
            return;
        }

        if ($class_name === null || !is_string($class_name) || strpos($class_name, self::PREFIX) !== 0) {
            return;
        }

        if (isset(self::$class_map[$class_name])) {
            $file = AETHER_PATH . self::$class_map[$class_name];
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
        if ($parts[0] !== 'Aether' || count($parts) < 3) {
            return;
        }
        array_shift($parts);
        $subdir = strtolower($parts[0]);
        array_shift($parts);
        $class_file = 'class-' . strtolower(str_replace('_', '-', implode('_', $parts))) . '.php';
        $file = AETHER_PATH . 'includes/' . $subdir . '/' . $class_file;
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
            $file = AETHER_PATH . $path;
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
