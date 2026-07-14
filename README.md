# Aether — WordPress Visual Editor & Contact Form Plugin

A powerful WordPress plugin that combines a visual editor with a fully-featured contact form system. No API tokens required, no activation limits — just install and use.

## Features

- **Visual Editor** — Intuitive Gutenberg-based editing experience
- **Contact Form** — `[aether_contact_form]` shortcode with customizable fields
- **Form Submissions** — Admin panel to view, filter, and export submissions (CSV)
- **REST API** — `/aether/v1/contact/form-config` and `/aether/v1/contact/submit` endpoints
- **Rate Limiting** — IP-based rate limiting (5 submissions/min) on REST endpoint
- **Security Hardened** — Nonce verification, input sanitization, CSRF protection, parameterized SQL queries
- **No Activation Required** — Free forever, no license keys, no API tokens

## Installation

1. Upload `wordexpress-aether.zip` via **WordPress → Plugins → Add New → Upload Plugin**
2. Click **Activate**
3. Use `[aether_contact_form]` shortcode in any page/post to display the contact form
4. Manage submissions at **Aether → Form Submissions**

## Shortcode

```
[aether_contact_form 
    title="联系我们"
    success_message="感谢您的留言！"]
```

### Custom Fields

Define custom fields via JSON:

```
[aether_contact_form fields='[{"name":"company","label":"公司名称","type":"text"},{"name":"message","label":"留言内容","type":"textarea","required":true}]']
```

## REST API

### Get Form Config
```
GET /wp-json/aether/v1/contact/form-config
```

### Submit Form
```
POST /wp-json/aether/v1/contact/submit
{
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+86 138 0000 0000",
  "subject": "Inquiry",
  "message": "Hello!"
}
```

Response:
```json
{
  "success": true,
  "message": "提交成功！"
}
```

## Database

Plugin creates table `wp_aether_contact_submissions` on activation with columns:

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| name | VARCHAR(255) | Visitor name |
| email | VARCHAR(255) | Visitor email |
| phone | VARCHAR(50) | Visitor phone |
| subject | VARCHAR(500) | Subject line |
| message | TEXT | Message content |
| ip_address | VARCHAR(45) | Visitor IP |
| user_agent | VARCHAR(500) | Browser UA |
| status | VARCHAR(20) | new / read / replied |
| created_at | DATETIME | Submission time |

## Security

- ✅ AJAX nonce verification on all admin endpoints
- ✅ CSRF token on frontend form submission
- ✅ Input sanitization (`sanitize_text_field`, whitelist validation)
- ✅ Parameterized SQL queries (`$wpdb->prepare`)
- ✅ Rate limiting on REST submit endpoint (5/min per IP)
- ✅ Role capability checks (`current_user_can`)
- ✅ Output escaping (`esc_html`, `esc_attr`, `esc_url`)

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+ or MariaDB 10.1+

## Changelog

### 1.1.0 (2026-07-14)
- Security audit and hardening
- Rate limiting on REST API
- Permission checks on AJAX handlers
- Input sanitization on settings save
- 11 new API route classes
- CSS optimization services
- Template preview and validator services

### 1.0.0 (2026-07-14)
- Initial release
- Visual editor integration
- Contact form with shortcode
- Admin submission management
- CSV export support

## License

GPL v2 or later

## Support

GitHub Issues: https://github.com/Byfitgear/aether/issues
