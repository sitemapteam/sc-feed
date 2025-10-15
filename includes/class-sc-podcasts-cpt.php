<?php

class SC_Podcasts_CPT {
    
    private static $instance = null;
    
    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constants should already be defined by the time this is instantiated
        if (!defined('SC_POST_TYPE') || !defined('SC_TAXONOMY')) {
            error_log('SC Podcasts CPT: ERROR - Constants not defined in constructor!');
            return;
        }
        
        error_log('SC Podcasts CPT: Constructor called, adding hooks');
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
        add_filter('post_type_link', array($this, 'episode_permalink_structure'), 10, 4);
        add_action('init', array($this, 'add_rewrite_rules'));
        
        if (defined('FM_VERSION')) {
            add_action('after_setup_theme', array($this, 'register_custom_fields'));
        }
        
        error_log('SC Podcasts CPT: All CPT hooks registered');
    }
    
    public function register_post_type() {
        $labels = array(
            'name'                  => _x('Episodes', 'Episodes', 'sc-podcasts'),
            'singular_name'         => _x('Episode', 'Episode', 'sc-podcasts'),
            'menu_name'             => SC_PARALLEL_MODE ? __('SC Podcasts', 'sc-podcasts') : __('Podcasts', 'sc-podcasts'),
            'name_admin_bar'        => __('Episode', 'sc-podcasts'),
            'archives'              => __('Episode Archives', 'sc-podcasts'),
            'attributes'            => __('Episode Attributes', 'sc-podcasts'),
            'parent_item_colon'     => __('Parent Episode:', 'sc-podcasts'),
            'all_items'             => __('All Episodes', 'sc-podcasts'),
            'add_new_item'          => __('Add New Episode', 'sc-podcasts'),
            'add_new'               => __('Add New Episode', 'sc-podcasts'),
            'new_item'              => __('New Episode', 'sc-podcasts'),
            'edit_item'             => __('Edit Episode', 'sc-podcasts'),
            'update_item'           => __('Update Episode', 'sc-podcasts'),
            'view_item'             => __('View Episode', 'sc-podcasts'),
            'view_items'            => __('View Episodes', 'sc-podcasts'),
            'search_items'          => __('Search Episode', 'sc-podcasts'),
            'not_found'             => __('Not found', 'sc-podcasts'),
            'not_found_in_trash'    => __('Not found in Trash', 'sc-podcasts'),
            'featured_image'        => __('Featured Image', 'sc-podcasts'),
            'set_featured_image'    => __('Set featured image', 'sc-podcasts'),
            'remove_featured_image' => __('Remove featured image', 'sc-podcasts'),
            'use_featured_image'    => __('Use as featured image', 'sc-podcasts'),
            'insert_into_item'      => __('Insert into episode', 'sc-podcasts'),
            'uploaded_to_this_item' => __('Uploaded to this episode', 'sc-podcasts'),
            'items_list'            => __('Episode list', 'sc-podcasts'),
            'items_list_navigation' => __('Episode list navigation', 'sc-podcasts'),
            'filter_items_list'     => __('Filter episode list', 'sc-podcasts'),
        );
        
        $rewrite = array(
            'slug'       => '/episode/%' . SC_TAXONOMY . '%/%post_id%',
            'with_front' => false,
            'pages'      => false,
            'feeds'      => true,
        );
        
        $args = array(
            'label'                 => __('Episodes', 'sc-podcasts'),
            'description'           => __('Podcast Episodes', 'sc-podcasts'),
            'labels'                => $labels,
            'supports'              => array('title', 'thumbnail'),
            'taxonomies'            => array('post_tag'),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 7,
            'menu_icon'             => SC_PARALLEL_MODE ? 'dashicons-microphone' : 'dashicons-video-alt3',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'rewrite'               => $rewrite,
            'query_var'             => true,
            'capability_type'       => 'page',
        );
        
        register_post_type(SC_POST_TYPE, $args);
    }
    
    public function register_taxonomy() {
        $labels = array(
            'name'                       => _x('Premium Name', 'Premium', 'sc-podcasts'),
            'singular_name'              => _x('Premium', 'Premium', 'sc-podcasts'),
            'menu_name'                  => __('Manage Premiums', 'sc-podcasts'),
            'all_items'                  => __('All Premiums', 'sc-podcasts'),
            'parent_item'                => __('Parent Premium', 'sc-podcasts'),
            'parent_item_colon'          => __('Parent Premium:', 'sc-podcasts'),
            'new_item_name'              => __('New Premium Name', 'sc-podcasts'),
            'add_new_item'               => __('Add New Premium', 'sc-podcasts'),
            'edit_item'                  => __('Edit Premium', 'sc-podcasts'),
            'update_item'                => __('Update Premium', 'sc-podcasts'),
            'view_item'                  => __('View Premium', 'sc-podcasts'),
            'separate_items_with_commas' => __('Separate shows with commas', 'sc-podcasts'),
            'add_or_remove_items'        => __('Add or remove shows', 'sc-podcasts'),
            'choose_from_most_used'      => __('Choose from the most used', 'sc-podcasts'),
            'popular_items'              => __('Popular Shows', 'sc-podcasts'),
            'search_items'               => __('Search Shows', 'sc-podcasts'),
            'not_found'                  => __('Not Found', 'sc-podcasts'),
            'no_terms'                   => __('No items', 'sc-podcasts'),
            'items_list'                 => __('Shows list', 'sc-podcasts'),
            'items_list_navigation'      => __('Shows list navigation', 'sc-podcasts'),
        );
        
        $rewrite = array(
            'slug'         => SC_TAXONOMY === 'premium' ? 'premium' : 'sc-premium',
            'with_front'   => true,
            'hierarchical' => true,
        );
        
        $args = array(
            'labels'             => $labels,
            'hierarchical'       => true,
            'public'             => true,
            'show_ui'            => true,
            'show_admin_column'  => true,
            'show_in_nav_menus'  => true,
            'show_tagcloud'      => true,
            'rewrite'            => $rewrite,
        );
        
        register_taxonomy(SC_TAXONOMY, array(SC_POST_TYPE), $args);
        register_taxonomy_for_object_type('post_tag', SC_POST_TYPE);
    }
    
    public function episode_permalink_structure($post_link, $post, $leavename, $sample) {
        if (false !== strpos($post_link, '%' . SC_TAXONOMY . '%')) {
            $terms = get_the_terms($post->ID, SC_TAXONOMY);
            $first_term = is_array($terms) ? array_pop($terms) : false;
            
            if ($first_term) {
                $post_link = str_replace('%' . SC_TAXONOMY . '%', $first_term->slug, $post_link);
                $post_link = str_replace('%post_id%', $post->ID, $post_link);
            }
        }
        return $post_link;
    }
    
    public function add_rewrite_rules() {
        $tax_slug = SC_TAXONOMY === 'premium' ? 'premium' : 'sc-premium';
        
        add_rewrite_tag('%' . $tax_slug . '%', '([^&]+)');
        add_rewrite_tag('%token%', '([^&]+)');
        add_rewrite_tag('%view%', '([^&]+)');
        add_rewrite_tag('%uv%', '([^&]+)');
        
        add_rewrite_rule(
            '^' . $tax_slug . '/([^/]*)/([^/]*)/([^/]*)/([^/]*)/?',
            'index.php?' . SC_TAXONOMY . '=$matches[1]&view=$matches[2]&token=$matches[3]&uv=$matches[4]',
            'top'
        );
    }
    
    public function register_custom_fields() {
        // Check if Fieldmanager is available
        if (!class_exists('Fieldmanager_Field')) {
            return;
        }
        
        // Episode fields
        $fm_notes = new Fieldmanager_RichTextArea(array('name' => 'ncrmnt_notes'));
        $fm_notes->add_meta_box('Episode notes', SC_POST_TYPE);
        
        $fm_duration = new Fieldmanager_Textfield(array('name' => 'ncrmnt_duration'));
        $fm_duration->add_meta_box('Duration', SC_POST_TYPE);
        
        $fm_media = new Fieldmanager_Group(array(
            'name'     => 'ncrmnt_media',
            'children' => array(
                'media_type'        => new Fieldmanager_Radios(
                    'Media Type',
                    array(
                        'options'       => array('Audio', 'Video'),
                        'default_value' => 'Audio'
                    )
                ),
                'media_filesize'    => new Fieldmanager_Textfield('Media Filesize'),
                'media_upload_type' => new Fieldmanager_Select(array(
                    'label'   => 'Media Upload',
                    'options' => array(
                        'link'   => 'Insert Link',
                        'upload' => 'Upload File',
                    ),
                )),
                'media_link_field'  => new Fieldmanager_Textfield(
                    'Media Link Field',
                    array(
                        'description' => 'This is a text field that sanitizes the value as a URL',
                        'display_if'  => array(
                            'src'   => 'media_upload_type',
                            'value' => 'link',
                        ),
                    )
                ),
                'media_upload_field' => new Fieldmanager_Media(
                    'Media Upload',
                    array(
                        'display_if' => array(
                            'src'   => 'media_upload_type',
                            'value' => 'upload',
                        ),
                    )
                ),
            )
        ));
        $fm_media->add_meta_box('Episode Media', SC_POST_TYPE);
        
        // SC Integration fields
        $fm_sc = new Fieldmanager_Group(array(
            'name'     => 'sc_integration',
            'children' => array(
                'episode_id'   => new Fieldmanager_Textfield('SC Episode ID'),
                'feed_id'      => new Fieldmanager_Textfield('SC Feed ID'),
                'guid'         => new Fieldmanager_Textfield('SC GUID'),
                'sync_status'  => new Fieldmanager_Select(array(
                    'label'   => 'Sync Status',
                    'options' => array(
                        'not_synced' => 'Not in SC',
                        'synced'     => 'Synced',
                        'pending'    => 'Pending Sync',
                        'failed'     => 'Sync Failed',
                    ),
                )),
                'last_synced'  => new Fieldmanager_Datepicker(array(
                    'label'       => 'Last Synced',
                    'date_format' => 'Y-m-d H:i:s',
                    'use_time'    => true,
                )),
            )
        ));
        $fm_sc->add_meta_box('Supporting Cast Integration', SC_POST_TYPE);
        
        // Taxonomy fields
        $taxonomy_fields = array(
            'ncrmnt_premium_created'      => array('type' => 'datepicker', 'label' => 'Created Date'),
            'ncrmnt_premium_homepage'     => array('type' => 'textfield', 'label' => 'Link to Homepage'),
            'ncrmnt_premium_media'        => array('type' => 'media', 'label' => 'Premium Image'),
            'ncrmnt_premium_bucket_url'   => array('type' => 'textfield', 'label' => 'Cloudfront Domain'),
            'ncrmnt_premium_linkwrap_url' => array('type' => 'textfield', 'label' => 'Analytics Link Wrap Domain'),
            'ncrmnt_premium_copyright'    => array('type' => 'textfield', 'label' => 'Copyright'),
            'ncrmnt_premium_author'       => array('type' => 'textfield', 'label' => 'Author'),
            'ncrmnt_premium_email'        => array('type' => 'textfield', 'label' => 'Author Email'),
            'ncrmnt_premium_block'        => array('type' => 'textfield', 'label' => 'Block in iTunes'),
            'ncrmnt_premium_category'     => array('type' => 'textfield', 'label' => 'Category'),
            'ncrmnt_premium_access_role'  => array('type' => 'textfield', 'label' => 'Member Role for Access'),
        );
        
        foreach ($taxonomy_fields as $name => $config) {
            if ($config['type'] === 'datepicker') {
                $fm = new Fieldmanager_Datepicker(array(
                    'name'        => $name,
                    'date_format' => 'Y-m-d',
                    'use_time'    => true,
                    'js_opts'     => array(
                        'dateFormat'  => 'yy-mm-dd',
                        'changeMonth' => true,
                        'changeYear'  => true,
                        'minDate'     => '2019-01-01',
                    )
                ));
            } elseif ($config['type'] === 'media') {
                $fm = new Fieldmanager_Media(array('name' => $name));
            } else {
                $fm = new Fieldmanager_Textfield(array('name' => $name));
            }
            $fm->add_term_meta_box($config['label'], SC_TAXONOMY);
        }
    }
}
