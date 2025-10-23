<?php
/*
Plugin Name: Custom Plugin - 
Description: Grid + Facets for LearnDash courses. Shortcode [cf_courses_grid].
Version: 0.1
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
        wp_register_style('cf-courses-grid-css', plugins_url('assets/css/cf-grid.css', __FILE__));
        wp_register_script('cf-courses-grid-js', plugins_url('assets/js/cf-grid.js', __FILE__), array('jquery'), null, true);
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

        ob_start();
        ?>
        <div class="cf-grid-wrap">
          <aside class="cf-grid-facets">
            <h4>Filter</h4>
            <div class="cf-facet cf-facet-category">
              <label>Category</label>
              <select data-facet="category">
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
              <label>Tag</label>
              <select data-facet="tag">
                <option value="">All</option>
                <?php
                $terms = get_terms(array('taxonomy' => 'ld_course_tag', 'hide_empty' => true));
                if (!is_wp_error($terms)) {
                  foreach($terms as $t) printf('<option value="%s">%s</option>', esc_attr($t->slug), esc_html($t->name));
                }
                ?>
              </select>
            </div>

            <div class="cf-facet cf-facet-price">
              <label>Price</label>
              <select data-facet="price">
                <option value="">All</option>
                <option value="free">Free</option>
                <option value="paid">Paid</option>
              </select>
            </div>

            <button class="cf-reset-filters">Reset</button>
          </aside>

          <main class="cf-grid-main">
            <div class="cf-grid-toolbar">
              <div class="cf-grid-count"></div>
            </div>
            <div id="cf-grid-container" class="cf-grid columns-<?php echo intval($atts['columns']); ?>" data-per-page="<?php echo intval($atts['per_page']); ?>">
              <!-- JS will inject course cards here -->
            </div>
            <div class="cf-grid-pagination"></div>
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
                'page' => array('validate_callback' => 'is_numeric'),
                'per_page' => array('validate_callback' => 'is_numeric'),
            )
        ));
    }

    public function rest_get_courses($request){
        $params = $request->get_params();
        $page = isset($params['page']) ? absint($params['page']) : 1;
        $per_page = isset($params['per_page']) ? absint($params['per_page']) : 12;

        $tax_query = array('relation' => 'AND');
        if (!empty($params['category'])) {
            $tax_query[] = array('taxonomy' => 'ld_course_category', 'field' => 'slug', 'terms' => sanitize_text_field($params['category']));
        }
        if (!empty($params['tag'])) {
            $tax_query[] = array('taxonomy' => 'ld_course_tag', 'field' => 'slug', 'terms' => sanitize_text_field($params['tag']));
        }

        $meta_query = array();
        if (!empty($params['price'])) {
            if ($params['price'] === 'free') {
                $meta_query[] = array('key' => '_course_price_type', 'value' => 'free', 'compare' => '=');
            } elseif ($params['price'] === 'paid') {
                $meta_query[] = array('relation' => 'OR', '0' => array('key' => '_course_price_type', 'value' => 'free', 'compare' => '!='), '1' => array('key' => '_course_price', 'compare' => 'EXISTS'));
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

        $q = new WP_Query($query_args);

        $items = array();
        foreach ($q->posts as $p){
            $items[] = array(
                'id' => $p->ID,
                'title' => get_the_title($p),
                'permalink' => get_permalink($p),
                'excerpt' => get_the_excerpt($p),
                'thumbnail' => get_the_post_thumbnail_url($p, 'medium'),
                'categories' => wp_get_post_terms($p->ID, 'ld_course_category', array('fields'=>'names')),
                'tags' => wp_get_post_terms($p->ID, 'ld_course_tag', array('fields'=>'names')),
            );
        }

        return array(
            'total' => (int)$q->found_posts,
            'per_page' => (int)$per_page,
            'page' => (int)$page,
            'pages' => (int)$q->max_num_pages,
            'items' => $items
        );
    }
}

new CF_Courses_Grid();
