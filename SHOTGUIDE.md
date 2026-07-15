# Aether Plugin — Screenshot Guide for WordPress.org

This document describes exactly what screenshots you need to capture for the WordPress.org plugin submission.

## Required Screenshots (5 images)

Each screenshot should be **1200×900 pixels** (WordPress.org minimum: 1200×900).

---

### Screenshot 1: Visual Editor Interface

**What to capture:**
- Open any page/post in WordPress admin
- Click "Aether Editor" link in the post list, or the editor button on the edit screen
- Show the full WYSIWYG editing area with:
  - The top toolbar (formatting buttons, media insert, AI button)
  - The content editing area with some sample text
  - Side panel if visible (settings, blocks, etc.)

**How to get there:**
1. Go to **Pages → All Pages** in WordPress admin
2. Hover over any page → click **"aether 编辑器"** link
3. Take screenshot of the editor view

**File name:** `screenshot-1.png`
**Alt text:** "Visual Editor Interface — WYSIWYG editing with toolbar and content area"

---

### Screenshot 2: Contact Form Output

**What to capture:**
- A published page/post that contains the contact form
- Show the rendered form on the front end
- Should include: Name field, Email field, Subject field, Message textarea, Submit button

**How to get there:**
1. Edit any page or post
2. Add the shortcode `[aether_contact_form]`
3. Preview or publish the page
4. Open the published page in a browser
5. Take screenshot of the form

**File name:** `screenshot-2.png`
**Alt text:** "Contact Form — Responsive form with name, email, subject and message fields"

---

### Screenshot 3: Admin Settings Panel

**What to capture:**
- The Aether settings page in WordPress admin
- Go to **Aether → Settings**
- Show the settings tabs/panels:
  - General settings (post types, permissions)
  - AI credentials tab
  - Design system tab
  - Performance/Optimization tab

**How to get there:**
1. WordPress admin sidebar → **Aether → Settings**
2. Take screenshot showing the settings interface

**File name:** `screenshot-3.png`
**Alt text:** "Admin Settings Panel — Configure post types, AI credentials, optimization and design system"

---

### Screenshot 4: Image Optimization Dashboard

**What to capture:**
- The image optimization/performance tab in settings
- Show compression settings (WebP, MozJPEG options)
- Any performance metrics or file size comparisons

**How to get there:**
1. WordPress admin → **Aether → Settings**
2. Click the **Performance** or **Optimization** tab
3. Take screenshot of the optimization controls

**File name:** `screenshot-4.png`
**Alt text:** "Image Optimization Dashboard — WebP and MozJPEG compression settings"

---

### Screenshot 5: AI-Assisted Editing Panel

**What to capture:**
- The AI dialog/panel inside the editor
- Click the AI button in the editor toolbar
- Show the AI interface (content generation, rewrite suggestions, layout options)

**How to get there:**
1. Open Aether Editor on any page/post
2. Click the **AI** button in the toolbar
3. Take screenshot of the AI dialog

**File name:** `screenshot-5.png`
**Alt text:** "AI-Assisted Editing Panel — Generate content and suggest layouts"

---

## How to Prepare Screenshots

### Option 1: Use a Local WordPress Install
1. Install WordPress locally (LocalWP, MAMP, Docker)
2. Install and activate Aether plugin
3. Follow the steps above to navigate to each screen
4. Use built-in screenshot tool (`Cmd+Shift+4` on Mac)
5. Resize to 1200×900px using any image editor

### Option 2: Use Browser DevTools
1. Open WordPress admin in Chrome/Firefox
2. Open DevTools (`F12`)
3. Toggle device toolbar → set to 1920×1080
4. Take full-page screenshot

### Option 3: Use a Staging Site
1. Deploy Aether to a staging WordPress site
2. Navigate through all features
3. Capture screenshots at the required resolution

## After Capturing

1. Place all 5 PNG files in the `screenshots/` folder
2. Update `readme.txt` with actual screenshot URLs
3. Rebuild the plugin ZIP
4. Push to GitHub and create new release

## Screenshot URL Format for WordPress.org

When submitting to WordPress.org, screenshots are uploaded directly. For GitHub README, use relative paths:

```markdown
== Screenshots ==
1. Visual Editor Interface — [screenshot-1.png](screenshots/screenshot-1.png)
2. Contact Form Output — [screenshot-2.png](screenshots/screenshot-2.png)
...
```
