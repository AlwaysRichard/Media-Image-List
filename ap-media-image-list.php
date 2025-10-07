<?php
/**
 * Plugin Name: AP Media Image List
 * Description: Shortcode [media_image_table] — displays all media images (one per row) with filename, parent post (linked), categories, and an optional inline compact category editor (search + tri-state checkboxes). Includes draft/private posts.
 * Version: 1.5.3
 * Author: AlwaysPhotographing
 * License: MIT
 * Text Domain: ap-media-image-list
 */

if (!defined('ABSPATH')) exit;

class AP_Media_Image_List {
  public function __construct() {
    add_shortcode('media_image_table', [$this, 'render_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('init', [$this, 'handle_category_update']); // save categories
  }

  public function enqueue_assets() {
    // Compact CSS using WP small font preset; root categories no left padding; stable width while scrolling
    $css = "
      table.media-image-table { width:100%; border-collapse:collapse; }
      table.media-image-table th, table.media-image-table td { border-bottom:1px solid #e5e7eb; padding:8px; vertical-align:top; }
      table.media-image-table th { text-align:left; font-weight:600; }
      .mit-thumb { max-width:140px; }
      .mit-filename { font-size:var(--wp--preset--font-size--small, 12px); color:#6b7280; margin-top:4px; word-break:break-all; }
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

      .ap-note { font-size:var(--wp--preset--font-size--small, 12px); color:#6b7280; }
      .ap-warning { color:#6b7280; font-style:italic; font-size:var(--wp--preset--font-size--small, 12px); }
    ";
    wp_register_style('ap-media-image-list-inline', false);
    wp_enqueue_style('ap-media-image-list-inline');
    wp_add_inline_style('ap-media-image-list-inline', $css);

    // JS (search filter + tri-state parents but never auto-check parent from child)
    $js = "
      (function(){
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
        });
      })();
    ";
    wp_register_script('ap-media-image-list-inline', false);
    wp_enqueue_script('ap-media-image-list-inline');
    wp_add_inline_script('ap-media-image-list-inline', $js);
  }

  // Handle save (categories only).
  public function handle_category_update() {
    if (empty($_POST['ap_mit_update_cats']) || empty($_POST['ap_mit_post_id']) || empty($_POST['ap_mit_nonce'])) return;

    $post_id = intval($_POST['ap_mit_post_id']);
    if ($post_id <= 0) return;

    if (!wp_verify_nonce($_POST['ap_mit_nonce'], 'ap_mit_update_categories_' . $post_id)) return;

    $post = get_post($post_id);
    if (!$post) return;

    $taxonomy   = 'category';
    $tax_obj    = taxonomy_exists($taxonomy) ? get_taxonomy($taxonomy) : null;
    $assign_cap = $tax_obj && !empty($tax_obj->cap->assign_terms) ? $tax_obj->cap->assign_terms : 'edit_posts';

    $taxonomies    = get_object_taxonomies($post->post_type);
    $uses_category = taxonomy_exists($taxonomy) && (in_array($taxonomy, $taxonomies, true) || $post->post_type === 'post');

    if (!$uses_category || !current_user_can('edit_post', $post_id) || !current_user_can($assign_cap)) return;

    $selected = isset($_POST['ap_mit_cat_id']) ? array_map('intval', (array) $_POST['ap_mit_cat_id']) : [];
    wp_set_post_terms($post_id, $selected, $taxonomy, false);

    if (wp_get_referer()) { wp_safe_redirect(wp_get_referer()); exit; }
  }

  public function render_shortcode($atts) {
    $atts = shortcode_atts([
      'per_page'           => 50,
      'page_var'           => 'mit_page',
      'include_unattached' => 'true',
      'orderby'            => 'date',
      'order'              => 'DESC',
      'size'               => 'thumbnail',
      'show_editor'        => 'true', // NEW: show/hide the Edit Categories column
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
      $thumb_html = wp_get_attachment_image($attachment_id, $size, false, ['class' => 'mit-thumb']);
      if (!$thumb_html) continue;

      $file_path = get_attached_file($attachment_id);
      $filename  = $file_path ? wp_basename($file_path) : wp_basename(get_post_field('guid', $attachment_id));

      $parent_id = (int) get_post_field('post_parent', $attachment_id);
      $right_col = '';
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
              $edit_col = '<div class="ap-warning">You need to be logged in as an Administrator or Editor to use this feature.</div>';
            } elseif ($uses_category && current_user_can('edit_post', $parent_id) && current_user_can($assign_cap)) {
              $all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
              $selected_ids = wp_get_post_terms($parent_id, $taxonomy, ['fields' => 'ids']);
              $by_parent = [];
              if (!is_wp_error($all_terms)) {
                foreach ($all_terms as $t) { $by_parent[$t->parent][] = $t; }
              }

              $panel_id = 'ap-cat-panel-' . $parent_id;
              ob_start();
              echo '<div id="' . esc_attr($panel_id) . '" class="ap-cat-panel">';
              echo '<form method="post" action="' . esc_url(add_query_arg([], remove_query_arg([]))) . '">';

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

              echo '<div class="ap-cat-actions"><button type="submit" class="ap-button">Save Categories</button><span class="ap-note">Parents are indeterminate when only some children are selected.</span></div>';

              echo '</form></div>';
              $edit_col = ob_get_clean();
            } else {
              $edit_col = '<div class="mit-unattached">You need to be logged in as an Administrator or Editor to use this feature.</div>';
            }
          }

        } else {
          $right_col = '<div class="mit-unattached">Parent post not found</div>';
          if ($show_editor) $edit_col  = '<div class="mit-unattached">—</div>';
        }
      } else {
        if (!$include_unattached) continue;
        $right_col = '<div class="mit-unattached">Unattached image</div>';
        if ($show_editor) $edit_col  = '<div class="mit-unattached">Attach to a post to edit categories.</div>';
      }

      $rows_html .= '<tr>';
      $rows_html .= '<td style="width:190px;"><div>' . $thumb_html . '</div><div class="mit-filename" title="' . esc_attr($filename) . '">' . esc_html($filename) . '</div></td>';
      $rows_html .= '<td>' . $right_col . '</td>';
      if ($show_editor) {
        $rows_html .= '<td style="width:200px;">' . $edit_col . '</td>';
      }
      $rows_html .= '</tr>';
    }

    $out  = '<table class="media-image-table">';
    if ($show_editor) {
      $out .= '<thead><tr><th>Image</th><th>Post & Categories</th><th>Edit Categories</th></tr></thead>';
    } else {
      $out .= '<thead><tr><th>Image</th><th>Post & Categories</th></tr></thead>';
    }
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
