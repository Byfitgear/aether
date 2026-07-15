(function() {
    var stored = localStorage.getItem('aether-dark-mode');
    var isDark = false;

    if (stored === 'true') {
        isDark = true;
    } else if (stored === 'false') {
        isDark = false;
    } else if (!('aether-dark-mode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        isDark = true;
    }

    function updateIcons() {
        var sunIcon = document.getElementById('icon-sun');
        var moonIcon = document.getElementById('icon-moon');
        if (sunIcon) sunIcon.classList.toggle('hidden', !isDark);
        if (moonIcon) moonIcon.classList.toggle('hidden', isDark);
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateIcons);
    } else {
        updateIcons();
    }

    var btn = document.getElementById('aether-dark-toggle');
    if (btn) {
        btn.addEventListener('click', function() {
            isDark = !isDark;
            document.documentElement.classList.toggle('dark', isDark);
            localStorage.setItem('aether-dark-mode', isDark.toString());
            updateIcons();
        });
    }
})();
