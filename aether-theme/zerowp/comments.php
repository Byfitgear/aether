<?php
/**
 * 评论模板
 */
if (!post_password_required()) : ?>
<section id="comments" class="mt-12">
    <?php if (have_comments()) : ?>
        <h3 class="text-lg font-semibold mb-6 text-gray-900 dark:text-gray-100">
            <?php
            $comment_count = get_comments_number();
            printf(_n('%s 条评论', '%s 条评论', $comment_count, 'aether'), number_format_i18n($comment_count));
            ?>
        </h3>

        <ol class="space-y-6 list-none">
            <?php
            wp_list_comments(array(
                'style'      => 'ol',
                'short_ping' => true,
                'callback'   => 'aether_comment_callback',
            ));
            ?>
        </ol>

        <?php
        the_comments_pagination(array(
            'prev_text' => '←',
            'next_text' => '→',
        ));
        ?>
    <?php endif; ?>

    <?php
    comment_form(array(
        'class_form'      => 'mt-8',
        'class_submit'    => 'px-4 py-2 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors text-sm font-medium',
        'submit_button'   => '<button type="submit" class="%1$s">%2$s</button>',
    ));
    ?>
</section>
<?php endif;
