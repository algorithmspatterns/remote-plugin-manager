<?php
/*
Plugin Name: Remote Plugin Manager
Description: A plugin for downloading and updating other plugins from external servers, using WordPress filesystem API.
Plugin URI: https://github.com/algorithmspatterns/remote-plugin-manager
Version: 0.6
Author: Konstantin Kryachko
Author URI: https://websolutionist.cc/
License: GPL v3
License URI: https://www.gnu.org/licenses/gpl-3.0.txt
Update URI: https://github.com/algorithmspatterns/remote-plugin-manager/archive/refs/heads/master.zip
Text Domain: remote-plugin-manager
Domain Path: /languages
*/

require_once plugin_dir_path(__FILE__) . 'inc/masterlist-handler.php'; // Подключение файла с обработчиком коллекций

// Загрузка текстового домена
add_action('plugins_loaded', 'rpm_load_textdomain');

function rpm_load_textdomain() {
    load_plugin_textdomain('remote-plugin-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Хук для создания страницы настроек
add_action('admin_menu', 'rpm_add_admin_menu');
add_action('admin_init', 'rpm_register_settings');
register_activation_hook(__FILE__, 'rpm_activate_plugin');

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

// Инициализация при активации
function rpm_activate_plugin() {
  // Загружаем masterlist.json и добавляем ссылки в базу
  rpm_load_masterlist();
}

// Отображение страницы настроек
function rpm_options_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Remote Plugin Manager', 'remote-plugin-manager'); ?></h1>
        <!-- Блок выбора коллекции -->
        <form method="post" action="">
            <h2><?php _e('Select Plugin Collection', 'remote-plugin-manager'); ?></h2>
            <?php rpm_display_collection_selector(); ?>
        </form>
        <!-- Поле для ручного добавления ссылок -->
        <h2><?php _e('Plugin URLs', 'remote-plugin-manager'); ?></h2>
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
        <!-- Блок загрузки новой коллекции по URL -->
        <h2><?php _e('Upload Collection by URL', 'remote-plugin-manager'); ?></h2>
        <form method="post" action="">
            <input type="text" name="rpm_collection_url" placeholder="https://example.com/collection.json" size="50">
            <?php submit_button(__('Download and Save Collection', 'remote-plugin-manager'), 'secondary', 'rpm_download_collection'); ?>
        </form>
    </div>
    <?php
}

// Обработка загрузки коллекции по URL
if (isset($_POST['rpm_download_collection'])) {
  $url = sanitize_text_field($_POST['rpm_collection_url']);
  rpm_download_collection($url);
}

// Добавление или замена коллекций на основе выбора
if (isset($_POST['action'])) {
  if (isset($_POST['collection']) && !empty($_POST['collection'])) {
      $file = sanitize_text_field($_POST['collection']);
      $file_path = 'collections/' . $file;
      
      if ($_POST['action'] === 'add') {
          rpm_add_collection_urls($file_path);
      } elseif ($_POST['action'] === 'replace') {
          rpm_replace_collection_urls($file_path);
      }
  } else {
      // Вывод сообщения об ошибке, если коллекция не выбрана
      echo '<div class="notice notice-error is-dismissible"><p>' . __('No collection selected. Please select a collection.', 'remote-plugin-manager') . '</p></div>';
  }
}


// Функция для загрузки и обновления плагинов
function rpm_install_plugin($url) {
    global $wp_filesystem;

    // Инициализация WordPress Filesystem API
    if ( ! rpm_init_wp_filesystem() ) {
        return new WP_Error('filesystem_error', __('Could not initialize filesystem.', 'remote-plugin-manager'));
    }

    // Загружаем файл по URL
    $tmp_file = download_url($url);
    
    if (is_wp_error($tmp_file)) {
        return $tmp_file;
    }

    // Определяем имя файла плагина
    $plugin_folder = WP_PLUGIN_DIR;
    $destination = $plugin_folder . '/' . basename($url, '.zip'); // Директория для распаковки плагина

    // Распаковка архива плагина в директорию плагинов
    $unzip_result = unzip_file($tmp_file, $plugin_folder);
    unlink($tmp_file);  // Удаляем временный файл

    if (is_wp_error($unzip_result)) {
        return $unzip_result;
    }

    return true;
}

// Инициализация файловой системы WordPress
function rpm_init_wp_filesystem() {
    global $wp_filesystem;

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $creds = request_filesystem_credentials('', '', false, false, array());

    if ( ! WP_Filesystem($creds) ) {
        return false;
    }

    return true;
}

// Обработка запроса на обновление плагинов
add_action('admin_init', 'rpm_handle_plugin_updates');
function rpm_handle_plugin_updates() {
    if (isset($_POST['rpm_update_plugins'])) {
        $urls = get_option('rpm_plugin_urls', array());

        // Проверка, есть ли указанные URL и что хотя бы один URL не пуст
        $urls = array_filter($urls, 'trim');  // Удаляем пустые строки из массива

        if (empty($urls)) {
            add_action('admin_notices', 'rpm_show_no_urls_notice');  // Показываем ошибку, если URL нет
        } else {
            rpm_update_plugins();  // Обновляем плагины только если URL существуют
            add_action('admin_notices', 'rpm_show_update_notice');  // Показываем успех, если обновление прошло
        }
    }
}

// Функция для вывода уведомления, если URL не указаны
function rpm_show_no_urls_notice() {
    echo '<div class="notice notice-error is-dismissible"><p>' . __('No plugin URLs found. Please specify plugin URLs before attempting to update or install plugins.', 'remote-plugin-manager') . '</p></div>';
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
