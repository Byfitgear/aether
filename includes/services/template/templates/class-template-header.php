<?php
/**
 * Header Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Header
 */
class Aether_Template_Header {
    
    /**
     * Get header template
     */
    public static function get() {
        // Load styles
        require_once plugin_dir_path(dirname(__FILE__)) . 'class-template-styles.php';
        // Use contextual styles - only includes content styles on single pages
        $styles = Aether_Template_Styles::get_contextual_styles();
        
        return '
        <div class="aether-default-template">
            <style>' . $styles . '</style>
            
            <header class="border-b border-gray-300 py-4 mb-8">
                <div class="max-w-7xl mx-auto px-4">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="text-2xl font-bold">
                            <a href="<?php echo home_url(); ?>" class="text-black no-underline hover:underline">
                                <?php bloginfo("name"); ?>
                            </a>
                        </div>
                        
                        <nav class="[&_.sub-menu]:hidden [&_.sub-menu]:absolute [&_.sub-menu]:top-full [&_.sub-menu]:left-0 [&_.sub-menu]:min-w-[200px] [&_.sub-menu]:bg-white [&_.sub-menu]:border [&_.sub-menu]:border-gray-300 [&_.sub-menu]:shadow-md [&_.sub-menu]:z-50 [&_.sub-menu_a]:block [&_.sub-menu_a]:px-4 [&_.sub-menu_a]:py-2 [&_.sub-menu_a:hover]:bg-gray-100">
                            <?php
                            wp_nav_menu(array(
                                "theme_location" => "primary",
                                "container" => false,
                                "menu_class" => "flex flex-wrap list-none gap-6",
                                "fallback_cb" => function() {
                                    ?>
                                    <ul class="flex flex-wrap list-none gap-6">
                                        <li><a href="<?php echo home_url(); ?>" class="text-black no-underline hover:underline">首页</a></li>
                                        <?php
                                        $pages = get_pages(array("number" => 5, "parent" => 0));
                                        foreach ($pages as $page) {
                                            echo \'<li><a href="\' . get_page_link($page->ID) . \'" class="text-black no-underline hover:underline">\' . esc_html($page->post_title) . \'</a></li>\';
                                        }
                                        ?>
                                    </ul>
                                    <?php
                                }
                            ));
                            ?>
                        </nav>
                        
                        <div>
                            <form role="search" method="get" class="flex gap-2" action="<?php echo home_url(\'/\'); ?>">
                                <input type="search" class="px-2 py-1 border border-gray-300 text-sm" placeholder="搜索..." value="<?php echo get_search_query(); ?>" name="s" />
                                <button type="submit" class="px-4 py-1 bg-gray-800 text-white border-0 cursor-pointer hover:bg-black">搜索</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>
            
            <main class="min-h-[400px] mb-8 [&_.navigation.pagination]:my-8 [&_.navigation.pagination_.nav-links]:flex [&_.navigation.pagination_.nav-links]:justify-center [&_.navigation.pagination_.nav-links]:items-center [&_.navigation.pagination_.nav-links]:gap-2 [&_.navigation.pagination_.nav-links]:flex-wrap [&_.navigation.pagination_.page-numbers]:inline-block [&_.navigation.pagination_.page-numbers]:px-3 [&_.navigation.pagination_.page-numbers]:py-2 [&_.navigation.pagination_.page-numbers]:border [&_.navigation.pagination_.page-numbers]:border-gray-300 [&_.navigation.pagination_.page-numbers]:no-underline [&_.navigation.pagination_.page-numbers]:text-gray-800 [&_.navigation.pagination_.page-numbers]:bg-white [&_.navigation.pagination_.page-numbers]:transition-all [&_.navigation.pagination_.page-numbers:hover]:bg-gray-100 [&_.navigation.pagination_.page-numbers:hover]:border-gray-400 [&_.navigation.pagination_.page-numbers.current]:bg-gray-800 [&_.navigation.pagination_.page-numbers.current]:text-white [&_.navigation.pagination_.page-numbers.current]:border-gray-800">
                <div class="max-w-7xl mx-auto px-4">
        ';
    }
}