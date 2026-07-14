<?php
defined('ABSPATH') || exit;

abstract class Aether_Base
{
    private static $instances = [];

    protected function __construct()
    {
        $this->init();
    }

    public static function getInstance()
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new static();
        }
        return self::$instances[$class];
    }

    abstract protected function init();

    protected function get_plugin_url($path = '')
    {
        return AETHER_URL . $path;
    }

    protected function get_plugin_path($path = '')
    {
        return AETHER_PATH . $path;
    }

    protected function get_asset_version($file_path)
    {
        $dev_mode = defined('AETHER_DEV_MODE') && constant('AETHER_DEV_MODE');
        if ($dev_mode) {
            return time();
        }
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

    protected function load_template($template, $vars = [])
    {
        $template_file = $this->get_plugin_path("templates/{$template}.php");
        if (file_exists($template_file)) {
            extract($vars);
            include $template_file;
        }
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception('Cannot unserialize singleton');
    }
}
