<?php
/**
 * Aether Plugin Updater (Disabled)
 */
if (!defined('ABSPATH')) { exit; }
class Aether_Plugin_Updater {
    public function __construct() {}
    public function check_for_updates($transient) { return $transient; }
    public function filters() { return $this; }
}
