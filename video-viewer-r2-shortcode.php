<?php
/*
Plugin Name: R2 Video Viewer
Description: Un plugin para mostrar videos basados en un UUID, require el plugin WooCommerce R2 Integration & Formulario de Subida de Archivos a R2, utilizar el shortcode [video_viewer] en la pagina donde quieres mostrar la url.
Version: 1.0
Author: United Fixers
*/

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Shortcode para mostrar el video
function video_viewer_shortcode($atts)
{
    // Obtener el parámetro UUID de la URL
    $uuid = isset($_GET['uuid']) ? sanitize_text_field($_GET['uuid']) : '';
    $order_id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

    if (empty($uuid) || empty($order_id)) {
        return '<p>No se ha proporcionado un UUID o ID válido.</p>';
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_orders_meta';
    $meta_key = '_threefold_uploaded_files';

    // Buscar el registro en la base de datos
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT meta_value FROM $table_name WHERE order_id = %d AND meta_key = %s",
        $order_id,
        $meta_key
    ));


    if (!$result) {
        return '<p>No se encontraron datos.</p>';
    }

    // Deserializar los datos
    $meta_value = maybe_unserialize($result->meta_value);

    if (!is_array($meta_value)) {
        return '<p>Formato de datos no válido.</p>';
    }

    // Buscar el video por UUID
    $video_url = '';
    foreach ($meta_value as $item) {
        if (isset($item['uuid']) && $item['uuid'] === $uuid && isset($item['public_url'])) {
            $video_url = $item['public_url'];
            break;
        }
    }

    if (empty($video_url)) {
        return '<p>No se encontró el video.</p>';
    }

    // Mostrar el video
    return '
    <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%;">
        <video controls style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
            <source src="' . esc_url($video_url) . '">
            Tu navegador no soporta la etiqueta de video.
        </video>
    </div>';
}

// Registrar el shortcode
add_shortcode('video_viewer', 'video_viewer_shortcode');
