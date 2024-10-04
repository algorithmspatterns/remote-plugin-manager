<?php

// Загрузка masterlist.json и добавление ссылок в базу данных
function rpm_load_masterlist($file = 'collections/masterlist.json') {
    $file_path = plugin_dir_path(__DIR__) . $file;
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);
        if (isset($data['plugins']) && is_array($data['plugins'])) {
            $urls = array_column($data['plugins'], 'url');
            update_option('rpm_plugin_urls', $urls);
        }
    }
}

// Функция для отображения доступных коллекций и управления ими
function rpm_display_collection_selector() {
    $dir = plugin_dir_path(__DIR__) . 'collections/';
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
            $data = json_decode(file_get_contents($dir . $file), true);
            $collection_name = $data['collection_name'] ?? basename($file, '.json');
            echo "<label><input type='radio' name='collection' value='{$file}'> {$collection_name}</label><br>";
        }
    }
    echo "<br><button type='submit' name='action' value='add'>" . __('Add Collection URLs', 'remote-plugin-manager') . "</button>";
    echo "<button type='submit' name='action' value='replace'>" . __('Replace with Collection URLs', 'remote-plugin-manager') . "</button>";
}

// Добавление ссылок из выбранной коллекции
function rpm_add_collection_urls($file) {
    $file_path = plugin_dir_path(__DIR__) . $file;
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);

        // Проверка на успешное декодирование JSON
        if (isset($data['plugins']) && is_array($data['plugins'])) {
            $urls = get_option('rpm_plugin_urls', array());
            $new_urls = array_column($data['plugins'], 'url');
            $merged_urls = array_merge($urls, $new_urls);
            update_option('rpm_plugin_urls', $merged_urls);
        } else {
            error_log("Error: Failed to decode JSON or 'plugins' key missing in file {$file_path}");
        }
    } else {
        error_log("Error: File {$file_path} not found.");
    }
}

// Замена текущих ссылок на ссылки из выбранной коллекции
function rpm_replace_collection_urls($file) {
    $file_path = plugin_dir_path(__DIR__) . $file;
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);

        // Проверка на успешное декодирование JSON
        if (isset($data['plugins']) && is_array($data['plugins'])) {
            $new_urls = array_column($data['plugins'], 'url');
            update_option('rpm_plugin_urls', $new_urls);
        } else {
            error_log("Error: Failed to decode JSON or 'plugins' key missing in file {$file_path}");
        }
    } else {
        error_log("Error: File {$file_path} not found.");
    }
}

// Скачивание коллекции по URL и сохранение в папке collections
function rpm_download_collection($url) {
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $filename = basename($url);
    $file_path = plugin_dir_path(__DIR__) . 'collections/' . $filename;

    file_put_contents($file_path, $body);
    return true;
}
