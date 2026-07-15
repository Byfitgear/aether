<?php
/**
 * Footer Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Footer
 */
class Aether_Template_Footer {
    
    /**
     * Get footer template
     */
    public static function get() {
        return '
                </div>
            </main>
            
            <footer class="border-t border-gray-300 py-8 mt-8">
                <div class="max-w-7xl mx-auto px-4">
                    <div class="text-center text-gray-600 text-sm">
                        <?php
                        // Only show footer menu if it exists
                        if (has_nav_menu("footer")) {
                            wp_nav_menu(array(
                                "theme_location" => "footer",
                                "container" => false,
                                "menu_class" => "flex justify-center list-none gap-6 mb-4 flex-wrap",
                                "depth" => 1
                            ));
                        }
                        ?>
                        
                        <p>&copy; <?php echo date("Y"); ?> <?php bloginfo("name"); ?>. All rights reserved.</p>
                    </div>
                </div>
            </footer>
        </div><!-- .aether-default-template -->
        ';
    }
}