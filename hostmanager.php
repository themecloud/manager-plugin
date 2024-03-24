<?php

/**
 * Plugin Name: Manager
 * Plugin URI: https://themecloud.io
 * Description: This plugin is ideal to effortlessly manage your website.
 * Version: 0.1.0
 * Author: Themecloud
 * Author URI: https://themecloud.io
 * License: GPLv2 or later
 */
if (!file_exists('/app/.include/manager.php')) {
    exit;
}
require_once('/app/.include/manager.php');

$app_id = defined('APP_ID') ? APP_ID : false;
$branch = defined('BRANCH') ? BRANCH : false;
$wp_api_key = defined('WP_API_KEY') ? WP_API_KEY : false;
$cfcache_enabled = defined('CFCACHE_ENABLED') ? CFCACHE_ENABLED : "false";
$private = defined('PRIVATE_MODE') ? PRIVATE_MODE : "false";

$app_env = ['APP_ID' => $app_id, 'BRANCH' => $branch, 'WP_API_KEY' => $wp_api_key, 'CFCACHE_ENABLED' => $cfcache_enabled];

if (strpos($_SERVER['REQUEST_URI'], 'hostmanager') !== false) {
    require_once ABSPATH . 'wp-load.php';
    add_filter('option_active_plugins', 'skipplugins_plugins_filter');
    function skipplugins_plugins_filter($plugins)
    {
        foreach ($plugins as $i => $plugin) {
            if ($plugin != "simply-static/simply-static.php" && $plugin != "advanced-custom-fields/acf.php" && $plugin != "advanced-custom-fields-pro/acf.php") {
                unset($plugins[$i]);
            }
        }
        return $plugins;
    }
}

function faaaster_disable_filters_for_manager_plugin($response)
{
    $request_url = $_SERVER['REQUEST_URI'];
    // Check if the URL contains "manager-plugin"
    if (strpos($request_url, 'hostmanager') !== false or strpos($request_url, 'sso') !== false) {
        // Remove all filters on the "rest_not_logged_in" hook
        remove_all_filters('rest_not_logged_in');
        remove_all_filters('rest_authentication_errors');
    }

    return $response;
}
add_action('rest_api_init', 'faaaster_disable_filters_for_manager_plugin');

require_once('plugin.php');
require_once('core.php');
require_once('site-state.php');
require_once('mu-plugin-manager.php');
require_once('loginSSO.php');

$siteState = new SiteState();
$muManager = new MUPluginManager();

// add_action('admin_enqueue_scripts', 'faaaster_hostmanager_assets');
// add_action('admin_menu', 'faaaster_manager_setup_menu');

function faaaster_hostmanager_assets($hook)
{

    // copy files into wp_content_dir because mu-plugins isn't accessible

    $plugin_data = get_plugin_data(__FILE__);
    $plugin_domain = "/" . $plugin_data['TextDomain'];

    $pluginDir = plugin_dir_path(__FILE__);

    if (!file_exists(WP_CONTENT_DIR . $plugin_domain . "/js")) {
        mkdir(WP_CONTENT_DIR . $plugin_domain . "/js", 0755, true);
    }

    if (!file_exists(WP_CONTENT_DIR . $plugin_domain . "/js/script.js")) {
        copy($pluginDir . "/js/script.js", WP_CONTENT_DIR . $plugin_domain . "/js/script.js");
    }

    // only enqueue script on our own page
    if ('toplevel_page_hostmanager' != $hook) {
        return;
    }

    // Charger notre script
    wp_enqueue_script('hostmanager',  WP_CONTENT_URL . $plugin_domain . "/js/script.js", array('jquery'), '1.0', true);

    // Envoyer une variable de PHP à JS proprement
    wp_localize_script('hostmanager', 'hostmanager', ['url' => get_site_url(), 'nonce' => wp_create_nonce('wp_rest'), 'tc_token' => $_COOKIE['tc_token']]);
}

function faaaster_manager_setup_menu()
{

    add_submenu_page(null, 'HostManger Plugin', 'HostManger Plugin', 'manage_options', 'hostmanager', 'faaaster_manager_init');
}

function faaaster_manager_init()
{

    global $muManager;

    $html = '
    <p>Toggle Benchmark plugin</p>
        <input type="submit" id="togglePlugin" name="togglePlugin"
                class="button" value="Toggle plugin" />

    ';

    echo $html;
}

function faaaster_get_check()
{
    $data = array(
        "code" => "ok",
    );

    return new WP_REST_Response($data, 200);
}

function faaaster_manager_do_remote_get(string $url, array $args = array())
{
    $headers = array(
        "X-Purge-Cache:true",
        "Host:" . wp_parse_url(home_url())['host'],
    );

    $ch = curl_init();

    //this will set the minimum time to wait before proceed to the next line to 100 milliseconds
    curl_setopt($ch, CURLOPT_URL, "$url");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    curl_setopt($ch, CURLOPT_COOKIE, '"trial_bypass":"true"');
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_exec($ch);

    //this line will be executed after 100 milliseconds

    curl_close($ch);
}

function faaaster_manager_clear_all_cache()
{
    // OP Cache
    opcache_reset();

    // New Method fcgi
    $_url_purge      = "http://localhost/purge-all";
    faaaster_manager_do_remote_get($_url_purge);

    // Pagespeed
    touch('/tmp/pagespeed/cache.flush');

    // Cloudflare
    if ($app_id && $wp_api_key && $branch && $cfcache_enabled == "true" && function_exists('faaaster_cf_purge_all')) {
        faaaster_cf_purge_all();
    }

    // Cache objet WordPress
    wp_cache_flush();
}

function faaaster_toggle_mu_plugin($request)
{
    global $muManager;

    // verify if user nonce is valid and can do something
    if (!current_user_can('manage_options')) {
        // if not check X-TC_TOKEN that has been set to the tc-token cookie
        if (!$request->get_header('X-TC-TOKEN')) {
            $data = array(
                "code" => "no_tc_token",
                "data" => "Need to set X-TC-Token header"
            );

            return new WP_REST_Response($data, 403);
        }

        $ssoClass = new LoginSSO();

        $verified = $ssoClass->verifyTCToken($request->get_header('X-TC-TOKEN'));

        // invalid tc token
        if (!$verified) {
            $data = array(
                "code" => "invalid_tc_token",
                "data" => "Invalid TC Token"
            );

            return new WP_REST_Response($data, 403);
        }
    }

    $data = array(
        "code" => "ok",
        "data" => $muManager->togglePlugin()
    );

    return new WP_REST_Response($data, 200);
}

// Get site info
function faaaster_get_site_state()
{
    global $siteState;
    $data = array(
        "code" => "ok",
        "data" => $siteState->get_site_full_state()
    );

    return new WP_REST_Response($data, 200);
}

// Update plugin
function faaaster_plugin_upgrade($request)
{
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->plugin_upgrade($request);
}

// Install plugin
function faaaster_plugin_install($request)
{
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->restInstall($request);
}

// Activate / deactivate plugin
function faaaster_plugin_toggle($request)
{
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->restToggle($request);
}

// List plugins
function faaaster_plugin_list($request)
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();

    $data = array(
        "code" => "ok",
        "data" =>  array("json" => json_encode($all_plugins))
    );

    return new WP_REST_Response($data, 200);
}

// Check integruty of core and plugins
function faaaster_integrity_check($request)
{
    exec('wp core verify-checksums --skip-plugins --skip-themes', $output, $return_var);
    if ($return_var !== 0) {
        // Handle error
        echo "Error executing command: " . implode("\n", $output);
        $core_integrity = false;
    } else {
        // Command executed successfully
        echo "Command executed successfully: " . implode("\n", $output);
        $core_integrity = true;
    }
    $plugins_integrity = shell_exec('wp plugin verify-checksums --skip-plugins --skip-themes --all --format=json');

    $data = array(
        "code" => "ok",
        "data" =>   array(
            "core" => $core_integrity,
            "plugins" => json_decode($plugins_integrity)
        )
    );

    return new WP_REST_Response($data, 200);
}

// Update core
function faaaster_update_core($request)
{
    $params = $request->get_query_params();
    $coreUpgrader = new CoreUpgrade();
    return $coreUpgrader->core_update($params);
}

// Reinstall core from wp.org
function faaaster_reinstall_core($request)
{
    exec('wp core download --skip-content --force --skip-plugins --skip-themes', $output, $return_var);
    if ($return_var !== 0) {
        // Handle error
        echo "Error executing command: " . implode("\n", $output);
        $data = array(
            "code" => "ko",
            "error" => json_encode($output),
        );
        return new WP_REST_Response($data, 200);
    } else {
        // Command executed successfully
        echo "Command executed successfully: " . implode("\n", $output);
        $data = array(
            "code" => "ok",
        );
        return new WP_REST_Response($data, 200);
    }
}

// Reinstall plugins from wp.org
function faaaster_reinstall_plugins($request)
{
    exec('wp plugin --force --skip-plugins --skip-themes install $(wp plugin list --force --skip-plugins --skip-themes --field=name | grep -v "nginx-helper") --force', $output, $return_var);
    if ($return_var !== 0) {
        // Handle error
        echo "Error executing command: " . implode("\n", $output);
        $data = array(
            "code" => "ko",
            "error" => json_encode($output),
        );
        return new WP_REST_Response($data, 200);
    } else {
        // Command executed successfully
        echo "Command executed successfully: " . implode("\n", $output);
        $data = array(
            "code" => "ok",
        );
        return new WP_REST_Response($data, 200);
    }
}

// Clear Cache
function faaaster_clear_cache($request)
{
    $clear_cache = faaaster_manager_clear_all_cache();

    $data = array(
        "code" => "ok",
    );

    return new WP_REST_Response($data, 200);
}

// Disable or enable emails
function faaaster_handle_email_control($request)
{
    $enable = $request->get_param('enable');

    if ($enable === 'yes') {
        update_option('disable_emails', 'no');
        return new WP_REST_Response('Emails enabled', 200);
    } else {
        update_option('disable_emails', 'yes');
        return new WP_REST_Response('Emails disabled', 200);
    }
}

// Set Astra Key
function faaaster_handle_astra_key($request)
{
    update_option('astra_key', $request->get_param('key'));
    return new WP_REST_Response('Astra Key', 200);
}

// Enable static
function faaaster_enable_static()
{
    $plugin_slug = 'simply-static';
    $plugin_path = 'simply-static/simply-static.php';

    // Check if the plugin is installed
    if (!file_exists(WP_CONTENT_DIR . "/plugins/" . $plugin_path)) {
        // Install the plugin
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $api = plugins_api('plugin_information', array('slug' => $plugin_slug));
        if (is_wp_error($api)) {
            return new WP_Error('plugin_error', 'Plugin information retrieval failed.');
        }

        $upgrader = new Plugin_Upgrader();
        $installed = $upgrader->install($api->download_link);

        if (is_wp_error($installed)) {
            return new WP_Error('install_failed', 'Plugin installation failed.');
        }
        // Attempt to activate the plugin
        activate_plugin($plugin_path);


        return rest_ensure_response(array('success' => true, 'message' => 'Plugin installed and activated.'));
    } else {
        if (!is_plugin_active($plugin_path)) {
            // Activate the plugin
            activate_plugin($plugin_path);

            return rest_ensure_response(array('success' => true, 'message' => 'Plugin activated.'));
        }
        // Plugin is already installed and active
        return rest_ensure_response(array('success' => true, 'message' => 'Plugin already installed and active.'));
    }
}

// Build a static export
function faaaster_run_static_export()
{ {
        if (!class_exists('Simply_Static\Plugin')) {
            // If the class does not exist, return early
            return new WP_REST_Response('Static not enabled', 400);
        }

        // Full static export
        $simply_static = Simply_Static\Plugin::instance();
        $simply_static->run_static_export();
        return new WP_REST_Response('Static export launched', 200);
    }
}

// Intercept emails
function faaaster_intercept_emails($args)
{
    if (get_option('disable_emails') === 'yes' && $private == "true") {
        return []; // Returning an empty array to cancel email sending
    }
    return $args;
}
add_filter('wp_mail', 'faaaster_intercept_emails');


// Get DB prefix
function faaaster_get_db_prefix()
{
    global $wpdb;
    $data = array(
        "code" => "ok",
        "data" => $wpdb->base_prefix
    );

    return new WP_REST_Response($data, 200);
}

// Login
function faaaster_login()
{
    include('request/index.php');
}

/**
 * at_rest_init
 */
function faaaster_at_rest_init()
{
    // route url: domain.com/wp-json/$namespace/$route
    $namespace = 'hostmanager/v1';

    $namespacePublic = 'public-hostmanager/v1';

    register_rest_route($namespace, '/site_state', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_get_site_state',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));


    register_rest_route($namespace, '/get_check', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_get_check',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/db_prefix', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_get_db_prefix',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_upgrade', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_plugin_upgrade',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_install', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_plugin_install',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_toggle', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_plugin_toggle',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_list', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_plugin_list',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/update_core', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_update_core',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/reinstall_core', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_reinstall_core',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/reinstall_plugins', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_reinstall_plugins',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/integrity_check', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_integrity_check',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/clear_cache', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_clear_cache',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/toggle_email', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_handle_email_control',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/astra_key', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_handle_astra_key',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/static_enable', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_enable_static',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/static_push', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_run_static_export',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespacePublic, '/toggle_mu_plugin', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_toggle_mu_plugin',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route('sso/v1', '/login', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_login',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));
}

add_action('rest_api_init', 'faaaster_at_rest_init');

if (defined('HIDE_WP_ERRORS') == false) {
    define('HIDE_WP_ERRORS', true);
}
// On désactive les indices de connexion WP
function faaaster_no_wordpress_errors()
{
    return 'Something is wrong!';
}
if (HIDE_WP_ERRORS != false) {
    add_filter('login_errors', 'faaaster_no_wordpress_errors');
}

// On cache la version de WP
function faaaster_remove_wordpress_version()
{
    return '';
}
add_filter('the_generator', 'faaaster_remove_wordpress_version');


// Pick out the version number from scripts and styles
function faaaster_remove_version_from_style_js($src)
{
    if (strpos($src, 'ver=' . get_bloginfo('version')))
        $src = remove_query_arg('ver', $src);
    return $src;
}
add_filter('style_loader_src', 'faaaster_remove_version_from_style_js');
add_filter('script_loader_src', 'faaaster_remove_version_from_style_js');


// Manage Cloudflare cache
if ($app_id && $wp_api_key && $branch && $cfcache_enabled == "true") {
    error_log("CF CACHE ENABLED " . $cfcache_enabled);
    function faaaster_cf_purge_all()
    {
        // error_log("Purge everything");
        $url = "https://app.faaaster.io/api/applications/" . APP_ID . "/instances/" . BRANCH . "/cloudflare";
        $data = array(
            'scope' => 'everything',
        );
        // Define the request arguments
        $args = array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . WP_API_KEY, // Add the Authorization header with the API key
            ),
        );
        // Make the API call
        $response = wp_remote_post($url, $args);

        // Check for errors and handle the response
        if (is_wp_error($response)) {
            echo "Error: " . $response->get_error_message();
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                // error_log("API call successful! Response: " . $response_body);
            } else {
                // error_log("API call failed with response code $response_code. Response: " . $response_body);
            }
        }
    }

    function faaaster_cf_purge_urls($urls)
    {

        // error_log("Purge urls" . JSON_ENCODE($urls));
        $url = "https://app.faaaster.io/api/applications/" . APP_ID . "/instances/" . BRANCH . "/cloudflare";
        $data = array(
            'scope' => 'urls',
            'urls' => array($urls)
        );
        // Define the request arguments
        $args = array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . WP_API_KEY, // Add the Authorization header with the API key
            ),
        );

        // Make the API call
        $response = wp_remote_post($url, $args);

        // Check for errors and handle the response
        if (is_wp_error($response)) {
            echo "Error: " . $response->get_error_message();
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                // error_log("API call successful! Response: " . $response_body);
            } else {
                // error_log("API call failed with response code $response_code. Response: " . $response_body);
            }
        }
    }

    add_action('rt_nginx_helper_after_fastcgi_purge_all', 'faaaster_cf_purge_all', PHP_INT_MAX);
    add_action('rt_nginx_helper_fastcgi_purge_url', 'faaaster_cf_purge_urls', PHP_INT_MAX, 1);


    # trigger event if component updated
    function faaaster_updater_updated_action($upgrader_object, $options)
    {
        // Get the update action (core, plugin, or theme)
        $action = $options['action'];

        // Get the update type (update, install, or delete)
        $type = $options['type'];

        if ($action === "update") {

            // Get the user information
            $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";

            // Format the date and time
            $date_time = current_time('mysql');

            // initialize components
            $components = [];

            // Check for different update types
            if ($type === 'plugin') {
                if (isset($options['bulk']) && $options['bulk'] == "true") {
                    foreach ($options['plugins'] as $each_plugin) {
                        $plugin = get_plugin_data(WP_CONTENT_DIR . "/plugins/" . $each_plugin);
                        $old_version = $upgrader_object->skin->plugin_info['Version'];
                        $name = $plugin["Name"];
                        $new_version = $plugin["Version"];
                        $plugin = $plugin["Name"] . " - " . $old_version . " >> " . $plugin["Version"];
                        $components[] = $plugin;
                    }
                } else {
                    $plugin = get_plugin_data(WP_CONTENT_DIR . "/plugins/" . $options['plugin']);
                    $old_version = $upgrader_object->skin->plugin_info['Version'];
                    $name = $plugin["Name"];
                    $new_version = $plugin["Version"];
                    $plugin = $plugin["Name"] . " - " . $old_version . " >> " . $plugin["Version"];
                    $components[] = $plugin;
                }
            } elseif ($type === 'theme') {
                if (isset($options['bulk']) && $options['bulk'] == "true") {

                    foreach ($options['themes'] as $each_theme) {
                        $theme = wp_get_theme($each_theme);
                        $old_version = get_transient('theme_' . $each_theme . '_old_version');
                        $name = $theme["Name"];
                        $new_version = $theme["Version"];
                        $theme = $old_version ? $theme["Name"] . " - " . $old_version . " >> " . $theme["Version"] :  $theme["Name"] . " - " . $theme["Version"];
                        $components[] = $theme;
                    }
                } else {
                    $theme =  wp_get_theme($options['theme']);
                    $old_version = get_transient('theme_' . $options['theme'] . '_old_version');
                    $name = $theme["Name"];
                    $new_version = $theme["Version"];
                    $theme = $old_version ? $theme["Name"] . " - " . $old_version . " >> " . $theme["Version"] :  $theme["Name"] . " - " . $theme["Version"];
                    $components[] = $theme;
                }
            } elseif ($type === 'core' || $type === 'translation') {
                return;
            }
            $url = "https://app.faaaster.io/api/webhook-event/";
            $data = array(
                'event' => "upgrader",
                'data' => array(
                    'action' => $action,
                    'type' => $type,
                    'components' => $components,
                    'name' => $name,
                    'old_version' => $old_version,
                    'new_version' => $new_version,
                    'user' => $user->user_email,
                    'date' => $date_time,
                ),
                'app_id' => APP_ID,
                'instance' => BRANCH,
            );
            // Define the request arguments
            $args = array(
                'body' => json_encode($data),
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' .  WP_API_KEY, // Add the Authorization header with the API key
                ),
            );
            // Make the API call
            if (!wp_remote_post($url, $args)) {
                error_log("Update event error");
            }
        }
    }
    add_action('upgrader_process_complete', 'faaaster_updater_updated_action', 10, 2);

    // Manage core updates
    function faaaster_on_wp_core_update($wp_version)
    {
        // Retrieve the old version
        $old_version = get_option('wp_pre_update_version');

        // Get the new version
        $new_version = $wp_version;

        $components[] = "WordPress" . " - " . $old_version . " >> " . $new_version;
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";
        $date_time = current_time('mysql');
        $url = "https://app.faaaster.io/api/webhook-event/";
        $data = array(
            'event' => "upgrader",
            'data' => array(
                'action' => "update",
                'type' => "core",
                'old_version' => $old_version,
                'new_version' => $new_version,
                'components' => $components,
                'user' => $user->user_email,
                'date' => $date_time,
            ),
            'app_id' => APP_ID,
            'instance' => BRANCH,
        );
        // Define the request arguments
        $args = array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' .  WP_API_KEY, // Add the Authorization header with the API key
            ),
        );
        // Make the API call
        if (!wp_remote_post($url, $args)) {
            error_log("Update event error");
        }

        // Clean up the transient
        delete_transient('wp_old_version');
    }
    add_action('_core_updated_successfully', 'faaaster_on_wp_core_update');

    // Capture old theme version
    function faaaster_capture_old_theme_version($true, $hook_extra)
    {
        if (isset($hook_extra['theme'])) {
            $theme_slug = $hook_extra['theme'];
            $theme = wp_get_theme($theme_slug);

            $old_version = $theme->get('Version');

            // Store the old version in a transient
            set_transient('theme_' . $theme_slug . '_old_version', $old_version, 60 * 10); // 10 minutes
        }
        return $true;
    }
    add_action('upgrader_pre_install', 'faaaster_capture_old_theme_version', 10, 2);

    // Capture old core version
    function faaaster_capture_wp_current_version()
    {
        if (!get_option('wp_pre_update_version')) {
            update_option('wp_pre_update_version', get_bloginfo('version'));
        } elseif (get_option('wp_pre_update_version') != get_bloginfo('version')) {
            update_option('wp_pre_update_version', get_bloginfo('version'));
        }
    }
    add_action('admin_init', 'faaaster_capture_wp_current_version');

    // Activation hook
    function faaaster_plugin_activate_action($plugin, $action)
    {
        // Get the user information
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";

        // Format the date and time
        $date_time = current_time('mysql');
        $plugin = get_plugin_data(WP_CONTENT_DIR . "/plugins/" . $plugin);
        $components = $plugin["Name"] . " - " . $plugin["Version"];

        $url = "https://app.faaaster.io/api/webhook-event/";
        $data = array(
            'event' => $action,
            'data' => array(
                'type' => "plugin",
                'components' => $components,
                'name' => $plugin["Name"],
                'version' => $plugin["Version"],
                'user' => $user->user_email,
                'date' => $date_time,
            ),
            'app_id' => APP_ID,
            'instance' => BRANCH,
        );
        // Define the request arguments
        $args = array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' .  WP_API_KEY, // Add the Authorization header with the API key
            ),
        );
        // Make the API call
        if (!wp_remote_post($url, $args)) {
            error_log("Install event error");
        }
    }
    add_action('activated_plugin', function ($plugin) {
        faaaster_plugin_activate_action($plugin, 'activate');
    });
    add_action('deactivated_plugin', function ($plugin) {
        faaaster_plugin_activate_action($plugin, 'deactivate');
    });

    function faaaster_theme_deactivation_action($new_theme, $old_theme)
    {
        // $new_theme is the newly activated theme
        // $old_theme is the deactivated theme

        // Get the user information
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";

        // Format the date and time
        $date_time = current_time('mysql');

        $url = "https://app.faaaster.io/api/webhook-event/";
        $data = array(
            'event' => "switch_theme",
            'data' => array(
                'type' => "theme",
                'components' => array(
                    'new' => $new_theme,
                    'old' => $old_theme,
                ),
                'user' => $user->user_email,
                'date' => $date_time,
            ),
            'app_id' => APP_ID,
            'instance' => BRANCH,
        );
        // Define the request arguments
        $args = array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' .  WP_API_KEY, // Add the Authorization header with the API key
            ),
        );
        // Make the API call
        if (!wp_remote_post($url, $args)) {
            error_log("Install event error");
        }
    }
    add_action('switch_theme', 'faaaster_theme_deactivation_action', 10, 2);
}
