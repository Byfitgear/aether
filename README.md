# Aether — Visual Editor for WordPress

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-green.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)](https://www.php.net/)
[![Version](https://img.shields.io/github/v/release/Byfitgear/aether)](https://github.com/Byfitgear/aether/releases)
[![GitHub stars](https://img.shields.io/github/stars/Byfitgear/aether?style=social)](https://github.com/Byfitgear/aether/stargazers)

> 🚀 **Minimalist WordPress visual editor** — No registration required. Install and start editing immediately.

## ✨ Features

| Feature | Description |
|---------|-------------|
| 📝 Visual HTML Editor | WYSIWYG editing that produces clean, semantic HTML |
| 📬 Contact Form | Built-in contact form with `[aether_contact_form]` shortcode |
| 🖼️ Image Optimization | Automatic WebP/MozJPEG compression |
| 🎨 CSS Compilation | Custom CSS compiled and injected automatically |
| 🤖 AI-Assisted Editing | Optional AI features for content generation |
| 🔒 Open Source | GPL v2 licensed, no paywalls |
| ⚡ Zero Setup | No API keys or license tokens needed |

## 📸 Screenshots

<!-- Replace these placeholder descriptions with actual screenshots -->
<!-- Screenshot 1: Visual Editor Interface -->
<!-- Screenshot 2: Contact Form Output -->
<!-- Screenshot 3: Admin Settings Panel -->
<!-- Screenshot 4: Image Optimization Dashboard -->
<!-- Screenshot 5: AI-Assisted Editing Panel -->

> 💡 **To add screenshots:** Capture images at 1200×900px and place them in a `screenshots/` folder. Update this README with markdown image links.

See [SHOTGUIDE.md](https://github.com/Byfitgear/aether/blob/main/SHOTGUIDE.md) for detailed screenshot instructions.

## 🚀 Quick Start

### Installation

1. **Download** the latest release ZIP file
2. **Upload** via WordPress admin: Plugins → Add New → Upload Plugin
3. **Activate** and start editing!

### Contact Form

Add a contact form to any page or post:

```
[aether_contact_form]
```

Configure the form under **Aether → Contact Form** in your WordPress admin.

### Settings

Access all settings under **Aether → Settings**:
- **General** — Post types, permissions
- **AI Credentials** — Configure AI provider API keys
- **Design System** — Import/export design tokens
- **Performance** — Image optimization and caching
- **Submissions** — View and manage contact form entries

## 📋 Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+ or MariaDB 10.1+

## 🛠️ Developer Integration

Aether exposes several hooks for developers:

```php
// Filter SSL verification for proxy requests
apply_filters('aether_proxy_verify_ssl', true);

// Customize CSS output
apply_filters('aether_editor_css_output', $css);

// Modify contact form fields
apply_filters('aether_contact_form_fields', $fields);
```

## 📄 License

Aether is released under the [GPL v2](LICENSE) license. You are free to use, modify, and distribute it, even in commercial projects.

## 🤝 Contributing

Contributions are welcome! Please submit pull requests or open issues on GitHub.

## 🔗 Links

- **[GitHub Repository](https://github.com/Byfitgear/aether)** — Source code and issues
- **[Latest Release](https://github.com/Byfitgear/aether/releases/latest)** — Download the plugin
- **[Demo Site](https://byfitgear.github.io/aether-demo/)** — See Aether in action
- **[Screenshot Guide](https://github.com/Byfitgear/aether/blob/main/SHOTGUIDE.md)** — How to capture plugin screenshots

---

Made with care for the WordPress community.
