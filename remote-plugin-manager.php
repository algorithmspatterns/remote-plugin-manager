<?php
/*
Plugin Name: Remote Plugin Manager
Description: A plugin for downloading and updating other plugins from external servers, including GitHub.
Version: 1.3
Author: Konstantin Kryachko
Text Domain: remote-plugin-manager
Domain Path: /languages
*/

// Загрузка текстового домена
add_action('plugins_loaded', 'rpm_load_textdomain');

function rpm_load_textdomain() {
    load_plugin_textdomain('remote-plugin-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Хук для создания страницы настроек
add_action('admin_menu', 'rpm_add_admin_menu');
add_action('admin_init', 'rpm_register_settings');

function rpm_add_admin_menu() {
    add_options_page(__('Remote Plugin Manager', 'remote-plugin-manager'), __('Remote Plugin Manager', 'remote-plugin-manager'), 'manage_options', 'remote-plugin-manager', 'rpm_options_page');
}

function rpm_register_settings() {
    register_setting('rpm_settings', 'rpm_plugin_urls', array(
        'type' => 'array',
        'description' => __('URLs for remote plugin download', 'remote-plugin-manager'),
        'sanitize_callback' => 'rpm_sanitize_urls',
        'default' => array(),
    ));
}

function rpm_sanitize_urls($input) {
    if (!is_array($input)) {
        $input = explode("\n", $input); // Разделение строк по новому символу
    }

    return array_map('esc_url_raw', $input); // Очистка URL
}

// Отображение страницы настроек
function rpm_options_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Remote Plugin Manager', 'remote-plugin-manager'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('rpm_settings');
            do_settings_sections('rpm_settings');
            $urls = get_option('rpm_plugin_urls', array());
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('Plugin download paths', 'remote-plugin-manager'); ?></th>
                    <td>
                        <textarea name="rpm_plugin_urls" rows="10" cols="50"><?php echo esc_textarea(implode("\n", $urls)); ?></textarea>
                        <p class="description"><?php _e('Enter each URL on a new line.', 'remote-plugin-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h2><?php _e('Update Plugins', 'remote-plugin-manager'); ?></h2>
        <form method="post">
            <input type="hidden" name="rpm_update_plugins" value="1" />
            <?php submit_button(__('Update plugins from remote servers', 'remote-plugin-manager')); ?>
        </form>
    </div>
    <?php
}

// Функция для загрузки и обновления плагинов
function rpm_install_plugin($url) {
    $tmp_file = download_url($url);
    
    if (is_wp_error($tmp_file)) {
        error_log('Error downloading file: ' . $tmp_file->get_error_message());
        return $tmp_file;
    }

    // Создаем уникальное временное место для распаковки
    $tmp_dir = WP_PLUGIN_DIR . '/temp-plugin-install/';
    if (!file_exists($tmp_dir)) {
        mkdir($tmp_dir);
    }

    // Распаковываем во временную директорию
    $unzip_result = unzip_file($tmp_file, $tmp_dir);
    unlink($tmp_file);  // Удаляем временный файл

    if (is_wp_error($unzip_result)) {
        error_log('Error unzipping file: ' . $unzip_result->get_error_message());
        return $unzip_result;
    }

    // Находим папку с плагином
    $plugin_folder = rpm_find_plugin_folder($tmp_dir);
    if ($plugin_folder) {
        // Перемещаем содержимое в wp-content/plugins
        error_log('Found plugin folder: ' . $plugin_folder);
        $move_result = rpm_move_folder($plugin_folder, WP_PLUGIN_DIR);

        // Проверка на успешное перемещение
        if (is_wp_error($move_result)) {
            error_log('Error moving folder: ' . $move_result->get_error_message());
        } else {
            error_log('Plugin moved successfully.');
        }

        // Удаляем временную директорию
        rpm_delete_temp_directory($tmp_dir);
        return true;
    } else {
        error_log('Plugin folder not found in ' . $tmp_dir);
        return new WP_Error('plugin_folder_not_found', __('Plugin folder not found', 'remote-plugin-manager'));
    }
}

// Поиск папки с плагином
function rpm_find_plugin_folder($directory) {
    $folders = glob($directory . '*/', GLOB_ONLYDIR);
    if (!empty($folders)) {
        return $folders[0]; // Возвращаем первую найденную папку
    }
    return false;
}

// Функция для перемещения папки
function rpm_move_folder($source, $destination) {
    $source = rtrim($source, '/');
    $destination = rtrim($destination, '/') . '/' . basename($source);

    if (!file_exists($destination)) {
        if (rename($source, $destination)) {
            return true;
        } else {
            return new WP_Error('rename_failed', __('Failed to move folder', 'remote-plugin-manager'));
        }
    } else {
        return new WP_Error('destination_exists', __('Destination folder already exists', 'remote-plugin-manager'));
    }
}

// Удаление временной директории
function rpm_delete_temp_directory($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    rpm_delete_temp_directory($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

// Обработка запроса на обновление плагинов
add_action('admin_init', 'rpm_handle_plugin_updates');
function rpm_handle_plugin_updates() {
    if (isset($_POST['rpm_update_plugins'])) {
        rpm_update_plugins();
        add_action('admin_notices', 'rpm_show_update_notice');
    }
}

function rpm_update_plugins() {
    $urls = get_option('rpm_plugin_urls', array());

    foreach ($urls as $url) {
        $result = rpm_install_plugin($url);
        if (is_wp_error($result)) {
            error_log(__('Error downloading plugin from ', 'remote-plugin-manager') . $url . ': ' . $result->get_error_message());
        }
    }
}

function rpm_show_update_notice() {
    echo '<div class="notice notice-success is-dismissible"><p>' . __('All plugins have been updated.', 'remote-plugin-manager') . '</p></div>';
}
