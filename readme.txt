=== Aether — Visual Editor for WordPress ===
Contributors: byfitgear
Tags: visual editor, gutenberg alternative, html editor, contact form, page builder, wysiwyg, ai editor, open source, free, elementor alternative
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, zero-friction visual editor for WordPress. No registration required — install and start editing immediately. Includes a built-in contact form, image optimization, and CSS compilation.

== Description ==

Aether is a **minimalist WordPress visual editor** that lets you edit pages and posts with a clean, distraction-free interface. Unlike heavy page builders, Aether focuses on what matters: **writing and designing content without bloat**.

### ✨ Key Features

= 🚀 Zero Setup =
No API keys, no license tokens, no registration. Install from WordPress admin and start using it right away.

= 📝 Visual HTML Editor =
Edit your content in a WYSIWYG environment that produces clean, semantic HTML. Supports inline CSS, media embedding, and block-style editing.

= 📬 Built-In Contact Form =
Drop a contact form onto any page with a single shortcode: `[aether_contact_form]`. Includes honeypot spam protection, configurable fields, and admin notification settings.

= 🖼️ Image Optimization =
Automatic image compression (WebP, MozJPEG) and responsive image generation. Reduces page load times without sacrificing quality.

= 🎨 CSS Compilation =
Write custom CSS in the editor and have it compiled and injected automatically. Supports page-level and global stylesheets.

= 🤖 AI-Assisted Editing =
Optional AI features to help generate content, suggest layouts, and optimize your pages. Configure your own API credentials in settings.

= 🔒 Open Source & Free =
Released under GPL v2. No paywalls, no feature gating. Fork, modify, and redistribute freely.

= ⚡ Lightweight =
No jQuery dependency, no bloated asset bundles. Aether loads only what it needs.

### 📋 Requirements

* WordPress 5.0+
* PHP 7.4+
* MySQL 5.6+ or MariaDB 10.1+

### 🛠️ Installation

1. Upload the `aether` folder to `/wp-content/plugins/` or install directly from WordPress admin → Plugins → Add New → Upload Plugin.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Start editing! No configuration required.
4. For contact forms, add `[aether_contact_form]` to any post or page.
5. Optional: Configure AI credentials and optimization settings under **Aether → Settings**.

### 📧 Contact Form Usage

The built-in contact form is accessible via shortcode:

    [aether_contact_form]

Customize fields, recipients, and success messages in **Aether → Contact Form** settings. The form includes:

* Honeypot anti-spam protection
* Configurable email notifications
* Custom success/error messages
* Responsive design

== Frequently Asked Questions ==

= Do I need an API key or license token? =
No. Aether is completely free and requires no registration or activation.

= Is Aether compatible with Gutenberg? =
Yes. Aether works alongside the block editor and can also be used as a standalone visual editor.

= Can I use Aether with my theme? =
Absolutely. Aether is theme-agnostic and works with any standard WordPress theme.

= How do I submit contact form entries? =
Entries are stored in the database and viewable under **Aether → Submissions**. You can also export them as CSV.

= Does Aether slow down my site? =
No. Aether is designed to be lightweight. It only loads assets on pages where the editor or contact form is used.

= Is my data secure? =
Yes. Aether uses WordPress's built-in security mechanisms, sanitizes all user inputs, and never sends data to external servers unless you configure AI features.

== Screenshots ==

1. Visual Editor Interface — WYSIWYG editing with toolbar and content area
2. Contact Form Output — Responsive form with name, email, subject and message fields
3. Admin Settings Panel — Configure post types, AI credentials, optimization and design system
4. Image Optimization Dashboard — WebP and MozJPEG compression settings
5. AI-Assisted Editing Panel — Generate content and suggest layouts with AI assistance

== Changelog ==

= 1.0.5 =
* Added English `readme.txt` for WordPress.org plugin directory compatibility
* Updated plugin header description to English
* Comprehensive README with badges, features table, and changelog
* Added Screenshot Guide (SHOTGUIDE.md) for WordPress.org submission
* Demo landing page at byfitgear.github.io/aether-demo

= 1.0.4 =
* Added English `readme.txt` for WordPress.org compatibility
* Updated plugin header description to English
* All features from v1.0.3 included

= 1.0.3 =
* Added built-in contact form service with honeypot spam protection
* Removed mandatory API Token verification — plugin works out of the box
* Added GPL v2 license
* Improved settings page UX
* Fixed CSS compilation edge cases

= 1.0.2 =
* Initial public release
* Visual HTML editor with WYSIWYG support
* Image optimization (WebP, MozJPEG)
* CSS compilation engine
* AI-assisted editing (optional)
* Admin settings panel

== Upgrade Notice ==

= 1.0.5 =
WordPress.org submission ready. No breaking changes — safe to upgrade from any previous version.

= 1.0.4 =
Added English documentation for WordPress.org. No breaking changes.

= 1.0.3 =
Contact form feature added. No breaking changes — safe to upgrade from 1.0.2.

== Arbitrary Section ==

### Developer Integration

Aether exposes several hooks for developers:

* `aether_proxy_verify_ssl` — Filter SSL verification for proxy requests
* `aether_editor_css_output` — Customize CSS output in `<head>`
* `aether_contact_form_fields` — Modify contact form fields dynamically

### Contributing

Aether is open source and welcomes contributions. Please submit pull requests or report issues on GitHub.

### License

Aether is licensed under the GPL v2 or later. This means you are free to use, modify, and distribute it, even in commercial projects, as long as you comply with the GPL terms.
