<?php
/**
 * Plugin Name: AP Media Image List
 * Description: Shortcode [media_image_table] — displays all media images (one per row) with filename, parent post (linked), categories, and an optional inline compact category editor (search + tri-state checkboxes). Includes draft/private posts.
 * Version: 1.6.1
 * Author: AlwaysPhotographing
 * License: MIT
 * Text Domain: ap-media-image-list
 */

if (!defined('ABSPATH')) exit;

// Helper to convert EXIF GPS array to DMS string (define only once)
if (!function_exists('ap_mit_exif_gps_to_dms')) {
  function ap_mit_exif_gps_to_dms($coord) {
      try {
        if (!is_array($coord) || count($coord) < 3) return '';
    $d = $coord[0]; $m = $coord[1]; $s = $coord[2];
    foreach (['d','m','s'] as $i => $k) {
      if (is_string($$k) && strpos($$k, '/') !== false) {
        list($num, $den) = explode('/', $$k, 2);
        if (is_numeric($num) && is_numeric($den) && $den != 0) {
          $$k = $num / $den;
        } else {
          $$k = 0;
        }
      } elseif (!is_numeric($$k)) {
        $$k = 0;
      }
    }
    // Avoid divide by zero or malformed data
    if (!is_numeric($d) || !is_numeric($m) || !is_numeric($s)) return '';
    return sprintf('%d° %d′ %d″', round($d), round($m), round($s));
      } catch (\Throwable $e) {
        error_log('ap_mit_exif_gps_to_dms error: ' . $e->getMessage());
        return '';
      }
  }
}

class AP_Media_Image_List {
  public function __construct() {
    add_shortcode('media_image_table', [$this, 'render_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_ap_mit_save_categories', [$this, 'ajax_save_categories']);
    add_action('wp_ajax_nopriv_ap_mit_save_categories', [$this, 'ajax_save_categories']);
  }

  public function enqueue_assets() {
    // Compact CSS using WP small font preset; root categories no left padding; stable width while scrolling
    $css = "
      table.media-image-table { width:100%; border-collapse:collapse; }
      table.media-image-table th, table.media-image-table td { border-bottom:1px solid #e5e7eb; padding:8px; vertical-align:top; }
      table.media-image-table th { text-align:left; font-weight:600; }
      /* All images constrained to fit within their boxes */
      .mit-thumb { max-width:140px; max-height:140px; width:auto; height:auto; object-fit:contain; }
      
      /* Thumbnail size - crops to fill 140x140 area */
      .size-thumbnail .mit-thumb { width:140px; height:140px; object-fit:cover; }
      
      /* Small and medium sizes - entire image fits within 140x140 maintaining aspect ratio */
      .size-small .mit-thumb, 
      .size-medium .mit-thumb { max-width:140px; max-height:140px; width:auto; height:auto; object-fit:contain; }
      
      /* Large size - entire image fits within 300x300 maintaining aspect ratio */
      .size-large .mit-thumb, 
      .size-full .mit-thumb { 
        max-width:300px !important; 
        max-height:300px !important; 
        width:auto !important; 
        height:auto !important; 
        object-fit:contain; 
      }
      .mit-filename { font-size:var(--wp--preset--font-size--small, 12px); color:#6b7280; margin-top:4px; word-break:break-all; }
      .mit-exif-popup-wrap { position:relative; display:inline-block; }
      .mit-exif-block { display:none; position:absolute; left:100%; top:0; z-index:10; min-width:420px; max-width:none; background:rgba(255,255,255,0.98); border:1px solid #e5e7eb; box-shadow:0 2px 8px #0001; font-family:monospace,monospace; font-size:13px; line-height:1.4; color:#444; padding:10px 14px; border-radius:6px; margin-left:10px; white-space:nowrap; }
      .mit-exif-popup-wrap:hover .mit-exif-block, .mit-exif-popup-wrap:focus-within .mit-exif-block, .mit-exif-popup-wrap.mit-exif-pinned .mit-exif-block { display:block; }
      .mit-exif-popup-wrap.mit-exif-pinned { z-index:100; }
      .mit-exif-block { cursor:text; user-select:text; }
      .mit-exif-popup-wrap .mit-thumb { cursor:pointer; }
      .mit-post-title { font-weight:600; margin-bottom:4px; font-size:calc(var(--wp--preset--font-size--small, 12px) + 1px); line-height:1.2; }
      .mit-cats { font-size:var(--wp--preset--font-size--small, 12px); color:#374151; line-height:1.2; }
      .mit-unattached { color:#9ca3af; font-style:italic; font-size:var(--wp--preset--font-size--small, 12px); }
      .mit-pagination { margin-top:10px; display:flex; gap:.4rem; flex-wrap:wrap; }
      .mit-pagination a, .mit-pagination span { padding:3px 6px; border:1px solid #e5e7eb; border-radius:3px; text-decoration:none; font-size:var(--wp--preset--font-size--small, 12px); }
      .mit-pagination .current { background:#f3f4f6; }

      /* Compact category panel */
      .ap-cat-panel { font-size:var(--wp--preset--font-size--small, 12px); line-height:1.2; max-width:290px; }
      .ap-cat-panel form { margin:0; }
      .ap-cat-search-wrap { position:relative; margin-bottom:4px; width:100%; }
      .ap-cat-search { width:100%; box-sizing:border-box; padding:5px 24px 5px 8px; border:1px solid #e5e7eb; border-radius:6px; background:#f3f4f6; font-size:var(--wp--preset--font-size--small, 12px); }
      .ap-cat-search::placeholder { color:#6b7280; }
      .ap-cat-clear { position:absolute; right:6px; top:50%; transform:translateY(-50%); font-size:14px; color:#6b7280; cursor:pointer; display:none; }
      .ap-cat-search-wrap.has-value .ap-cat-clear { display:block; }

      .ap-cat-tree { max-height:200px; overflow:auto; border:1px solid #e5e7eb; background:#fff; border-radius:6px; padding:5px; padding-left:3px; width:100%; scrollbar-gutter: stable; }
      .ap-cat-tree ul { list-style:none; margin:0; }
      .ap-cat-tree > ul { padding-left:0; }          /* root level: no indent */
      .ap-cat-tree ul ul { padding-left:12px; }      /* nested levels only */
      .ap-cat-tree li { margin:2px 0; }
      .ap-cat-tree li > label { display:flex; align-items:center; gap:6px; min-width:0; } /* allows ellipsis on label text */
      .ap-cat-check { width:12px; height:12px; flex:0 0 auto; }
      .ap-cat-label { flex:1 1 auto; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:var(--wp--preset--font-size--small, 12px); }

      .ap-cat-actions { margin-top:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
      .ap-button { padding:4px 7px; border:1px solid #d1d5db; border-radius:3px; background:#f9fafb; cursor:pointer; font-size:var(--wp--preset--font-size--small, 12px); }
      .ap-button:hover { background:#f3f4f6; }

      .ap-note { font-size:var(--wp--preset--font-size--small, 12px); color:#6b7280; display:inline-block; min-width:80px; margin-left:8px; }
      .ap-warning { color:#6b7280; font-style:italic; font-size:var(--wp--preset--font-size--small, 12px); }
      
      /* Inline category editor styles */
      .mit-edit-categories-btn { 
        margin-top: 8px; 
        padding: 2px 6px; 
        border: 1px solid #d1d5db; 
        border-radius: 3px; 
        background: #f9fafb; 
        cursor: pointer; 
        font-size: var(--wp--preset--font-size--small, 12px); 
        color: #374151;
        display: inline-block;
      }
      .mit-edit-categories-btn:hover { background: #f3f4f6; }
      .mit-inline-editor { 
        display: none; 
        margin-top: 8px; 
        padding: 8px; 
        border: 1px solid #e5e7eb; 
        border-radius: 6px; 
        background: #f9fafb; 
      }
      .mit-inline-editor.visible { display: block; }
    ";
    wp_register_style('ap-media-image-list-inline', false);
    wp_enqueue_style('ap-media-image-list-inline');
    wp_add_inline_style('ap-media-image-list-inline', $css);

    // JS (category panel + EXIF popup pinning + AJAX save)
    $ajax_url = admin_url('admin-ajax.php');
  $js = <<<JS
      (function(){
        // EXIF popup pinning logic
        document.addEventListener('DOMContentLoaded', function(){
          document.querySelectorAll('.mit-exif-popup-wrap').forEach(function(wrap){
            var img = wrap.querySelector('.mit-thumb');
            if (!img) return;
            img.addEventListener('click', function(e){
              e.preventDefault();
              e.stopPropagation();
              var pinned = wrap.classList.toggle('mit-exif-pinned');
              if (pinned) {
                // Unpin all others
                document.querySelectorAll('.mit-exif-popup-wrap.mit-exif-pinned').forEach(function(other){
                  if (other !== wrap) other.classList.remove('mit-exif-pinned');
                });
              }
            });
          });
          // Clicking anywhere else closes all pinned popups
          document.addEventListener('click', function(e){
            document.querySelectorAll('.mit-exif-popup-wrap.mit-exif-pinned').forEach(function(wrap){
              if (!wrap.contains(e.target)) wrap.classList.remove('mit-exif-pinned');
            });
          });
          // AJAX save for category editor
          document.querySelectorAll('.ap-cat-panel form').forEach(function(form){
            form.addEventListener('submit', function(ev){
              ev.preventDefault();
              var fd = new FormData(form);
              fd.append('action', 'ap_mit_save_categories');
              var btn = form.querySelector('button[type="submit"]');
              var note = form.querySelector('.ap-note');
              if (!note) return;
              note.textContent = 'Saving...';
              btn.disabled = true;
              fetch("{$ajax_url}", { method: "POST", body: fd, credentials: "same-origin" })
                .then(function(r){ return r.json(); })
                .then(function(data){
                  note.textContent = data.success ? "Saved!" : (data.data && data.data.message ? data.data.message : "Error");
                  if (data.success) {
                    // Update the categories display in the second column
                    updateCategoriesDisplay(form);
                    setTimeout(function(){ note.textContent = ''; }, 2000);
                  }
                })
                .catch(function(){ note.textContent = "Error"; })
                .finally(function(){ btn.disabled = false; });
            });
          });
        });
        
        // Function to update categories display in the second column
        function updateCategoriesDisplay(form) {
          // Get all checked categories from the form
          var checkedBoxes = form.querySelectorAll('input[name="ap_mit_cat_id[]"]:checked');
          var categoryNames = [];
          checkedBoxes.forEach(function(box) {
            var name = box.getAttribute('data-name');
            if (name) categoryNames.push(name);
          });
          
          // Find the corresponding categories display in the second column
          // The form is in the third column, we need to find the second column in the same row
          var currentRow = form.closest('tr');
          if (currentRow) {
            var categoriesDiv = currentRow.querySelector('td:nth-child(2) .mit-cats');
            if (categoriesDiv) {
              if (categoryNames.length > 0) {
                categoriesDiv.innerHTML = categoryNames.join(', ');
              } else {
                categoriesDiv.innerHTML = '<span class="mit-unattached">No categories</span>';
              }
            }
          }
        }
        
        // Category panel logic (unchanged)
        function applyFilter(panel, query){
          query = (query || '').toLowerCase();
          var wrap = panel.querySelector('.ap-cat-search-wrap');
          if(wrap){ wrap.classList.toggle('has-value', query.length>0); }
          var root = panel.querySelector('.ap-cat-tree > ul');
          if(!root) return;
          root.querySelectorAll(':scope > .ap-cat-li').forEach(function(li){ filterNode(li, query); });
        }
        function filterNode(li, q){
          var label = (li.getAttribute('data-label')||'').toLowerCase();
          var selfMatch = !q || label.indexOf(q) !== -1;
          var childMatch = false;
          var children = li.querySelectorAll(':scope > ul > .ap-cat-li');
          children.forEach(function(ch){
            if(filterNode(ch, q)) childMatch = true;
          });
          var visible = selfMatch || childMatch;
          li.style.display = visible ? '' : 'none';
          return visible;
        }
        function setChildren(li, checked){
          li.querySelectorAll(':scope ul .ap-cat-check').forEach(function(childCb){
            childCb.checked = checked;
            childCb.indeterminate = false;
          });
        }
        // Do NOT auto-check parent when children change; only show indeterminate.
        function updateParents(li){
          var parentLi = li.parentElement.closest('.ap-cat-li');
          if(!parentLi) return;
          var parentCb = parentLi.querySelector(':scope > label > .ap-cat-check');
          if(!parentCb) return;
          var childCbs = parentLi.querySelectorAll(':scope > ul .ap-cat-check');
          var total = 0, checked = 0;
          childCbs.forEach(function(c){ total++; if(c.checked) checked++; });
          if(checked === 0 || checked === total){
            parentCb.indeterminate = false;
          }else{
            parentCb.indeterminate = true;
          }
          updateParents(parentLi);
        }
        function attach(panel){
          var search = panel.querySelector('.ap-cat-search');
          var clearBtn = panel.querySelector('.ap-cat-clear');
          if(search){
            search.addEventListener('input', function(){ applyFilter(panel, this.value); });
          }
          if(clearBtn){
            clearBtn.addEventListener('click', function(e){
              e.preventDefault();
              search.value=''; applyFilter(panel, '');
              search.focus();
            });
          }
          panel.addEventListener('change', function(e){
            var cb = e.target.closest('.ap-cat-check');
            if(!cb) return;
            var li = cb.closest('.ap-cat-li');
            var isParent = !!li.querySelector(':scope > ul');
            if(isParent && e.target === cb){
              setChildren(li, cb.checked);
            }
            updateParents(li);
          });
          panel.querySelectorAll('.ap-cat-label').forEach(function(span){
            if(!span.getAttribute('title')) span.setAttribute('title', span.textContent.trim());
          });
          panel.querySelectorAll('.ap-cat-li').forEach(function(li){ updateParents(li); });
          applyFilter(panel, '');
        }
        document.addEventListener('DOMContentLoaded', function(){
          document.querySelectorAll('.ap-cat-panel').forEach(attach);
          
          // Handle Edit Categories button clicks
          document.querySelectorAll('.mit-edit-categories-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
              e.preventDefault();
              var editor = btn.nextElementSibling;
              if (editor && editor.classList.contains('mit-inline-editor')) {
                var isVisible = editor.classList.contains('visible');
                
                // Hide all other editors first
                document.querySelectorAll('.mit-inline-editor.visible').forEach(function(otherEditor) {
                  if (otherEditor !== editor) {
                    otherEditor.classList.remove('visible');
                    var otherBtn = otherEditor.previousElementSibling;
                    if (otherBtn) otherBtn.textContent = 'Edit Categories';
                  }
                });
                
                // Toggle current editor
                if (isVisible) {
                  editor.classList.remove('visible');
                  btn.textContent = 'Edit Categories';
                } else {
                  editor.classList.add('visible');
                  btn.textContent = 'Hide Editor';
                }
              }
            });
          });
        });
      })();
JS;
    wp_register_script('ap-media-image-list-inline', false);
    wp_enqueue_script('ap-media-image-list-inline');
    wp_add_inline_script('ap-media-image-list-inline', $js);
  }

  // AJAX handler for category save
  public function ajax_save_categories() {
  // Debug: log when handler is called
  error_log('AP_Media_Image_List: ajax_save_categories called');
  // Set content type header for JSON
  header('Content-Type: application/json; charset=utf-8');
    // Check nonce and permissions
    if (empty($_POST['ap_mit_update_cats']) || empty($_POST['ap_mit_post_id']) || empty($_POST['ap_mit_nonce'])) {
      wp_send_json_error(['message' => 'Missing data.']);
    }
    $post_id = intval($_POST['ap_mit_post_id']);
    if ($post_id <= 0) wp_send_json_error(['message' => 'Invalid post.']);
    if (!wp_verify_nonce($_POST['ap_mit_nonce'], 'ap_mit_update_categories_' . $post_id)) wp_send_json_error(['message' => 'Invalid nonce.']);
    $post = get_post($post_id);
    if (!$post) wp_send_json_error(['message' => 'Post not found.']);
    $taxonomy   = 'category';
    $tax_obj    = taxonomy_exists($taxonomy) ? get_taxonomy($taxonomy) : null;
    $assign_cap = $tax_obj && !empty($tax_obj->cap->assign_terms) ? $tax_obj->cap->assign_terms : 'edit_posts';
    $taxonomies    = get_object_taxonomies($post->post_type);
    $uses_category = taxonomy_exists($taxonomy) && (in_array($taxonomy, $taxonomies, true) || $post->post_type === 'post');
    if (!$uses_category || !current_user_can('edit_post', $post_id) || !current_user_can($assign_cap)) wp_send_json_error(['message' => 'Permission denied.']);
    $selected = isset($_POST['ap_mit_cat_id']) ? array_map('intval', (array) $_POST['ap_mit_cat_id']) : [];
    wp_set_post_terms($post_id, $selected, $taxonomy, false);
    wp_send_json_success(['message' => 'Saved.']);
  }

  public function render_shortcode($atts) {
    $atts = shortcode_atts([
      'per_page'           => 50,
      'page_var'           => 'mit_page',
      'include_unattached' => 'true',
      'orderby'            => 'date',
      'order'              => 'DESC',
      'size'               => 'thumbnail',
      'show_editor'        => 'true',
      'metadata_display'   => 'basic', // basic|json
    ], $atts, 'media_image_table');

    $per_page = intval($atts['per_page']); if ($per_page === 0) $per_page = 50;
    $page_var = sanitize_key($atts['page_var']);
    $current_page = isset($_GET[$page_var]) ? max(1, intval($_GET[$page_var])) : 1;

    $include_unattached = filter_var($atts['include_unattached'], FILTER_VALIDATE_BOOLEAN);
    $orderby = sanitize_key($atts['orderby']);
    $order   = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';
    $size    = sanitize_text_field($atts['size']);
    $show_editor = filter_var($atts['show_editor'], FILTER_VALIDATE_BOOLEAN);

    $query_args = [
      'post_type'      => 'attachment',
      'post_status'    => 'inherit',
      'post_mime_type' => 'image',
      'orderby'        => $orderby,
      'order'          => $order,
      'posts_per_page' => $per_page,
      'paged'          => $current_page,
      'fields'         => 'ids',
    ];

    $attachments_q = new WP_Query($query_args);
    if (!$attachments_q->have_posts()) return '<p>No media images found.</p>';

    $rows_html = '';
    foreach ($attachments_q->posts as $attachment_id) {
      try {
  $parent_id = (int) get_post_field('post_parent', $attachment_id);
      } catch (\Throwable $e) {
        $rows_html .= '<tr><td colspan="3" style="color:red;font-size:13px;">EXIF ERROR: ' . esc_html($e->getMessage()) . '</td></tr>';
        continue;
      }
      // ...no debug output...
  // Reset right_col for each row
  $right_col = '';
      $thumb_html = wp_get_attachment_image($attachment_id, $size, false, ['class' => 'mit-thumb']);
      if (!$thumb_html) continue;

      // Get original file path (not a resized version)
      $upload_dir = wp_get_upload_dir();
      $relative_path = get_post_meta($attachment_id, '_wp_attached_file', true);
      $original_file_path = $relative_path ? trailingslashit($upload_dir['basedir']) . $relative_path : '';
      $filename  = $original_file_path ? wp_basename($original_file_path) : wp_basename(get_post_field('guid', $attachment_id));

      // EXIF extraction from original file
      $exif = [];
      if ($original_file_path && file_exists($original_file_path)) {
        if (function_exists('wp_read_image_metadata')) {
          $meta = wp_read_image_metadata($original_file_path);
          if ($meta && is_array($meta)) {
            $exif = $meta;
          }
        } elseif (function_exists('exif_read_data')) {
          $exif = @exif_read_data($original_file_path, 0, true);
        }
      }

      // Format EXIF info (reset for each row)
      $exif_lines = array();
      // Camera
      $make  = $exif['IFD0']['Make'] ?? $exif['EXIF']['Make'] ?? $exif['Make'] ?? '';
      $model = $exif['IFD0']['Model'] ?? $exif['EXIF']['Model'] ?? $exif['Model'] ?? '';
      if ($make || $model) {
        $exif_lines[] = 'Camera: ' . esc_html(trim($make . ' ' . $model));
      }
      // Lens
      $lens_make  = $exif['EXIF']['LensMake'] ?? $exif['IFD0']['LensMake'] ?? $exif['LensMake'] ?? '';
      $lens_model = $exif['EXIF']['LensModel'] ?? $exif['IFD0']['LensModel'] ?? $exif['UndefinedTag:0xA434'] ?? $exif['LensModel'] ?? '';
      if ($lens_make || $lens_model) {
        $exif_lines[] = 'Lens: ' . esc_html(trim($lens_make . ' ' . $lens_model));
      }
      // Exposure
      $focal = $exif['EXIF']['FocalLength'] ?? $exif['EXIF']['FocalLengthIn35mmFilm'] ?? $exif['FocalLength'] ?? '';
      // If focal is in fraction format (e.g., 3000/10), calculate value
      if (is_string($focal) && strpos($focal, '/') !== false) {
        list($num, $den) = explode('/', $focal, 2);
        if (is_numeric($num) && is_numeric($den) && $den != 0) {
          $focal = round($num / $den);
        }
      }
      $fnum = $exif['EXIF']['FNumber'] ?? $exif['FNumber'] ?? '';
      $exposure_time = $exif['EXIF']['ExposureTime'] ?? $exif['ExposureTime'] ?? '';
      $iso = $exif['EXIF']['ISOSpeedRatings'] ?? $exif['ISOSpeedRatings'] ?? ($exif['EXIF']['PhotographicSensitivity'] ?? $exif['PhotographicSensitivity'] ?? '');
      $exposure = [];
      if ($focal) $exposure[] = (is_array($focal) ? implode(' ', $focal) : $focal) . 'mm';
      if ($fnum) $exposure[] = 'f/' . (is_array($fnum) ? reset($fnum) : $fnum);
      if ($exposure_time) $exposure[] = (is_array($exposure_time) ? reset($exposure_time) : $exposure_time) . 's';
      if ($iso) $exposure[] = 'ISO ' . (is_array($iso) ? reset($iso) : $iso);
      if ($exposure) $exif_lines[] = 'Exposure: ' . esc_html(implode(' ', $exposure));
      // Date
      $date = $exif['EXIF']['DateTimeOriginal'] ?? $exif['IFD0']['DateTime'] ?? $exif['DateTimeOriginal'] ?? '';
      if ($date) {
        $exif_lines[] = 'Date: ' . esc_html($date);
      }
      // Location (GPS) (not present in your sample, but keep logic)
      $gps_lat = '';
      $gps_lon = '';
      $gps_dir = '';
      if (!empty($exif['GPS']['GPSLatitude']) && !empty($exif['GPS']['GPSLatitudeRef'])) {
        $gps_lat = $exif['GPS']['GPSLatitudeRef'] . ' ' . ap_mit_exif_gps_to_dms($exif['GPS']['GPSLatitude']);
      }
      if (!empty($exif['GPS']['GPSLongitude']) && !empty($exif['GPS']['GPSLongitudeRef'])) {
        $gps_lon = $exif['GPS']['GPSLongitudeRef'] . ' ' . ap_mit_exif_gps_to_dms($exif['GPS']['GPSLongitude']);
      }
      if (!empty($exif['GPS']['GPSImgDirection'])) {
        $gps_dir = $exif['GPS']['GPSImgDirection'] . (isset($exif['GPS']['GPSImgDirectionRef']) ? ' ' . $exif['GPS']['GPSImgDirectionRef'] : '');
      }
      $gps_parts = array_filter([$gps_lat, $gps_lon, $gps_dir]);
      if ($gps_parts) {
        $exif_lines[] = 'Location: ' . esc_html(implode(' ', $gps_parts));
      }
      // File (always add once, at the end, and never duplicate)
      $exif_lines = array_values(array_filter($exif_lines, function($line) use ($filename) {
        return trim($line) !== ('File: ' . $filename);
      }));
      $exif_lines[] = 'File: ' . esc_html($filename);
      if ($atts['metadata_display'] === 'json') {
        $exif_block = '<div class="mit-exif-block" style="white-space:pre-wrap;word-break:break-all;">' . esc_html(json_encode($exif)) . '</div>';
      } else {
        $exif_block = '<div class="mit-exif-block">' . implode('<br>', $exif_lines) . '</div>';
      }

      $edit_col  = '';

      if ($parent_id > 0) {
        $parent = get_post($parent_id);
        if ($parent) {
          $post_title = get_the_title($parent_id);
          $status     = get_post_status($parent_id);
          $permalink  = ($status === 'publish') ? get_permalink($parent_id) : get_preview_post_link($parent);
          $post_title_esc = esc_html($post_title ?: '(untitled)');
          $post_link_esc  = esc_url($permalink ?: '#');

          $cat_terms = taxonomy_exists('category') ? wp_get_post_terms($parent_id, 'category') : [];
          $tax_cats  = (!empty($cat_terms) && !is_wp_error($cat_terms)) ? wp_list_pluck($cat_terms, 'name') : [];
          $cats_text = !empty($tax_cats) ? esc_html(implode(', ', $tax_cats)) : '';

          $right_col .= '<div class="mit-post-title"><a href="' . $post_link_esc . '">' . $post_title_esc . '</a>';
          if ($status && $status !== 'publish') $right_col .= ' <span class="mit-unattached">(' . esc_html($status) . ')</span>';
          $right_col .= '</div>';
          $right_col .= '<div class="mit-cats">' . ($cats_text !== '' ? $cats_text : '<span class="mit-unattached">No categories</span>') . '</div>';

          if ($show_editor) {
            $taxonomy    = 'category';
            $tax_obj     = taxonomy_exists($taxonomy) ? get_taxonomy($taxonomy) : null;
            $assign_cap  = $tax_obj && !empty($tax_obj->cap->assign_terms) ? $tax_obj->cap->assign_terms : 'edit_posts';
            $taxonomies  = get_object_taxonomies($parent->post_type);
            $uses_category = taxonomy_exists($taxonomy) && (in_array($taxonomy, $taxonomies, true) || $parent->post_type === 'post');

            if (!is_user_logged_in()) {
              $right_col .= '<div class="ap-warning" style="margin-top:8px;">You need to be logged in as an Administrator or Editor to use this feature.</div>';
            } elseif ($uses_category && current_user_can('edit_post', $parent_id) && current_user_can($assign_cap)) {
              $all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
              $selected_ids = wp_get_post_terms($parent_id, $taxonomy, ['fields' => 'ids']);
              $by_parent = [];
              if (!is_wp_error($all_terms)) {
                foreach ($all_terms as $t) { $by_parent[$t->parent][] = $t; }
              }

              $panel_id = 'ap-cat-panel-' . $parent_id;
              $right_col .= '<button class="mit-edit-categories-btn">Edit Categories</button>';
              
              ob_start();
              echo '<div class="mit-inline-editor">';
              echo '<div id="' . esc_attr($panel_id) . '" class="ap-cat-panel">';
              echo '<form method="post">';

              echo '<div class="ap-cat-search-wrap">';
              echo '<input type="search" class="ap-cat-search" placeholder="Search Categories" />';
              echo '<span class="ap-cat-clear" title="Clear">×</span>';
              echo '</div>';

              echo '<div class="ap-cat-tree">';
              echo $this->render_cat_tree_ul(0, $by_parent, $selected_ids, 'ap_mit_cat_id');
              echo '</div>';

              wp_nonce_field('ap_mit_update_categories_' . $parent_id, 'ap_mit_nonce');
              echo '<input type="hidden" name="ap_mit_update_cats" value="1" />';
              echo '<input type="hidden" name="ap_mit_post_id" value="' . esc_attr($parent_id) . '" />';

              echo '<div class="ap-cat-actions"><button type="submit" class="ap-button">Save Categories</button><span class="ap-note"></span></div>';

              echo '</form></div>';
              echo '</div>';
              $right_col .= ob_get_clean();
            } else {
              $right_col .= '<div class="ap-warning" style="margin-top:8px;">You need to be logged in as an Administrator or Editor to use this feature.</div>';
            }
          }

        } else {
          $right_col = '<div class="mit-unattached">Parent post not found</div>';
          if ($show_editor) {
            $right_col .= '<div class="ap-warning" style="margin-top:8px;">—</div>';
          }
        }
      } else {
        if (!$include_unattached) continue;
        $right_col = '<div class="mit-unattached">Unattached image</div>';
        if ($show_editor) {
          $right_col .= '<div class="ap-warning" style="margin-top:8px;">Attach to a post to edit categories.</div>';
        }
      }

      // Adjust column width based on image size
      $col_width = ($size === 'large' || $size === 'full') ? '320px' : '190px';
      
      $rows_html .= '<tr>';
      $rows_html .= '<td style="width:' . $col_width . '; vertical-align:top;">'
        . '<div class="mit-exif-popup-wrap size-' . esc_attr($size) . '">' . $thumb_html . $exif_block . '</div>'
        . '</td>';
      $rows_html .= '<td style="vertical-align:top; position:relative;">'
        . $right_col
        . '</td>';
      $rows_html .= '</tr>';
    }

    $out  = '<table class="media-image-table">';
    $out .= '<thead><tr><th>Image</th><th>Post & Categories</th></tr></thead>';
    $out .= '<tbody>' . $rows_html . '</tbody>';
    $out .= '</table>';

    if ($per_page > 0 && $attachments_q->max_num_pages > 1) {
      $out .= $this->pagination_links($attachments_q->max_num_pages, $current_page, $page_var);
    }

    wp_reset_postdata();
    return $out;
  }

  private function render_cat_tree_ul($parent_id, $by_parent, $selected_ids, $name_attr) {
    if (empty($by_parent[$parent_id])) return '';
    $html = '<ul>';
    foreach ($by_parent[$parent_id] as $term) {
      $checked = in_array($term->term_id, $selected_ids, true) ? ' checked' : '';
      $has_children = !empty($by_parent[$term->term_id]);
      $html .= '<li class="ap-cat-li" data-label="' . esc_attr($term->name) . '">';
      $html .= '<label><input type="checkbox" class="ap-cat-check" name="' . esc_attr($name_attr) . '[]" value="' . esc_attr($term->term_id) . '" data-name="' . esc_attr($term->name) . '"' . $checked . '> <span class="ap-cat-label" title="' . esc_attr($term->name) . '">' . esc_html($term->name) . '</span></label>';
      if ($has_children) {
        $html .= $this->render_cat_tree_ul($term->term_id, $by_parent, $selected_ids, $name_attr);
      }
      $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
  }

  private function pagination_links($max_pages, $current_page, $page_var) {
    $links = paginate_links([
      'base'      => esc_url_raw(add_query_arg($page_var, '%#%')),
      'format'    => '',
      'current'   => $current_page,
      'total'     => $max_pages,
      'type'      => 'array',
      'prev_text' => '« Prev',
      'next_text' => 'Next »',
    ]);
    if (empty($links) || !is_array($links)) return '';
    return '<div class="mit-pagination">' . implode('', $links) . '</div>';
  }
}

new AP_Media_Image_List();