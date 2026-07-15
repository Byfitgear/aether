<?php
/**
 * Aether Plugin Updater (Disabled - no remote update checking)
 */
if (!defined('ABSPATH')) { exit; }
class Aether_Plugin_Updater {
    public function __construct() {}
    public function check_for_updates($transient) { return $transient; }
    public function filters() { return $this; }
}
