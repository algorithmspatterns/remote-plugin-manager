<?php
/*
Plugin Name: Remote Plugin Manager
Description: Плагин для загрузки и обновления других плагинов с внешних серверов.
Version: 1.0
Author: Konstantin Kryachko
*/

// Хук для создания страницы настроек
add_action('admin_menu', 'rpm_add_admin_menu');
add_action('admin_init', 'rpm_register_settings');

function rpm_add_admin_menu() {
    add_options_page('Remote Plugin Manager', 'Remote Plugin Manager', 'manage_options', 'remote-plugin-manager', 'rpm_options_page');
}

function rpm_register_settings() {
    register_setting('rpm_settings', 'rpm_plugin_urls', array(
        'type' => 'array',
        'description' => 'URLs for remote plugin download',
        'sanitize_callback' => 'rpm_sanitize_urls',
        'default' => array(),
    ));
}

function rpm_sanitize_urls($input) {
    if (!is_array($input)) {
        return array();
    }

    return array_map('esc_url_raw', $input);
}

// Отображение страницы настроек
function rpm_options_page() {
?>
    <div class="wrap">
        <h1>Remote Plugin Manager</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('rpm_settings');
            do_settings_sections('rpm_settings');
            $urls = get_option('rpm_plugin_urls', array());
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Пути для загрузки плагинов</th>
                    <td>
                        <textarea name="rpm_plugin_urls" rows="10" cols="50"><?php echo implode("\n", $urls); ?></textarea>
                        <p class="description">Введите каждый URL на новой строке.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php
}

// Функция для загрузки и обновления плагинов
function rpm_install_plugin($url) {
    $tmp_file = download_url($url);
    
    if (is_wp_error($tmp_file)) {
        return $tmp_file;
    }

    $unzip_result = unzip_file($tmp_file, WP_PLUGIN_DIR);
    unlink($tmp_file);  // Удаляем временный файл

    if (is_wp_error($unzip_result)) {
        return $unzip_result;
    }

    return true;
}

// Добавляем возможность обновления через внешний сервер
function rpm_update_plugins() {
    $urls = get_option('rpm_plugin_urls', array());

    foreach ($urls as $url) {
        $result = rpm_install_plugin($url);
        if (is_wp_error($result)) {
            error_log('Ошибка загрузки плагина с ' . $url . ': ' . $result->get_error_message());
        }
    }
}

// Добавляем кнопку для ручного обновления
add_action('admin_notices', 'rpm_manual_update_button');

function rpm_manual_update_button() {
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-success"><p><a href="?rpm_update_plugins=true" class="button">Обновить плагины с удаленных серверов</a></p></div>';
    }

    if (isset($_GET['rpm_update_plugins'])) {
        rpm_update_plugins();
        echo '<div class="notice notice-success"><p>Все плагины обновлены.</p></div>';
    }
}
