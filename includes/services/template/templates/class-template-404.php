<?php
/**
 * 404 Error Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_404
 */
class Aether_Template_404 {
    
    /**
     * Get 404 template
     */
    public static function get() {
        return '
        <div class="text-center py-16">
            <h1 class="text-6xl font-bold mb-4">404</h1>
            <h2 class="text-2xl mb-8">页面未找到</h2>
            
            <p class="text-gray-600 mb-8">抱歉，您访问的页面不存在。可能已被删除或地址输入错误。</p>
            
            <div class="mb-12">
                <a href="<?php echo home_url(); ?>" class="inline-block px-6 py-3 bg-gray-800 text-white no-underline hover:bg-black">返回首页</a>
            </div>
            
            <div class="max-w-md mx-auto mb-12">
                <h3 class="text-xl font-bold mb-4">搜索网站</h3>
                <?php get_search_form(); ?>
            </div>
            
            <div class="max-w-md mx-auto">
                <h3 class="text-xl font-bold mb-4">最新文章</h3>
                <ul class="list-none">
                    <?php
                    $recent_posts = wp_get_recent_posts(array(
                        "numberposts" => 5,
                        "post_status" => "publish"
                    ));
                    
                    foreach ($recent_posts as $post) {
                        echo \'<li class="mb-2"><a href="\' . get_permalink($post["ID"]) . \'" class="text-black hover:underline">\' . $post["post_title"] . \'</a></li>\';
                    }
                    ?>
                </ul>
            </div>
        </div>
        ';
    }
}