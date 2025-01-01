<?php
/*
Plugin Name: MyBB Sync for Vikinger
Plugin URI: https://ulties.com/
Description: Synchronizes MyBB forum activity with the Vikinger WordPress theme, integrating gamification features such as XP, badges, and ranks.
Version: 2.3.0
Author: Joey Awwad
Author URI: https://ulties.com/
License: GPL-2.0+
Text Domain: mybb-sync-vikinger
Domain Path: /languages/
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class MyBBSyncVikinger {

    public static function init() {
        // Actions
        add_action('admin_init', [__CLASS__, 'initialize_settings']);
        add_action('init', [__CLASS__, 'load_textdomain']);
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('wp_login', [__CLASS__, 'sync_user_login'], 10, 2);

        // Plugin activation and deactivation hooks
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function activate() {
        // Add default options
        add_option('mybb_sync_host', 'localhost');
        add_option('mybb_sync_db', '');
        add_option('mybb_sync_db_username', '');
        add_option('mybb_sync_db_password', '');
        add_option('mybb_sync_table_prefix', 'mybbyx_');
    }

    public static function deactivate() {
        // Remove options
        delete_option('mybb_sync_host');
        delete_option('mybb_sync_db');
        delete_option('mybb_sync_db_username');
        delete_option('mybb_sync_db_password');
        delete_option('mybb_sync_table_prefix');
    }

    public static function initialize_settings() {
        // Register settings
        register_setting('mybb_sync_options', 'mybb_sync_host');
        register_setting('mybb_sync_options', 'mybb_sync_db');
        register_setting('mybb_sync_options', 'mybb_sync_db_username');
        register_setting('mybb_sync_options', 'mybb_sync_db_password');
        register_setting('mybb_sync_options', 'mybb_sync_table_prefix');
    }

    public static function load_textdomain() {
        load_plugin_textdomain('mybb-sync-vikinger', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public static function add_settings_page() {
        add_options_page(
            __('MyBB Sync Settings', 'mybb-sync-vikinger'),
            __('MyBB Sync', 'mybb-sync-vikinger'),
            'manage_options',
            'mybb-sync-vikinger',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('MyBB Sync Settings', 'mybb-sync-vikinger'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('mybb_sync_options');
                do_settings_sections('mybb_sync_options');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mybb_sync_host"><?php _e('MyBB Host', 'mybb-sync-vikinger'); ?></label></th>
                        <td><input type="text" id="mybb_sync_host" name="mybb_sync_host" value="<?php echo esc_attr(get_option('mybb_sync_host')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mybb_sync_db"><?php _e('Database Name', 'mybb-sync-vikinger'); ?></label></th>
                        <td><input type="text" id="mybb_sync_db" name="mybb_sync_db" value="<?php echo esc_attr(get_option('mybb_sync_db')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mybb_sync_db_username"><?php _e('Database Username', 'mybb-sync-vikinger'); ?></label></th>
                        <td><input type="text" id="mybb_sync_db_username" name="mybb_sync_db_username" value="<?php echo esc_attr(get_option('mybb_sync_db_username')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mybb_sync_db_password"><?php _e('Database Password', 'mybb-sync-vikinger'); ?></label></th>
                        <td><input type="password" id="mybb_sync_db_password" name="mybb_sync_db_password" value="<?php echo esc_attr(get_option('mybb_sync_db_password')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mybb_sync_table_prefix"><?php _e('Table Prefix', 'mybb-sync-vikinger'); ?></label></th>
                        <td><input type="text" id="mybb_sync_table_prefix" name="mybb_sync_table_prefix" value="<?php echo esc_attr(get_option('mybb_sync_table_prefix')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function sync_user_login($user_login, $user) {
        $db_host = get_option('mybb_sync_host');
        $db_name = get_option('mybb_sync_db');
        $db_user = get_option('mybb_sync_db_username');
        $db_password = get_option('mybb_sync_db_password');
        $table_prefix = get_option('mybb_sync_table_prefix');

        $connection = new mysqli($db_host, $db_user, $db_password, $db_name);

        if ($connection->connect_error) {
            error_log('MyBB Sync Error: Database connection failed - ' . $connection->connect_error);
            return;
        }

        $ms_username = $user->user_login;
        $ms_email = $user->user_email;
        $ms_password = $_POST['pwd'];

        $query = $connection->prepare("SELECT * FROM `" . $table_prefix . "users` WHERE `username` = ? OR `email` = ?");
        $query->bind_param('ss', $ms_username, $ms_email);
        $query->execute();
        $result = $query->get_result();

        if ($result->num_rows === 0) {
            $salt = substr(md5(uniqid(rand(), true)), 0, 8); // Custom salt generation
            $hashed_password = md5(md5($salt) . md5($ms_password)); // MyBB hashing logic

            $regdate = time();
            $insert = $connection->prepare("INSERT INTO `" . $table_prefix . "users` (username, password, salt, email, receivepms, allownotices, pmnotify, usergroup, regdate) VALUES (?, ?, ?, ?, 1, 1, 1, 2, ?)");
            $insert->bind_param('ssssi', $ms_username, $hashed_password, $salt, $ms_email, $regdate);
            $insert->execute();

            if ($insert->affected_rows > 0) {
                error_log("MyBB Sync: User '{$ms_username}' successfully added.");
            } else {
                error_log("MyBB Sync: Failed to insert user '{$ms_username}'.");
            }
        }

        $connection->close();
    }
}

MyBBSyncVikinger::init();