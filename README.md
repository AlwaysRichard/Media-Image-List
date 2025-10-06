# AP Media Image List

A lightweight WordPress plugin that lists **all image attachments** in a table—one image per row—with:

- **Thumbnail & filename** (column 1)
- **Parent post title** (linked) + the post’s categories (column 2)
- An inline, compact **category editor** with search, tri‑state checkboxes, tooltips, and no layout shift (column 3)

It works with **draft/private** posts and includes capability checks so only authorized users can modify categories.

> **Shortcode:** `[media_image_table]`

---

## ✨ Features

- Displays every image in the Media Library (paginated)
- Shows **draft**/**private** parent posts too
- Inline category editor with:
  - Search box + clear “×”
  - Tri‑state parents (indeterminate when some children selected)
  - **No auto‑select** of parent when selecting a child
  - Native **tooltips** on hover for long names
  - Non‑wrapping labels with **ellipsis**
  - Root categories have **no left indent**
  - Stable width while filtering (`scrollbar-gutter: stable`)
- Capability-aware (respects taxonomy `assign_terms` and `edit_post`)
- Minimal CSS, inherits WP font sizes (uses `--wp--preset--font-size--small`)
- No admin pages—just drop in the shortcode on any page

---

## 📦 Requirements

- WordPress **5.9+** (tested up to latest)
- PHP **7.4+**
- A theme that loads WP preset font variables (most do; graceful fallback is provided)

---

## 🧩 Installation

1. Copy `ap-media-image-list.php` into `/wp-content/plugins/ap-media-image-list/`
2. Activate **AP Media Image List** from *Plugins → Installed Plugins*
3. Create a **draft** page (recommended) and add the shortcode:

```text
[media_image_table]
```

Open the page (or preview) to use the tool.

> Tip: Using a draft/private page keeps the tool hidden from visitors.

---

## 🔧 Shortcode Attributes

| Attribute | Type | Default | Notes |
|---|---|---:|---|
| `per_page` | int | `50` | Items per page (pagination enabled when needed). |
| `page_var` | string | `mit_page` | URL query var for pagination. |
| `include_unattached` | bool | `true` | Show images with no parent post. |
| `orderby` | string | `date` | Any valid `WP_Query` orderby for attachments. |
| `order` | `ASC` \| `DESC` | `DESC` | Sort direction. |
| `size` | string | `thumbnail` | Any registered image size. |

**Examples**

```text
[media_image_table per_page="100" orderby="title" order="ASC" size="medium"]
[media_image_table include_unattached="false"]
```

---

## 🔐 Permissions & Security

- The editor column appears only for logged‑in users with:
  - `edit_post` on the parent post **and**
  - the taxonomy’s `assign_terms` capability (for `category` this is usually `edit_posts`).
- Requests are validated with nonces and use `wp_set_post_terms()`.

If you’re logged in but still see a warning, ensure your account has the correct role/capabilities and that the post type actually supports the **category** taxonomy.

---

## 🖱️ Category Editor UX

- **Search** filters the tree to show matching terms **and their ancestors**.
- **Clear “×”** button resets the filter.
- **Tri‑state parents:** parents become *indeterminate* when some (not all) children are selected.
- **Child clicks don’t check the parent** automatically.
- **No-wrap labels** with ellipsis keep rows compact.
- **Tooltips** on hover show the full category name.
- Root lists have **no left padding**; nested lists indent by 12px.
- The panel keeps a **stable width** while filtering (no jumping).

---

## 🖼️ Screenshots

Add screenshots to `assets/` and reference them here:

1. Media list table  
   `assets/screenshot-1.png`

2. Category editor (search + tri‑state)  
   `assets/screenshot-2.png`

```md
![Media table](assets/screenshot-1.png)
![Category editor](assets/screenshot-2.png)
```

---

## ⚙️ Customization

Small CSS hooks you might override in your theme or a Customizer snippet:

```css
/* Make the third column wider */
.media-image-table td:nth-child(3) { width: 240px; }

/* Tweak editor max-height */
.ap-cat-tree { max-height: 260px; }

/* Adjust nested indent */
.ap-cat-tree ul ul { padding-left: 16px; }
```

> The plugin intentionally uses light, scoped CSS and WP presets to minimize conflicts.

---

## 🧠 How it Works (Tech Notes)

- Lists image attachments via `WP_Query` (`post_type=attachment`, `post_mime_type=image`).
- For each image:
  - Renders the thumbnail + sanitized filename.
  - Finds the **parent post** (if any), renders its title (linked). Drafts use a preview link.
  - Shows current `category` terms.
  - Outputs a small form with a hierarchical checkbox tree.
- Saving posts the selected term IDs; the plugin updates categories with `wp_set_post_terms()` (nonced, capability-checked), then redirects back to the referring page.

---

## 🧪 Development

- Single-file plugin: `ap-media-image-list.php`
- Main class: `AP_Media_Image_List`
- Hooks used:
  - `add_shortcode( 'media_image_table', ... )`
  - `wp_enqueue_scripts` for inline CSS/JS
  - `init` for processing the save action
- Text domain: `ap-media-image-list`

### Local Setup

1. Drop the file into `wp-content/plugins/ap-media-image-list/`
2. Activate the plugin
3. Create/preview a page with `[media_image_table]`

### Coding Style

- Follows WordPress PHP coding standards.
- Escape everything (`esc_html`, `esc_attr`, `esc_url`) and validate with `wp_verify_nonce` & `current_user_can`.

---

## 🚧 Known Limitations

- Only manages the **default `category`** taxonomy. If your post type uses a custom taxonomy, this build won’t edit it (roadmap item).
- Large taxonomies (thousands of terms) will render, but filtering may feel slower in very old browsers.
- The editor UI is available **per image’s parent post**, not for unattached media.

---

## 🗺️ Roadmap

- Support custom taxonomies via shortcode attribute (e.g., `taxonomy="topic"`)
- Bulk actions (apply selected categories to multiple images)
- Keyboard navigation (↑/↓ to focus, space to toggle)
- Optional “Select all children” affordance on parent hover

---

## 📝 Changelog

- **1.5.2** — No-wrap labels with ellipsis; hover tooltips; stable width while filtering; minor style polish.
- **1.5.1** — Fixed filename rendering; widened editor column.
- **1.5.0** — Renamed to *AP Media Image List*; no root indent; child click no longer auto-checks parent.
- **1.4.x** — Category editor with search + tri‑state; login/permission warnings; support for drafts/private posts; compact styles.

---

## 🤝 Contributing

PRs welcome! Please:

1. Follow WP coding standards.
2. Keep CSS footprint minimal and scoped.
3. Test with drafts/private posts and on a non-admin account.
4. Update this `README.md` and bump the version header.

---

## 📄 License

MIT © AlwaysPhotographing

---

## 💬 Support

Open an issue on GitHub with:
- WordPress & PHP versions
- Theme name
- A screenshot of the problem
- Console errors (if any)

