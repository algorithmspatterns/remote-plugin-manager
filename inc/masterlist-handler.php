<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Защита от прямого доступа
}

/**
 * Загрузка masterlist.json и добавление ссылок в базу данных.
 * Вызывается при активации плагина.
 *
 * @param string $file Путь к файлу masterlist.json относительно корня плагина.
 */
function rpm_load_masterlist($file = 'collections/masterlist.json') {
    $file_path = plugin_dir_path(__DIR__) . $file;
    if (file_exists($file_path)) {
        $json_content = file_get_contents($file_path);
        $data = json_decode($json_content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['plugins']) && is_array($data['plugins'])) {
            $urls = array_column($data['plugins'], 'url');
            update_option('rpm_plugin_urls', $urls);
        } else {
            error_log("Error: Invalid JSON in file {$file_path}. Error: " . json_last_error_msg());
        }
    } else {
        error_log("Error: File {$file_path} not found.");
    }
}

/**
 * Отображение доступных коллекций и кнопок для добавления/замены ссылок.
 */
function rpm_display_collection_selector() {
    $dir = plugin_dir_path(__DIR__) . 'collections/';
    if ( ! is_dir( $dir ) ) {
        echo '<p>' . __('Collections directory does not exist.', 'remote-plugin-manager') . '</p>';
        return;
    }
    $files = array_diff(scandir($dir), array('.', '..'));
    if (empty($files)) {
        echo '<p>' . __('No collections found. Please upload a collection.', 'remote-plugin-manager') . '</p>';
        return;
    }
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
            $file_path = $dir . $file;
            $json_content = file_get_contents($file_path);
            $data = json_decode($json_content, true);
            $collection_name = isset($data['collection_name']) ? esc_html($data['collection_name']) : esc_html(basename($file, '.json'));
            echo "<label><input type='radio' name='collection' value='" . esc_attr($file) . "'> {$collection_name}</label><br>";
        }
    }
    echo "<br><button type='submit' name='action' value='add' class='button button-primary'>" . __('Add Collection URLs', 'remote-plugin-manager') . "</button> ";
    echo "<button type='submit' name='action' value='replace' class='button button-secondary'>" . __('Replace with Collection URLs', 'remote-plugin-manager') . "</button>";
}

/**
 * Добавление ссылок из выбранной коллекции.
 *
 * @param string $file Путь к файлу коллекции относительно папки collections.
 */
function rpm_add_collection_urls($file) {
    $file_path = plugin_dir_path(__DIR__) . $file;
    if (file_exists($file_path)) {
        $json_content = file_get_contents($file_path);
        $data = json_decode($json_content, true);

        // Проверка на успешное декодирование JSON и наличие ключа 'plugins'
        if (json_last_error() === JSON_ERROR_NONE && isset($data['plugins']) && is_array($data['plugins'])) {
            $current_urls = get_option('rpm_plugin_urls', array());
            $new_urls = array_column($data['plugins'], 'url');

            // Объединение текущих и новых URL, избегая дубликатов
            $merged_urls = array_unique(array_merge($current_urls, $new_urls));
            update_option('rpm_plugin_urls', $merged_urls);
        } else {
            error_log("Error: Invalid JSON or 'plugins' key missing in file {$file_path}. Error: " . json_last_error_msg());
        }
    } else {
        error_log("Error: File {$file_path} not found.");
    }
}

/**
 * Замена текущих ссылок на ссылки из выбранной коллекции.
 *
 * @param string $file Путь к файлу коллекции относительно папки collections.
 */
function rpm_replace_collection_urls($file) {
    $file_path = plugin_dir_path(__DIR__) . $file;
    if (file_exists($file_path)) {
        $json_content = file_get_contents($file_path);
        $data = json_decode($json_content, true);

        // Проверка на успешное декодирование JSON и наличие ключа 'plugins'
        if (json_last_error() === JSON_ERROR_NONE && isset($data['plugins']) && is_array($data['plugins'])) {
            $new_urls = array_column($data['plugins'], 'url');
            update_option('rpm_plugin_urls', $new_urls);
        } else {
            error_log("Error: Invalid JSON or 'plugins' key missing in file {$file_path}. Error: " . json_last_error_msg());
        }
    } else {
        error_log("Error: File {$file_path} not found.");
    }
}

/**
 * Скачивание коллекции по URL и сохранение в папку collections.
 *
 * @param string $url URL на JSON-файл коллекции.
 * @return bool|WP_Error Возвращает true при успешной загрузке, WP_Error при ошибке.
 */
function rpm_download_collection($url) {
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        error_log("Error: Failed to download collection from {$url}. Error: " . $response->get_error_message());
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $parsed_url = parse_url($url);
    $filename = basename($parsed_url['path']);
    
    // Убедимся, что файл имеет расширение .json
    if (pathinfo($filename, PATHINFO_EXTENSION) !== 'json') {
        $filename .= '.json';
    }

    $file_path = plugin_dir_path(__DIR__) . 'collections/' . sanitize_file_name($filename);
    
    // Проверка наличия папки collections
    if ( ! is_dir( plugin_dir_path(__DIR__) . 'collections/' ) ) {
        wp_mkdir_p( plugin_dir_path(__DIR__) . 'collections/' );
    }

    // Сохранение файла
    $result = file_put_contents($file_path, $body);
    if ($result === false) {
        error_log("Error: Failed to save collection to {$file_path}.");
        return new WP_Error('save_failed', __('Failed to save the collection file.', 'remote-plugin-manager'));
    }

    return true;
}
