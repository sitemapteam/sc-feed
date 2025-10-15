<?php

class SC_Podcasts_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init_admin'), 15);
    }
    
    public function init_admin() {
        if (!defined('SC_POST_TYPE') || !defined('SC_TAXONOMY')) {
            return;
        }
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('manage_' . SC_POST_TYPE . '_posts_columns', array($this, 'add_admin_columns'));
        add_action('manage_' . SC_POST_TYPE . '_posts_custom_column', array($this, 'render_admin_columns'), 10, 2);
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_ncrmnt_premium_episodes', array($this, 'ajax_get_premium_episodes'));
        add_action('wp_ajax_nopriv_ncrmnt_premium_episodes', array($this, 'ajax_get_premium_episodes'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . SC_POST_TYPE,
            'SC Podcasts Settings',
            'Settings',
            'manage_options',
            'sc-podcasts-settings',
            array($this, 'render_settings_page')
        );
        
        if (SC_PARALLEL_MODE) {
            add_submenu_page(
                'edit.php?post_type=' . SC_POST_TYPE,
                'Migration Tools',
                'Migration',
                'manage_options',
                'sc-podcasts-migration',
                array($this, 'render_migration_page')
            );
        }
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'sc-podcasts') === false && get_post_type() !== SC_POST_TYPE) {
            return;
        }
        
        wp_enqueue_style(
            'sc-podcasts-admin',
            SC_PODCASTS_PLUGIN_URL . 'assets/admin.css',
            array(),
            SC_PODCASTS_VERSION
        );
        
        wp_enqueue_script(
            'sc-podcasts-admin',
            SC_PODCASTS_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            SC_PODCASTS_VERSION,
            true
        );
        
        wp_localize_script('sc-podcasts-admin', 'sc_podcasts', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sc_podcasts_nonce'),
        ));
    }
    
    public function add_admin_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['sc_sync_status'] = 'SC Status';
                $new_columns['sc_feed'] = 'Feed';
                $new_columns['episode_duration'] = 'Duration';
            }
        }
        
        return $new_columns;
    }
    
    public function render_admin_columns($column, $post_id) {
        switch ($column) {
            case 'sc_sync_status':
                $sc_data = get_post_meta($post_id, 'sc_integration', true);
                $status = isset($sc_data['sync_status']) ? $sc_data['sync_status'] : 'not_synced';
                
                $status_icons = array(
                    'synced'     => '<span style="color:#46b450;">●</span> Synced',
                    'pending'    => '<span style="color:#ffb900;">●</span> Pending',
                    'failed'     => '<span style="color:#dc3232;">●</span> Failed',
                    'not_synced' => '<span style="color:#999;">○</span> Not in SC',
                );
                
                echo isset($status_icons[$status]) ? $status_icons[$status] : $status_icons['not_synced'];
                break;
                
            case 'sc_feed':
                $terms = get_the_terms($post_id, SC_TAXONOMY);
                if ($terms && !is_wp_error($terms)) {
                    $term_names = wp_list_pluck($terms, 'name');
                    echo implode(', ', $term_names);
                }
                break;
                
            case 'episode_duration':
                $duration = get_post_meta($post_id, 'ncrmnt_duration', true);
                echo $duration ? esc_html($duration) : '—';
                break;
        }
    }
    
    public function admin_notices() {
        if (!defined('SC_POST_TYPE') || !defined('SC_PARALLEL_MODE')) {
            return;
        }
        
        if (get_transient('sc_podcasts_activated')) {
            $mode = SC_PARALLEL_MODE ? 'parallel' : 'production';
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>SC Podcasts activated</strong> in <?php echo $mode; ?> mode.</p>
                <?php if (SC_PARALLEL_MODE): ?>
                <p>Running alongside Increment plugin. Using post type: <code><?php echo SC_POST_TYPE; ?></code></p>
                <?php endif; ?>
            </div>
            <?php
            delete_transient('sc_podcasts_activated');
        }
        
        if (SC_PARALLEL_MODE && is_plugin_active('increment-core/increment-core.php')) {
            $screen = get_current_screen();
            if ($screen && $screen->post_type === SC_POST_TYPE) {
                ?>
                <div class="notice notice-warning">
                    <p><strong>Parallel Mode Active:</strong> SC Podcasts is running alongside Increment. 
                    <a href="<?php echo admin_url('edit.php?post_type=' . SC_POST_TYPE . '&page=sc-podcasts-migration'); ?>">View Migration Tools</a></p>
                </div>
                <?php
            }
        }
    }
    
    public function register_settings() {
        register_setting('sc_podcasts_settings', 'sc_podcasts_api_token');
        register_setting('sc_podcasts_settings', 'sc_podcasts_network_id');
        register_setting('sc_podcasts_settings', 'sc_parallel_mode');
        register_setting('sc_podcasts_settings', 'sc_enable_redirects');
        register_setting('sc_podcasts_settings', 'sc_podcasts_feed_mapping');
    }
    
    public function render_settings_page() {
        if (!defined('SC_POST_TYPE') || !defined('SC_PARALLEL_MODE')) {
            echo '<div class="wrap"><h1>Error</h1><p>Plugin not fully initialized. Please refresh the page.</p></div>';
            return;
        }
        
        $api_token = get_option('sc_podcasts_api_token');
        $network_id = get_option('sc_podcasts_network_id', '10280');
        $parallel_mode = get_option('sc_parallel_mode', true);
        $enable_redirects = get_option('sc_enable_redirects', false);
        $last_sync = get_option('sc_podcasts_last_sync');
        $sync_stats = get_option('sc_podcasts_last_sync_stats');
        
        ?>
        <div class="wrap">
            <h1>SC Podcasts Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('sc_podcasts_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Supporting Cast API Token</th>
                        <td>
                            <input type="password" name="sc_podcasts_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" />
                            <p class="description">Bearer token for Supporting Cast API</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Network ID</th>
                        <td>
                            <input type="text" name="sc_podcasts_network_id" value="<?php echo esc_attr($network_id); ?>" class="regular-text" />
                            <p class="description">Supporting Cast network ID</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Operating Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sc_parallel_mode" value="1" <?php checked($parallel_mode); ?> />
                                Enable Parallel Mode (use sc_episode post type)
                            </label>
                            <p class="description">
                                <?php if (is_plugin_active('increment-core/increment-core.php')): ?>
                                <strong style="color:#d63638;">⚠ Increment plugin is active.</strong> Keep this checked to avoid conflicts.
                                <?php else: ?>
                                Uncheck to use production post type (ncrmnt_episode)
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <?php if (SC_PARALLEL_MODE): ?>
                    <tr>
                        <th scope="row">Redirects</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sc_enable_redirects" value="1" <?php checked($enable_redirects); ?> />
                                Enable redirects from old episodes to new
                            </label>
                            <p class="description">Redirect ncrmnt_episode URLs to sc_episode URLs</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <h2>Sync Status</h2>
            <table class="widefat">
                <tbody>
                    <tr>
                        <th>Last Sync</th>
                        <td><?php echo $last_sync ? esc_html($last_sync) : 'Never'; ?></td>
                    </tr>
                    <?php if ($sync_stats): ?>
                    <tr>
                        <th>Episodes Synced</th>
                        <td><?php echo esc_html($sync_stats['synced']); ?> / <?php echo esc_html($sync_stats['total']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Actions</th>
                        <td>
                            <button type="button" class="button" id="sc-test-connection">Test Connection</button>
                            <button type="button" class="button" id="sc-manual-sync">Run Manual Sync</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <h2>Feed Mapping</h2>
            <p>Map Supporting Cast feeds to taxonomy terms:</p>
            <?php $this->render_feed_mapping_table(); ?>
        </div>
        <?php
    }
    
    public function render_migration_page() {
        if (!defined('SC_PARALLEL_MODE')) {
            echo '<div class="wrap"><h1>Error</h1><p>Plugin not fully initialized. Please refresh the page.</p></div>';
            return;
        }
        
        $migration = SC_Podcasts_Migration::instance();
        
        ?>
        <div class="wrap">
            <h1>SC Podcasts Migration Tools</h1>
            
            <div class="card">
                <h2>Migration Status</h2>
                <p>Current mode: <strong><?php echo SC_PARALLEL_MODE ? 'Parallel' : 'Production'; ?></strong></p>
                <p>Increment plugin: <strong><?php echo is_plugin_active('increment-core/increment-core.php') ? 'Active' : 'Inactive'; ?></strong></p>
            </div>
            
            <div class="card">
                <h2>Step 1: Analyze Episodes</h2>
                <p>Compare Increment episodes with SC episodes to identify matches.</p>
                <button type="button" class="button button-secondary" id="sc-analyze-episodes">Analyze Episodes</button>
                <div id="sc-analysis-results"></div>
            </div>
            
            <div class="card">
                <h2>Step 2: Copy Episodes</h2>
                <p>Copy all ncrmnt_episode posts to sc_episode posts.</p>
                <button type="button" class="button button-primary" id="sc-copy-episodes">Copy Episodes</button>
                <div id="sc-copy-results"></div>
            </div>
            
            <div class="card">
                <h2>Step 3: Switch to Production Mode</h2>
                <p>Once testing is complete:</p>
                <ol>
                    <li>Deactivate the Increment plugin</li>
                    <li>Uncheck "Parallel Mode" in settings</li>
                    <li>Save settings to switch to production post types</li>
                </ol>
            </div>
        </div>
        <?php
    }
    
    private function render_feed_mapping_table() {
        if (!defined('SC_TAXONOMY')) {
            echo '<p>Plugin not fully initialized.</p>';
            return;
        }
        
        $api = SC_Podcasts_API::instance();
        $feeds = $api->get_feeds();
        $feed_mapping = get_option('sc_podcasts_feed_mapping', array());
        
        if (is_wp_error($feeds)) {
            echo '<p>Unable to fetch feeds. Check API settings.</p>';
            return;
        }
        
        $terms = get_terms(array(
            'taxonomy'   => SC_TAXONOMY,
            'hide_empty' => false,
        ));
        
        ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th>SC Feed</th>
                    <th>Mapped to Taxonomy</th>
                </tr>
            </thead>
            <tbody>
                <?php if (isset($feeds['data'])): ?>
                    <?php foreach ($feeds['data'] as $feed): ?>
                    <tr>
                        <td><?php echo esc_html($feed['title']); ?> (ID: <?php echo esc_html($feed['id']); ?>)</td>
                        <td>
                            <select name="feed_mapping[<?php echo esc_attr($feed['id']); ?>]">
                                <option value="">— Not Mapped —</option>
                                <?php foreach ($terms as $term): ?>
                                <option value="<?php echo esc_attr($term->term_id); ?>" 
                                    <?php selected(isset($feed_mapping[$feed['id']]) && $feed_mapping[$feed['id']] == $term->term_id); ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    public function ajax_get_premium_episodes() {
        if (!defined('SC_POST_TYPE') || !defined('SC_TAXONOMY')) {
            wp_send_json_error(array('message' => 'Plugin not initialized'));
            return;
        }
        
        $premium = isset($_GET['premium']) ? sanitize_text_field($_GET['premium']) : '';
        $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $posts_per_page = isset($_GET['posts_per_page']) ? intval($_GET['posts_per_page']) : 12;
        
        $args = array(
            'post_type'      => SC_POST_TYPE,
            'posts_per_page' => $posts_per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => array(
                array(
                    'taxonomy' => SC_TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $premium,
                ),
            ),
        );
        
        $query = new WP_Query($args);
        $episodes = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $episodes[] = array(
                    'id'        => get_the_ID(),
                    'title'     => get_the_title(),
                    'permalink' => get_permalink(),
                    'date'      => get_the_date(),
                    'excerpt'   => get_the_excerpt(),
                    'img'       => get_the_post_thumbnail_url(get_the_ID(), 'medium'),
                    'default_img' => get_stylesheet_directory_uri() . '/assets/img/wotr-logo-square.jpg',
                );
            }
        }
        
        wp_reset_postdata();
        
        wp_send_json_success(array(
            'episodes'      => $episodes,
            'paged'         => $paged,
            'max_num_pages' => $query->max_num_pages,
        ));
    }
}
