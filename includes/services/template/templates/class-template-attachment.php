<?php
/**
 * Attachment Template - Media/attachment pages
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Attachment
 */
class Aether_Template_Attachment {
    
    /**
     * Get attachment template
     */
    public static function get() {
        return '
        <article>
            <header class="mb-8 pb-4 border-b border-gray-300">
                <h1 class="text-3xl font-bold mb-4"><?php the_title(); ?></h1>
                
                <div class="text-gray-600 text-sm">
                    <span>上传于: <?php echo get_the_date(); ?></span>
                    <span> | 上传者: <?php the_author(); ?></span>
                    <?php if (get_the_parent()) : ?>
                        <span> | 附加到: 
                            <a href="<?php echo get_permalink(get_the_parent()); ?>" class="hover:underline">
                                <?php echo get_the_title(get_the_parent()); ?>
                            </a>
                        </span>
                    <?php endif; ?>
                </div>
            </header>
            
            <div class="mb-8 text-center">
                <?php
                if (wp_attachment_is_image()) {
                    // Display image
                    echo wp_get_attachment_image(get_the_ID(), "large", false, array("class" => "max-w-full h-auto mx-auto"));
                } else {
                    // Display download link for non-image attachments
                    $attachment_url = wp_get_attachment_url();
                    $attachment_meta = wp_get_attachment_metadata();
                    $file_size = size_format(filesize(get_attached_file(get_the_ID())));
                    $file_type = get_post_mime_type();
                    
                    echo \'<div class="border border-gray-300 p-8 inline-block">\';
                    echo \'<div class="text-6xl mb-4 text-gray-400">📄</div>\';
                    echo \'<div class="mb-2"><strong>文件类型:</strong> \' . $file_type . \'</div>\';
                    echo \'<div class="mb-4"><strong>文件大小:</strong> \' . $file_size . \'</div>\';
                    echo \'<a href="\' . esc_url($attachment_url) . \'" class="inline-block px-4 py-2 bg-black text-white hover:bg-gray-800" download>下载文件</a>\';
                    echo \'</div>\';
                }
                ?>
            </div>
            
            <?php if (get_the_content()) : ?>
                <div class="aether-wysiwyg-content mb-8">
                    <?php the_content(); ?>
                </div>
            <?php endif; ?>
            
            <?php
            // Show image metadata for images
            if (wp_attachment_is_image()) {
                $metadata = wp_get_attachment_metadata();
                if ($metadata) {
                    echo \'<div class="mt-8 pt-4 border-t border-gray-300">\';
                    echo \'<h3 class="text-lg font-bold mb-4">图片信息</h3>\';
                    echo \'<div class="grid grid-cols-2 gap-4 text-sm">\';
                    
                    if (isset($metadata["width"]) && isset($metadata["height"])) {
                        echo \'<div><strong>尺寸:</strong> \' . $metadata["width"] . \' × \' . $metadata["height"] . \' 像素</div>\';
                    }
                    
                    $file_size = size_format(filesize(get_attached_file(get_the_ID())));
                    echo \'<div><strong>文件大小:</strong> \' . $file_size . \'</div>\';
                    
                    echo \'</div>\';
                    echo \'</div>\';
                }
            }
            ?>
            
            <?php if (get_the_parent()) : ?>
                <nav class="mt-8 pt-4 border-t border-gray-300">
                    <a href="<?php echo get_permalink(get_the_parent()); ?>" class="text-black hover:underline">
                        ← 返回 <?php echo get_the_title(get_the_parent()); ?>
                    </a>
                </nav>
            <?php endif; ?>
        </article>
        ';
    }
}