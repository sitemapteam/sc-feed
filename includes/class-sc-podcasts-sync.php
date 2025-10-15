<?php

class SC_Podcasts_Sync {
    
    private static $instance = null;
    private $api;
    
    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api = SC_Podcasts_API::instance();
        
        add_action('sc_podcasts_sync_episodes', array($this, 'sync_episodes'));
        add_action('wp_ajax_sc_podcasts_manual_sync', array($this, 'ajax_manual_sync'));
    }
    
    public function sync_episodes() {
        $last_sync = get_option('sc_podcasts_last_sync');
        $updated_since = $last_sync ? date('Y-m-d\TH:i:s\Z', strtotime($last_sync)) : null;
        
        $episodes = $this->api->get_all_episodes($updated_since);
        
        if (is_wp_error($episodes)) {
            $this->log_error('API fetch failed: ' . $episodes->get_error_message());
            return false;
        }
        
        $synced_count = 0;
        $failed_count = 0;
        
        foreach ($episodes as $episode) {
            $result = $this->sync_single_episode($episode);
            
            if ($result) {
                $synced_count++;
            } else {
                $failed_count++;
            }
        }
        
        update_option('sc_podcasts_last_sync', current_time('mysql'));
        update_option('sc_podcasts_last_sync_stats', array(
            'synced'  => $synced_count,
            'failed'  => $failed_count,
            'total'   => count($episodes),
            'time'    => current_time('mysql')
        ));
        
        return true;
    }
    
    public function sync_single_episode($episode_data) {
        if (!defined('SC_POST_TYPE')) {
            return false;
        }
        
        // Extract SC data
        $sc_id = isset($episode_data['id']) ? $episode_data['id'] : '';
        $sc_guid = isset($episode_data['guid']) ? $episode_data['guid'] : '';
        $sc_feed_id = isset($episode_data['feed_id']) ? $episode_data['feed_id'] : '';
        $title = isset($episode_data['title']) ? $episode_data['title'] : '';
        $description = isset($episode_data['description']) ? $episode_data['description'] : '';
        $duration = isset($episode_data['duration']) ? $episode_data['duration'] : '';
        $audio_url = isset($episode_data['enclosure_url']) ? $episode_data['enclosure_url'] : '';
        $filesize = isset($episode_data['enclosure_length']) ? $episode_data['enclosure_length'] : '';
        $published_at = isset($episode_data['published_at']) ? $episode_data['published_at'] : '';
        
        if (empty($sc_id) || empty($title)) {
            return false;
        }
        
        // Check if episode exists
        $existing = $this->find_episode_by_sc_id($sc_id);
        
        if ($existing) {
            $post_id = $existing->ID;
            $post_data = array(
                'ID'         => $post_id,
                'post_title' => $title,
                'post_date'  => $published_at,
            );
            wp_update_post($post_data);
        } else {
            // Check if we can match by GUID
            $existing_by_guid = $this->find_episode_by_guid($sc_guid);
            
            if ($existing_by_guid) {
                $post_id = $existing_by_guid->ID;
                $post_data = array(
                    'ID'         => $post_id,
                    'post_title' => $title,
                    'post_date'  => $published_at,
                );
                wp_update_post($post_data);
            } else {
                // Create new episode
                $post_data = array(
                    'post_type'   => SC_POST_TYPE,
                    'post_title'  => $title,
                    'post_status' => 'publish',
                    'post_date'   => $published_at,
                );
                $post_id = wp_insert_post($post_data);
                
                if (is_wp_error($post_id)) {
                    return false;
                }
            }
        }
        
        // Update meta fields
        update_post_meta($post_id, 'ncrmnt_notes', $description);
        update_post_meta($post_id, 'ncrmnt_duration', $duration);
        
        // Update media field
        $media_data = array(
            'media_type'        => 'Audio',
            'media_filesize'    => $filesize,
            'media_upload_type' => 'link',
            'media_link_field'  => $audio_url,
        );
        update_post_meta($post_id, 'ncrmnt_media', $media_data);
        
        // Update SC integration fields
        update_post_meta($post_id, 'sc_integration', array(
            'episode_id'  => $sc_id,
            'feed_id'     => $sc_feed_id,
            'guid'        => $sc_guid,
            'sync_status' => 'synced',
            'last_synced' => current_time('mysql'),
        ));
        
        // Map to taxonomy
        $this->map_feed_to_taxonomy($post_id, $sc_feed_id);
        
        return true;
    }
    
    private function find_episode_by_sc_id($sc_id) {
        if (!defined('SC_POST_TYPE')) {
            return null;
        }
        
        $args = array(
            'post_type'  => SC_POST_TYPE,
            'meta_query' => array(
                array(
                    'key'     => 'sc_integration',
                    'value'   => serialize(array('episode_id' => $sc_id)),
                    'compare' => 'LIKE'
                )
            ),
            'posts_per_page' => 1,
        );
        
        $posts = get_posts($args);
        return !empty($posts) ? $posts[0] : null;
    }
    
    private function find_episode_by_guid($guid) {
        if (!defined('SC_POST_TYPE')) {
            return null;
        }
        
        if (empty($guid)) {
            return null;
        }
        
        // Try to match by post ID from GUID
        if (preg_match('/\/episode\/[^\/]+\/(\d+)/', $guid, $matches)) {
            $post_id = intval($matches[1]);
            $post = get_post($post_id);
            
            if ($post && $post->post_type === SC_POST_TYPE) {
                return $post;
            }
        }
        
        // Fallback to meta search
        $args = array(
            'post_type'  => SC_POST_TYPE,
            'meta_query' => array(
                array(
                    'key'     => 'sc_integration',
                    'value'   => serialize(array('guid' => $guid)),
                    'compare' => 'LIKE'
                )
            ),
            'posts_per_page' => 1,
        );
        
        $posts = get_posts($args);
        return !empty($posts) ? $posts[0] : null;
    }
    
    private function map_feed_to_taxonomy($post_id, $feed_id) {
        if (!defined('SC_TAXONOMY')) {
            return;
        }
        
        $feed_mapping = get_option('sc_podcasts_feed_mapping', array());
        
        if (isset($feed_mapping[$feed_id])) {
            $term_id = $feed_mapping[$feed_id];
            wp_set_object_terms($post_id, array($term_id), SC_TAXONOMY, false);
        } else {
            // Try to auto-map based on feed info
            $feed_info = $this->api->get_feed($feed_id);
            
            if (!is_wp_error($feed_info) && isset($feed_info['title'])) {
                $term = term_exists($feed_info['title'], SC_TAXONOMY);
                
                if (!$term) {
                    $term = wp_insert_term($feed_info['title'], SC_TAXONOMY, array(
                        'description' => isset($feed_info['description']) ? $feed_info['description'] : '',
                    ));
                }
                
                if (!is_wp_error($term)) {
                    $term_id = is_array($term) ? $term['term_id'] : $term;
                    $feed_mapping[$feed_id] = $term_id;
                    update_option('sc_podcasts_feed_mapping', $feed_mapping);
                    wp_set_object_terms($post_id, array($term_id), SC_TAXONOMY, false);
                }
            }
        }
    }
    
    public function ajax_manual_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->sync_episodes();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Sync completed successfully',
                'stats'   => get_option('sc_podcasts_last_sync_stats')
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Sync failed'
            ));
        }
    }
    
    private function log_error($message) {
        error_log('[SC Podcasts Sync] ' . $message);
    }
}
