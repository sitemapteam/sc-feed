<?php

class SC_Podcasts_API {
    
    private static $instance = null;
    private $api_base_url = 'https://api.supportingcast.fm/v2';
    private $network_id;
    private $api_token;
    
    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->network_id = get_option('sc_podcasts_network_id', '10280');
        $this->api_token = get_option('sc_podcasts_api_token');
    }
    
    public function get_episodes($params = array()) {
        $defaults = array(
            'page' => 1,
            'per_page' => 50,
            'updated_since' => null,
            'feed_id' => null,
        );
        
        $params = wp_parse_args($params, $defaults);
        $endpoint = $this->api_base_url . '/' . $this->network_id . '/episodes';
        
        $query_args = array();
        if ($params['page']) {
            $query_args['page'] = $params['page'];
        }
        if ($params['per_page']) {
            $query_args['per_page'] = $params['per_page'];
        }
        if ($params['updated_since']) {
            $query_args['updated_since'] = $params['updated_since'];
        }
        if ($params['feed_id']) {
            $query_args['feed_id'] = $params['feed_id'];
        }
        
        if (!empty($query_args)) {
            $endpoint .= '?' . http_build_query($query_args);
        }
        
        return $this->make_request($endpoint);
    }
    
    public function get_episode($episode_id) {
        $endpoint = $this->api_base_url . '/' . $this->network_id . '/episodes/' . $episode_id;
        return $this->make_request($endpoint);
    }
    
    public function get_feeds($params = array()) {
        $endpoint = $this->api_base_url . '/' . $this->network_id . '/feeds';
        
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        return $this->make_request($endpoint);
    }
    
    public function get_feed($feed_id) {
        $endpoint = $this->api_base_url . '/' . $this->network_id . '/feeds/' . $feed_id;
        return $this->make_request($endpoint);
    }
    
    private function make_request($endpoint, $method = 'GET', $body = null) {
        if (empty($this->api_token)) {
            return new WP_Error('no_api_token', 'SC API token not configured');
        }
        
        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        );
        
        if ($body && $method !== 'GET') {
            $args['body'] = json_encode($body);
        }
        
        $response = wp_remote_request($endpoint, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            $data = json_decode($response_body, true);
            return $data ? $data : new WP_Error('json_decode_error', 'Failed to decode API response');
        } else {
            return new WP_Error('api_error', 'API request failed', array(
                'status_code' => $response_code,
                'response'    => $response_body
            ));
        }
    }
    
    public function test_connection() {
        $result = $this->get_feeds(array('per_page' => 1));
        return !is_wp_error($result);
    }
    
    public function count_episodes() {
        $first_page = $this->get_episodes(array('per_page' => 1));
        
        if (is_wp_error($first_page)) {
            return 0;
        }
        
        return isset($first_page['total']) ? $first_page['total'] : 0;
    }
    
    public function get_all_episodes($updated_since = null) {
        $all_episodes = array();
        $page = 1;
        $has_more = true;
        
        while ($has_more) {
            $params = array(
                'page' => $page,
                'per_page' => 50
            );
            
            if ($updated_since) {
                $params['updated_since'] = $updated_since;
            }
            
            $response = $this->get_episodes($params);
            
            if (is_wp_error($response)) {
                break;
            }
            
            if (isset($response['data']) && is_array($response['data'])) {
                $all_episodes = array_merge($all_episodes, $response['data']);
            }
            
            $has_more = isset($response['has_more']) && $response['has_more'];
            $page++;
            
            // Safety check
            if ($page > 100) {
                break;
            }
        }
        
        return $all_episodes;
    }
}
