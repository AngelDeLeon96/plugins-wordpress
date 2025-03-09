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
    global $wpdb;
    $table_name = $wpdb->prefix . 'threefold_uploads_r2';
    $uuid = isset($_GET['uuid']) ? sanitize_text_field($_GET['uuid']) : '';
    $order_id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

    if (empty($uuid) || empty($order_id)) {
        return '<p>No se ha proporcionado un UUID o ID válido.</p>';
    }

    // Buscar el registro en la base de datos
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT public_url, type, name FROM $table_name WHERE order_id = %d AND uuid = %s",
        $order_id,
        $uuid
    ));

    if (!$result) {
        return '<p>No se encontraron datos.</p>';
    }

    // Buscar el video por UUID
    $video_url = $result->public_url;
    $tipo = $result->type;
    // Validar el tipo de archivo
    $nombre = $result->name;

    if (empty($video_url)) {
        return '<p>No se encontró el video.</p>';
    }

    $allowed_types = ['video/mp4', 'video/avi', 'video/mpeg', 'video/quicktime'];
    $allowed_image_types = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (in_array($tipo, $allowed_types)) {
        $allUploaded = '<h2> Archivo: ' . explode("/", $nombre)[1] . '</h2> 
        <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%;">
        <video controls style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
            <source src="' . esc_url($video_url) . '">
            Tu navegador no soporta la etiqueta de video.
        </video>
    </div>';
    } elseif (in_array($tipo, $allowed_image_types)) {
        $allUploaded = '<h2>Archivo: ' . explode("/", $nombre)[1] . '</h2>
        <div style="max-width: 100%;">
        <img src="' . esc_url($video_url) . '" alt="' . esc_attr($nombre) . '" style="width: 100%; height: auto;">
        </div>';
    } else {
        $allUploaded = '<p>El archivo debe ser un video o una imagen.</p>';
    }

    // Mostrar el video
    return $allUploaded;
}

// Registrar el shortcode
add_shortcode('video_viewer', 'video_viewer_shortcode');
