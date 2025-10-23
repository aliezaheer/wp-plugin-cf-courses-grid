<?php
/*
Plugin Name: Custom Plugin - LearnDash Courses Grid
Description: Grid + Facets for LearnDash courses. Shortcode [cf_courses_grid].
Version: 0.2
Author: Ali Zaheer
*/

if (!defined('ABSPATH')) exit;

class CF_Courses_Grid {
    public function __construct() {
        add_action('init', array($this,'register_shortcodes'));
        add_action('wp_enqueue_scripts', array($this,'enqueue_assets'));
        add_action('rest_api_init', array($this,'register_rest_routes'));
    }

    public function register_shortcodes(){
        add_shortcode('cf_courses_grid', array($this,'render_grid_shortcode'));
    }

    public function enqueue_assets(){
        $plugin_dir = plugin_dir_path(__FILE__);
        $css_file = plugin_dir_url(__FILE__) . 'assets/css/cf-grid.css';
        $js_file = plugin_dir_url(__FILE__) . 'assets/js/cf-grid.js';

        // Version using filemtime when possible for cache-busting
        $ver_css = file_exists($plugin_dir . 'assets/css/cf-grid.css') ? filemtime($plugin_dir . 'assets/css/cf-grid.css') : null;
        $ver_js = file_exists($plugin_dir . 'assets/js/cf-grid.js') ? filemtime($plugin_dir . 'assets/js/cf-grid.js') : null;

        wp_register_style('cf-courses-grid-css', $css_file, array(), $ver_css);
        wp_register_script('cf-courses-grid-js', $js_file, array(), $ver_js, true);

        // Provide the REST endpoint and nonce to the frontend script
        wp_localize_script('cf-courses-grid-js', 'CFGridData', array(
            'rest_url' => esc_url_raw(rest_url('cf-grid/v1/courses')),
            'nonce' => wp_create_nonce('wp_rest')
        ));

        wp_enqueue_style('cf-courses-grid-css');
        wp_enqueue_script('cf-courses-grid-js');
    }

    public function render_grid_shortcode($atts){
        $atts = shortcode_atts(array(
            'per_page' => 12,
            'columns' => 3
        ), $atts, 'cf_courses_grid');

        $per_page = intval($atts['per_page']) > 0 ? intval($atts['per_page']) : 12;
        $columns = in_array(intval($atts['columns']), array(1,2,3,4)) ? intval($atts['columns']) : 3;

        ob_start();
        ?>
        <div class="cf-grid-wrap">
          <aside class="cf-grid-facets" role="region" aria-label="Course filters">
            <div class="cf-facet-head">
              <h4>Filter Courses</h4>
              <button class="cf-facets-toggle" aria-expanded="false" aria-controls="cf-grid-container">Filters</button>
            </div>

            <div class="cf-facet cf-facet-category">
              <label for="cf-filter-category">Category</label>
              <select id="cf-filter-category" data-facet="category">
                <option value="">All</option>
                <?php
                $terms = get_terms(array('taxonomy' => 'ld_course_category', 'hide_empty' => true));
                if (!is_wp_error($terms)) {
                  foreach($terms as $t) printf('<option value="%s">%s</option>', esc_attr($t->slug), esc_html($t->name));
                }
                ?>
              </select>
            </div>

            <div class="cf-facet cf-facet-tag">
              <label for="cf-filter-tag">Tag</label>
              <select id="cf-filter-tag" data-facet="tag">
                <option value="">All</option>
                <?php
                $terms = get_terms(array('taxonomy' => 'ld_course_tag', 'hide_empty' => true));
                if (!is_wp_error($terms)) {
                  foreach($terms as $t) printf('<option value="%s">%s</option>', esc_attr($t->slug), esc_html($t->name));
                }
                ?>
              </select>
            </div>

            <!--<div class="cf-facet cf-facet-price">-->
            <!--  <label for="cf-filter-price">Price</label>-->
            <!--  <select id="cf-filter-price" data-facet="price">-->
            <!--    <option value="">All</option>-->
            <!--    <option value="free">Free</option>-->
            <!--    <option value="paid">Paid</option>-->
            <!--  </select>-->
            <!--</div>-->

            <div class="cf-facet cf-actions">
              <button class="cf-reset-filters">Reset</button>
            </div>
          </aside>

          <main class="cf-grid-main">
            <div class="cf-grid-toolbar">
              <div class="cf-grid-count" aria-live="polite"></div>
            </div>

            <div id="cf-grid-container" class="cf-grid columns-<?php echo intval($columns); ?>" data-per-page="<?php echo intval($per_page); ?>">
              <!-- JS will inject course cards here -->
            </div>

            <nav class="cf-grid-pagination" aria-label="Course pagination"></nav>
          </main>
        </div>
        <?php
        return ob_get_clean();
    }

    public function register_rest_routes(){
        register_rest_route('cf-grid/v1', '/courses', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_courses'),
            'permission_callback' => '__return_true',
            'args' => array(
                'page' => array('default' => 1, 'sanitize_callback' => 'absint'),
                'per_page' => array('default' => 12, 'sanitize_callback' => 'absint'),
            )
        ));
    }

    public function rest_get_courses($request){
        $page = $request->get_param('page') ?: $request->get_param('paged') ?: $request->get_param('p') ?: 1;
        $page = max(1, absint($page));

        $per_page = $request->get_param('per_page') ?: $request->get_param('perpage') ?: $request->get_param('pp') ?: 12;
        $per_page = absint($per_page) > 0 ? absint($per_page) : 12;

        $tax_query = array('relation' => 'AND');

        $category = $request->get_param('category');
        if (!empty($category)) {
            if (!is_array($category)) $category = array_map('trim', explode(',', sanitize_text_field($category)));
            $tax_query[] = array('taxonomy' => 'ld_course_category', 'field' => 'slug', 'terms' => $category);
        }

        $tag = $request->get_param('tag');
        if (!empty($tag)) {
            if (!is_array($tag)) $tag = array_map('trim', explode(',', sanitize_text_field($tag)));
            $tax_query[] = array('taxonomy' => 'ld_course_tag', 'field' => 'slug', 'terms' => $tag);
        }

        $meta_query = array();
        $price = $request->get_param('price');
        if (!empty($price)) {
            $price = sanitize_text_field($price);
            if ($price === 'free') {
                $meta_query[] = array('key' => '_course_price_type', 'value' => 'free', 'compare' => '=');
            } elseif ($price === 'paid') {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array('key' => '_course_price_type', 'value' => 'free', 'compare' => '!='),
                    array('key' => '_course_price', 'compare' => 'EXISTS'),
                );
            }
        }

        $query_args = array(
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'paged' => $page,
            'posts_per_page' => $per_page,
        );

        if (count($tax_query) > 1) $query_args['tax_query'] = $tax_query;
        if (!empty($meta_query)) $query_args['meta_query'] = $meta_query;

        $query_args = apply_filters('cf_grid_query_args', $query_args, $request);

        $q = new WP_Query($query_args);

        $items = array();
      if (!empty($q->posts)) {
    foreach ($q->posts as $p) {
        $raw_stored_excerpt = get_post_field('post_excerpt', $p->ID);

        $raw_source = $raw_stored_excerpt ? $raw_stored_excerpt : $p->post_content;

        $clean = strip_shortcodes( html_entity_decode( $raw_source ) );
        $clean = wp_strip_all_tags( $clean );

        $excerpt_final = wp_trim_words( $clean, 22, '...' );

        $items[] = array(
            'id'         => $p->ID,
            'title'      => get_the_title($p),
            'permalink'  => get_permalink($p),
            'excerpt'    => $excerpt_final,               
            'raw_excerpt'=> $raw_stored_excerpt,          
            'thumbnail'  => get_the_post_thumbnail_url($p, 'medium'),
            'categories' => wp_get_post_terms($p->ID, 'ld_course_category', array('fields' => 'names')),
            'tags'       => wp_get_post_terms($p->ID, 'ld_course_tag', array('fields' => 'names')),
        );
    }
}



        $data = array(
            'total' => (int)$q->found_posts,
            'per_page' => (int)$per_page,
            'page' => (int)$page,
            'pages' => (int)$q->max_num_pages,
            'items' => $items
        );

        if ( defined('WP_DEBUG') && WP_DEBUG && function_exists('current_user_can') && current_user_can('manage_options') ) {
            $data['debug_query_args'] = $query_args;
        }

        $response = rest_ensure_response($data);
        $response->header('X-WP-Total', (int)$q->found_posts);
        $response->header('X-WP-TotalPages', (int)$q->max_num_pages);

        return $response;
    }

}

new CF_Courses_Grid();
