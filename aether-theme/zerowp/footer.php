</main>

<footer class="py-8 border-t border-gray-200 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400">
    <div class="flex items-center justify-between">
        <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?></p>
        <a href="<?php echo esc_url(get_bloginfo('rss2_url')); ?>" class="hover:text-gray-700 dark:hover:text-gray-300 transition-colors" aria-label="RSS Feed">
            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 5c7.18 0 13 5.82 13 13M6 11a7 7 0 017 7m-6 0a1 1 0 11-2 0 1 1 0 012 0z"/>
            </svg>
            RSS
        </a>
    </div>
</footer>
</div>

<?php wp_footer(); ?>
</body>

</html>
