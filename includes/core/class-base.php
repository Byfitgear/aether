<?php
/**
 * 基础类
 * 
 * 提供插件类的基础功能
 *
 * @package aether
 * @subpackage Core
 */

defined('ABSPATH') || exit;

/**
 * 抽象基类
 */
abstract class Aether_Base {
    
    /**
     * 单例实例
     * 
     * @var array
     */
    private static $instances = [];
    
    /**
     * Constructor
     */
    protected function __construct() {
        $this->init();
    }
    
    /**
     * Get singleton instance
     * 
     * @return static
     */
    public static function getInstance() {
        $class = static::class;
        
        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new static();
        }
        
        return self::$instances[$class];
    }
    
    /**
     * 初始化方法（子类实现）
     */
    abstract protected function init();
    
    /**
     * 获取插件 URL
     * 
     * @param string $path 相对路径
     * @return string
     */
    protected function get_plugin_url($path = '') {
        return AETHER_URL . $path;
    }
    
    /**
     * 获取插件路径
     * 
     * @param string $path 相对路径
     * @return string
     */
    protected function get_plugin_path($path = '') {
        return AETHER_PATH . $path;
    }
    
    /**
     * 获取资源版本号（基于文件修改时间）
     * 
     * @param string $file_path 文件相对路径
     * @return string 版本号
     */
    protected function get_asset_version($file_path) {
        // Check if development mode is enabled
        $dev_mode = defined('AETHER_DEV_MODE') && constant('AETHER_DEV_MODE');
        
        if ($dev_mode) {
            // Use timestamp in development mode to ensure always getting latest version
            return time();
        }
        
        // 验证文件路径不为空
        if (empty($file_path)) {
            return AETHER_VERSION;
        }
        
        $full_path = AETHER_PATH . $file_path;
        $version = AETHER_VERSION;
        
        if (file_exists($full_path)) {
            $file_time = filemtime($full_path);
            if ($file_time !== false) {
                $version .= '.' . $file_time;
            }
        }
        
        return $version;
    }
    
    /**
     * 加载模板文件
     * 
     * @param string $template 模板名称
     * @param array $vars 模板变量
     */
    protected function load_template($template, $vars = []) {
        $template_file = $this->get_plugin_path("templates/{$template}.php");
        
        if (file_exists($template_file)) {
            extract($vars);
            include $template_file;
        }
    }
    
    /**
     * 防止克隆
     */
    private function __clone() {}
    
    /**
     * 防止反序列化
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}