<?php

class SC_Podcasts_Migration {
    
    private static $instance = null;
    
    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_sc_analyze_episodes', array($this, 'ajax_analyze_episodes'));
        add_action('wp_ajax_sc_copy_episodes', array($this, 'ajax_copy_episodes'));
    }
    
    public function analyze_episodes() {
        $increment_episodes = $this->get_increment_episodes();
        $sc_episodes = $this->get_sc_episodes();
        
        $analysis = array(
            'increment_total'     => count($increment_episodes),
            'sc_total'           => count($sc_episodes),
            'matched'            => 0,
            'unmatched'          => 0,
            'matched_episodes'   => array(),
            'unmatched_episodes' => array(),
        );
        
        foreach ($increment_episodes as $inc_episode) {
            $matched = false;
            
            // Try to match by title
            foreach ($sc_episodes as $sc_episode) {
                if ($this->normalize_title($inc_episode->post_title) === $this->normalize_title($sc_episode->post_title)) {
                    $analysis['matched']++;
                    $analysis['matched_episodes'][] = array(
                        'increment_id'    => $inc_episode->ID,
                        'increment_title' => $inc_episode->post_title,
                        'sc_id'          => $sc_episode->ID,
                        'sc_title'       => $sc_episode->post_title,
                    );
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                // Try to match by post ID in URL
                $media = get_post_meta($inc_episode->ID, 'ncrmnt_media', true);
                if ($media && isset($media['media_link_field'])) {
                    foreach ($sc_episodes as $sc_episode) {
                        $sc_integration = get_post_meta($sc_episode->ID, 'sc_integration', true);
                        if (isset($sc_integration['guid']) && strpos($sc_integration['guid'], '/' . $inc_episode->ID . '/') !== false) {
                            $analysis['matched']++;
                            $analysis['matched_episodes'][] = array(
                                'increment_id'    => $inc_episode->ID,
                                'increment_title' => $inc_episode->post_title,
                                'sc_id'          => $sc_episode->ID,
                                'sc_title'       => $sc_episode->post_title,
                            );
                            $matched = true;
                            break;
                        }
                    }
                }
            }
            
            if (!$matched) {
                $analysis['unmatched']++;
                $analysis['unmatched_episodes'][] = array(
                    'id'    => $inc_episode->ID,
                    'title' => $inc_episode->post_title,
                    'date'  => $inc_episode->post_date,
                );
            }
        }
        
        return $analysis;
    }
    
    public function copy_episodes() {
        if (!defined('SC_POST_TYPE')) {
            return array('error' => 'Plugin not initialized');
        }
        
        $increment_episodes = $this->get_increment_episodes();
        $copied = 0;
        $failed = 0;
        $skipped = 0;
        
        foreach ($increment_episodes as $inc_episode) {
            // Check if already migrated
            $existing = get_post_meta($inc_episode->ID, '_sc_migrated_id', true);
            if ($existing) {
                $skipped++;
                continue;
            }
            
            // Create new SC episode
            $new_post_data = array(
                'post_type'     => SC_POST_TYPE,
                'post_title'    => $inc_episode->post_title,
                'post_content'  => $inc_episode->post_content,
                'post_excerpt'  => $inc_episode->post_excerpt,
                'post_status'   => $inc_episode->post_status,
                'post_date'     => $inc_episode->post_date,
                'post_date_gmt' => $inc_episode->post_date_gmt,
            );
            
            $new_id = wp_insert_post($new_post_data);
            
            if (is_wp_error($new_id)) {
                $failed++;
                continue;
            }
            
            // Copy all meta
            $this->copy_post_meta($inc_episode->ID, $new_id);
            
            // Copy taxonomies
            $this->copy_taxonomies($inc_episode->ID, $new_id);
            
            // Copy featured image
            $thumbnail_id = get_post_thumbnail_id($inc_episode->ID);
            if ($thumbnail_id) {
                set_post_thumbnail($new_id, $thumbnail_id);
            }
            
            // Mark as migrated
            update_post_meta($inc_episode->ID, '_sc_migrated_id', $new_id);
            update_post_meta($new_id, '_migrated_from_id', $inc_episode->ID);
            
            $copied++;
        }
        
        return array(
            'copied'  => $copied,
            'failed'  => $failed,
            'skipped' => $skipped,
            'total'   => count($increment_episodes),
        );
    }
    
    private function get_increment_episodes() {
        return get_posts(array(
            'post_type'      => 'ncrmnt_episode',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
    }
    
    private function get_sc_episodes() {
        if (!defined('SC_POST_TYPE')) {
            return array();
        }
        
        return get_posts(array(
            'post_type'      => SC_POST_TYPE,
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
    }
    
    private function normalize_title($title) {
        // Remove special characters and make lowercase for comparison
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\s]/', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title);
    }
    
    private function copy_post_meta($from_id, $to_id) {
        $meta_data = get_post_meta($from_id);
        
        foreach ($meta_data as $key => $values) {
            // Skip private meta
            if (strpos($key, '_') === 0 && $key !== '_thumbnail_id') {
                continue;
            }
            
            foreach ($values as $value) {
                add_post_meta($to_id, $key, maybe_unserialize($value));
            }
        }
    }
    
    private function copy_taxonomies($from_id, $to_id) {
        if (!defined('SC_TAXONOMY')) {
            return;
        }
        
        // Copy premium/sc_premium taxonomy
        $terms = wp_get_object_terms($from_id, 'premium', array('fields' => 'slugs'));
        if (!is_wp_error($terms) && !empty($terms)) {
            // Check if terms exist in new taxonomy
            foreach ($terms as $slug) {
                $term = get_term_by('slug', $slug, SC_TAXONOMY);
                if (!$term) {
                    // Get original term details
                    $original_term = get_term_by('slug', $slug, 'premium');
                    if ($original_term) {
                        // Create term in new taxonomy
                        $new_term = wp_insert_term(
                            $original_term->name,
                            SC_TAXONOMY,
                            array(
                                'description' => $original_term->description,
                                'slug'        => $original_term->slug,
                            )
                        );
                        
                        if (!is_wp_error($new_term)) {
                            // Copy term meta
                            $this->copy_term_meta($original_term->term_id, $new_term['term_id']);
                        }
                    }
                }
            }
            
            wp_set_object_terms($to_id, $terms, SC_TAXONOMY);
        }
        
        // Copy post tags
        $tags = wp_get_object_terms($from_id, 'post_tag', array('fields' => 'slugs'));
        if (!is_wp_error($tags) && !empty($tags)) {
            wp_set_object_terms($to_id, $tags, 'post_tag');
        }
    }
    
    private function copy_term_meta($from_term_id, $to_term_id) {
        $meta_fields = array(
            'ncrmnt_premium_created',
            'ncrmnt_premium_homepage',
            'ncrmnt_premium_media',
            'ncrmnt_premium_bucket_url',
            'ncrmnt_premium_linkwrap_url',
            'ncrmnt_premium_copyright',
            'ncrmnt_premium_author',
            'ncrmnt_premium_email',
            'ncrmnt_premium_block',
            'ncrmnt_premium_category',
            'ncrmnt_premium_access_role',
        );
        
        foreach ($meta_fields as $field) {
            $value = get_term_meta($from_term_id, $field, true);
            if ($value) {
                update_term_meta($to_term_id, $field, $value);
            }
        }
    }
    
    public function ajax_analyze_episodes() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $analysis = $this->analyze_episodes();
        wp_send_json_success($analysis);
    }
    
    public function ajax_copy_episodes() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->copy_episodes();
        wp_send_json_success($result);
    }
}
