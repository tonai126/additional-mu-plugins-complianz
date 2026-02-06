<?php
/**
 * Plugin Name: Complianz Services Reorder
 * Plugin URI: https://complianz.io
 * Description: Reorder services on the Complianz Cookie Policy page in a custom order
 * Version: 1.0.0
 * Author: Complianz Support (via Claude)
 * License: GPL v2 or later
 * Text Domain: cmplz-services-reorder
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CMPLZ_Services_Reorder {
    
    private $option_name = 'cmplz_services_order';
    private $page_option_name = 'cmplz_cookie_policy_page';
    
    public function __construct() {
        // Hook for admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Hook to save settings
        add_action('admin_post_save_cmplz_order', array($this, 'save_order'));
        add_action('admin_post_save_cmplz_page', array($this, 'save_page'));
        
        // Hook to modify page content
        add_filter('the_content', array($this, 'reorder_services'), 999);
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX for scanning the page
        add_action('wp_ajax_scan_cookie_page', array($this, 'ajax_scan_page'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Complianz Services Order',
            'Reorder Complianz Services', //name of the plugin inside the left section
            'manage_options',
            'cmplz-services-order',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_cmplz-services-order') {
            return;
        }
        
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('cmplz-reorder-admin', plugin_dir_url(__FILE__) . 'admin-style.css');
        
        // Add inline script for AJAX
        wp_localize_script('jquery', 'cmplzReorder', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cmplz_scan_nonce')
        ));
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        // Get saved page
        $saved_page_id = get_option($this->page_option_name, '');
        
        // Get saved order
        $saved_order = get_option($this->option_name, array());
        
        // Find all available services
        $available_services = array();
        if (!empty($saved_page_id)) {
            $available_services = $this->find_available_services($saved_page_id);
        }
        
        // Create ordered array
        $ordered_services = $this->get_ordered_services($available_services, $saved_order);
        
        // Get all pages for dropdown
        $pages = get_pages(array('post_status' => 'publish'));
        
        ?>
        <div class="wrap">
            <h1>Reorder Complianz Services</h1>
            
            <?php if (isset($_GET['page_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Cookie policy page saved! Services have been scanned.</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Services order saved successfully!</p>
                </div>
            <?php endif; ?>
            
            <div class="cmplz-reorder-container">
                <!-- Page selection section -->
                <div class="cmplz-page-selector">
                    <h2>1. Select Cookie Policy Page</h2>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="page-selector-form">
                        <?php wp_nonce_field('cmplz_page_nonce', 'cmplz_page_nonce'); ?>
                        <input type="hidden" name="action" value="save_cmplz_page">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="cookie_policy_page">Cookie Policy Page</label>
                                </th>
                                <td>
                                    <select name="cookie_policy_page" id="cookie_policy_page" class="regular-text">
                                        <option value="">-- Select a page --</option>
                                        <?php foreach ($pages as $page): ?>
                                            <option value="<?php echo esc_attr($page->ID); ?>" <?php selected($saved_page_id, $page->ID); ?>>
                                                <?php echo esc_html($page->post_title); ?> (ID: <?php echo $page->ID; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Select the page that contains your Complianz cookie policy.
                                        <br>E.g., "Cookie Policy" or "Privacy & Cookies"
                                    </p>

                                    <p class="description" >
                                        <strong>Warning:</strong> Changes will apply to ALL cookie policy pages (e.g., both English and Italian versions will use the same order).
                                        <br>E.g., "Cookie Policy" or "Privacy & Cookies"
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-search" style="margin-top: 3px;"></span>
                                Scan Page
                            </button>
                        </p>
                    </form>
                    
                    <?php if (!empty($saved_page_id)): ?>
                        <div class="current-page-info">
                            <strong>Current page:</strong> 
                            <?php 
                            $current_page = get_post($saved_page_id);
                            if ($current_page) {
                                echo esc_html($current_page->post_title);
                                echo ' <a href="' . get_permalink($saved_page_id) . '" target="_blank" class="button button-small">View</a>';
                                echo ' <a href="' . get_edit_post_link($saved_page_id) . '" target="_blank" class="button button-small">Edit</a>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($saved_page_id)): ?>
                    <div class="notice notice-info">
                        <p><strong>Start by selecting your cookie policy page above.</strong></p>
                        <p>The plugin will automatically scan the page HTML to find all Complianz services.</p>
                    </div>
                <?php elseif (empty($available_services)): ?>
                    <div class="notice notice-warning">
                        <p><strong>No services found on the selected page.</strong></p>
                        <p>Make sure the page contains the Complianz document with cookie services.</p>
                        <p>If you just selected the page, reload this page after saving.</p>
                    </div>
                <?php else: ?>
                    <!-- Services reordering section -->
                    <div class="cmplz-instructions">
                        <h2>2. Reorder Services</h2>
                        <p>Drag and drop services to reorder them. The order you set here will be applied to the cookie policy page.</p>
                        <p><strong>Services found:</strong> <?php echo count($available_services); ?></p>
                    </div>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('cmplz_reorder_nonce', 'cmplz_nonce'); ?>
                        <input type="hidden" name="action" value="save_cmplz_order">
                        
                        <div class="cmplz-services-list">
                            <h3>Services Order</h3>
                            
                            <ul id="sortable-services" class="sortable-list">
                                <?php 
                                $index = 1;
                                foreach ($ordered_services as $service): 
                                ?>
                                    <li class="service-item" data-service="<?php echo esc_attr($service['id']); ?>">
                                        <span class="service-number"><?php echo $index++; ?></span>
                                        <span class="dashicons dashicons-menu"></span>
                                        <strong><?php echo esc_html($service['name']); ?></strong>
                                        <span class="service-category"><?php echo esc_html($service['category']); ?></span>
                                        <input type="hidden" name="services_order[]" value="<?php echo esc_attr($service['id']); ?>">
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary button-large">
                                    <span class="dashicons dashicons-saved" style="margin-top: 3px;"></span>
                                    Save Order
                                </button>
                            </p>
                        </div>
                    </form>
                    
                    <div class="cmplz-preview">
                        <h3>Current Order Preview</h3>
                        <ol>
                            <?php foreach ($ordered_services as $service): ?>
                                <li>
                                    <strong><?php echo esc_html($service['name']); ?></strong>
                                    <span class="preview-category"><?php echo esc_html($service['category']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Initialize sortable functionality
                $('#sortable-services').sortable({
                    handle: '.dashicons-menu',
                    placeholder: 'sortable-placeholder',
                    update: function(event, ui) {
                        // Update numbers after reordering
                        updateNumbers();
                        // Update preview after reordering
                        updatePreview();
                    }
                });
                
                // Update service numbers in the list
                function updateNumbers() {
                    $('#sortable-services li').each(function(index) {
                        $(this).find('.service-number').text(index + 1);
                    });
                }
                
                // Update the preview list
                function updatePreview() {
                    var preview = $('.cmplz-preview ol');
                    preview.empty();
                    
                    $('#sortable-services li').each(function() {
                        var name = $(this).find('strong').text();
                        var category = $(this).find('.service-category').text();
                        preview.append('<li><strong>' + name + '</strong><span class="preview-category">' + category + '</span></li>');
                    });
                }
            });
            </script>
        </div>
        
        <style>
        .cmplz-reorder-container {
            max-width: 1200px;
            margin-top: 20px;
        }
        
        .cmplz-page-selector {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .cmplz-page-selector h2 {
            margin-top: 0;
            color: #2271b1;
        }
        
        .current-page-info {
            background: #f0f6fc;
            padding: 15px;
            margin-top: 20px;
            border-left: 4px solid #2271b1;
        }
        
        .cmplz-instructions {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #00a32a;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .cmplz-instructions h2 {
            margin-top: 0;
            color: #00a32a;
        }
        
        .cmplz-services-list {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .sortable-list {
            list-style: none;
            padding: 0;
            margin: 20px 0;
        }
        
        .service-item {
            background: #f6f7f7;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            cursor: move;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #dcdcde;
            transition: all 0.2s;
        }
        
        .service-item:hover {
            background: #fff;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }
        
        .service-number {
            background: #2271b1;
            color: #fff;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .service-item .dashicons {
            color: #666;
            flex-shrink: 0;
        }
        
        .service-item strong {
            flex-grow: 1;
            font-size: 15px;
        }
        
        .service-category {
            background: #f0f0f1;
            color: #2c3338;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .sortable-placeholder {
            background: #fff;
            border: 2px dashed #2271b1;
            height: 60px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
        
        .cmplz-preview {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .cmplz-preview h3 {
            margin-top: 0;
            border-bottom: 1px solid #dcdcde;
            padding-bottom: 10px;
        }
        
        .cmplz-preview ol {
            padding-left: 25px;
        }
        
        .cmplz-preview li {
            margin-bottom: 12px;
            padding: 8px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        
        .preview-category {
            color: #666;
            font-size: 13px;
            margin-left: 10px;
            font-style: italic;
        }
        
        .button .dashicons {
            margin-top: 3px;
        }

        p.description { 
            color: #222; 
        }
        </style>
        <?php
    }
    
    /**
     * Find all available services on the specified page
     */
    private function find_available_services($page_id = null) {
        $services = array();
        
        if (empty($page_id)) {
            return $services;
        }
        
        // Get the page
        $page = get_post($page_id);
        
        if (!$page) {
            return $services;
        }
        
        // Get rendered page content (with processed shortcodes)
        $content = apply_filters('the_content', $page->post_content);
        $content = do_shortcode($content);
        
        // Use DOMDocument to parse HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Find all details elements with cmplz-service-desc class
        $service_elements = $xpath->query("//details[contains(@class, 'cmplz-service-desc')]");
        
        foreach ($service_elements as $element) {
            // Extract service name from h3 inside summary
            $h3_elements = $xpath->query(".//summary//h3", $element);
            
            if ($h3_elements->length > 0) {
                $service_name = trim($h3_elements->item(0)->textContent);
                
                // Extract category from p inside summary
                $category_elements = $xpath->query(".//summary//p", $element);
                $category = 'Unknown';
                
                if ($category_elements->length > 0) {
                    $category = trim($category_elements->item(0)->textContent);
                }
                
                // Generate unique ID based on name
                $service_id = sanitize_title($service_name);
                
                if (!isset($services[$service_id])) {
                    $services[$service_id] = array(
                        'id' => $service_id,
                        'name' => $service_name,
                        'category' => $category,
                        'page_id' => $page_id,
                    );
                }
            }
        }
        
        return $services;
    }
    
    /**
     * Save cookie policy page
     */
    public function save_page() {
        // Verify nonce
        if (!isset($_POST['cmplz_page_nonce']) || !wp_verify_nonce($_POST['cmplz_page_nonce'], 'cmplz_page_nonce')) {
            wp_die('Security check failed');
        }
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Save page
        $page_id = isset($_POST['cookie_policy_page']) ? intval($_POST['cookie_policy_page']) : 0;
        update_option($this->page_option_name, $page_id);
        
        // Redirect
        wp_redirect(add_query_arg(array(
            'page' => 'cmplz-services-order',
            'page_saved' => 'true'
        ), admin_url('options-general.php')));
        exit;
    }
    
    /**
     * Get ordered services
     */
    private function get_ordered_services($available_services, $saved_order) {
        if (empty($saved_order)) {
            return $available_services;
        }
        
        $ordered = array();
        
        // First add services in saved order
        foreach ($saved_order as $service_id) {
            if (isset($available_services[$service_id])) {
                $ordered[$service_id] = $available_services[$service_id];
            }
        }
        
        // Then add any new services not in saved order
        foreach ($available_services as $service_id => $service) {
            if (!isset($ordered[$service_id])) {
                $ordered[$service_id] = $service;
            }
        }
        
        return $ordered;
    }
    
    /**
     * Save services order
     */
    public function save_order() {
        // Verify nonce
        if (!isset($_POST['cmplz_nonce']) || !wp_verify_nonce($_POST['cmplz_nonce'], 'cmplz_reorder_nonce')) {
            wp_die('Security check failed');
        }
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Save order
        $services_order = isset($_POST['services_order']) ? array_map('sanitize_text_field', $_POST['services_order']) : array();
        update_option($this->option_name, $services_order);
        
        // Redirect
        wp_redirect(add_query_arg(array(
            'page' => 'cmplz-services-order',
            'updated' => 'true'
        ), admin_url('options-general.php')));
        exit;
    }
    
    /**
     * Reorder services in page content
     */
    public function reorder_services($content) {
        // Get saved cookie policy page
        $saved_page_id = get_option($this->page_option_name, '');
        
        // If no page is saved, return original content
        if (empty($saved_page_id)) {
            return $content;
        }
        
        // Check if we're on the correct page
        if (!is_page($saved_page_id)) {
            return $content;
        }
        
        // Check if page contains cmplz services
        if (strpos($content, 'cmplz-service-desc') === false) {
            return $content;
        }
        
        // Get saved order
        $saved_order = get_option($this->option_name, array());
        
        if (empty($saved_order)) {
            return $content;
        }
        
        // Use DOMDocument to manipulate HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Find the cmplz-cookies-overview container
        $containers = $xpath->query("//*[@id='cmplz-cookies-overview']");
        
        if ($containers->length === 0) {
            return $content;
        }
        
        $container = $containers->item(0);
        
        // Find all services (details with cmplz-service-desc class)
        $service_elements = $xpath->query(".//details[contains(@class, 'cmplz-service-desc')]", $container);
        
        // Create associative array of services
        $services = array();
        $service_nodes = array();
        
        foreach ($service_elements as $element) {
            $h3_elements = $xpath->query(".//summary//h3", $element);
            if ($h3_elements->length > 0) {
                $service_name = trim($h3_elements->item(0)->textContent);
                $service_id = sanitize_title($service_name);
                $services[$service_id] = $element;
                $service_nodes[] = $element;
            }
        }
        
        // Remove all service nodes from DOM
        foreach ($service_nodes as $node) {
            $node->parentNode->removeChild($node);
        }
        
        // Reinsert services in specified order
        foreach ($saved_order as $service_id) {
            if (isset($services[$service_id])) {
                $container->appendChild($services[$service_id]);
            }
        }
        
        // Add any services not in the order (new services)
        foreach ($services as $service_id => $service_node) {
            if (!in_array($service_id, $saved_order)) {
                $container->appendChild($service_node);
            }
        }
        
        // Return modified content
        $new_content = $dom->saveHTML();
        
        // Remove tags added by DOMDocument
        $new_content = preg_replace('/^<!DOCTYPE.+?>/', '', $new_content);
        $new_content = str_replace(array('<html>', '</html>', '<body>', '</body>'), '', $new_content);
        
        return $new_content;
    }
}

// Initialize the plugin
new CMPLZ_Services_Reorder();