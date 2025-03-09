<?php
require_once __DIR__ . '/qr-function.php';

function threefold_upload_to_cloudflare_r2($files, $order_id)
{
    // Verificar que el SDK de AWS esté disponible
    if (!class_exists('Aws\S3\S3Client')) {
        // Intentar cargar el autoloader desde varias ubicaciones comunes
        $posibles_rutas = [
            plugin_dir_path(__FILE__) . 'aws/aws-autoloader.php',
            WP_PLUGIN_DIR . '/aws-sdk-php/aws-autoloader.php',
            ABSPATH . 'aws/aws-autoloader.php',
            __DIR__ . '/../aws/aws-autoloader.php'
            // Agrega más rutas según sea necesario
        ];

        $cargado = false;
        foreach ($posibles_rutas as $ruta) {
            if (file_exists($ruta)) {
                require_once $ruta;
                $cargado = true;
                break;
            }
        }

        if (!$cargado) {
            error_log('Error: No se pudo cargar el AWS SDK. Por favor, instala el SDK de AWS.');
            return false;
        }
    }

    // Configuración de credenciales de Cloudflare R2
    $options = get_option('wc_r2_files_access_settings', [
        'endpoint' => '',
        'access_key' => '',
        'secret_key' => '',
        'bucket_name' => '',
        'public_domain' => '',
    ]);

    // Verificar que existan todas las opciones necesarias
    if (
        empty($options['endpoint']) || empty($options['access_key']) ||
        empty($options['secret_key']) || empty($options['bucket_name'])
    ) {
        error_log('Error: Configuración de R2 incompleta. Por favor, configura el plugin WooCommerce R2 Files Access.');
        return false;
    }
    //config del s3client de R2
    $s3Client = new Aws\S3\S3Client([
        'endpoint' => $options['endpoint'],
        'version' => 'latest',
        'region'  => 'auto',
        'credentials' => [
            'key'    => $options['access_key'],
            'secret' => $options['secret_key'],
        ],
    ]);

    $bucket = $options['bucket_name'];
    $allUploaded = true;
    $uploaded_files = [];

    foreach ($files['archivos']['name'] as $key => $nombre) {
        $archivo_temporal = $files['archivos']['tmp_name'][$key];
        $tipo = $files['archivos']['type'][$key];
        $size = $files['archivos']['size'][$key];

        // Verificar si el archivo fue subido
        if (empty($nombre) || empty($archivo_temporal)) {
            continue; // Saltar archivos vacíos
        }

        // Validar el tipo de archivo
        $allowed_types = ['image/jpeg', 'image/png', 'video/mp4', 'video/avi', 'video/mpeg', 'video/quicktime'];
        if (!in_array($tipo, $allowed_types)) {
            $allUploaded = false;
            continue;
        }

        try {
            // Generar una key única para el archivo
            $name = sanitize_file_name($nombre);
            $uuid = wp_generate_uuid4();
            $key = 'order_' . intval($order_id) . '/' . $name;
            $res = threefold_subir_archivo($s3Client, $bucket, $archivo_temporal, $key, $tipo);
            $url = site_url('visor') . '?uuid=' . $uuid . "&id=" . $order_id;
            generate_qr_send_email($url, wp_get_current_user()->user_email, $uuid);

            if ($res) {
                $uploaded_files[] = [
                    'order_id' => $order_id,
                    'uuid' => $uuid,
                    'name' => $key,
                    'type' => $tipo,
                    'size' => $size,
                    'public_url' =>  $options['public_domain'] . $key
                ];
            } else {
                $allUploaded = false;
            }
        } catch (Exception $e) {
            error_log("Error al subir " . $nombre . ": " . $e->getMessage());
            $allUploaded = false;
        }
    }

    // Opcionalmente, guardar información sobre los archivos subidos en metadata del pedido
    if (!empty($uploaded_files)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'threefold_uploads_r2';

        foreach ($uploaded_files as $file) {
            $wpdb->insert(
                $table_name,
                array(
                    'uuid' => $file['uuid'],
                    'name' => $file['name'],
                    'public_url' => $file['public_url'],
                    'order_id' => $order_id,
                    'type' => $file['type'],
                    'size' => $file['size']
                ),
                array('%s', '%s', '%s', '%d')
            );
        }
    }

    return $allUploaded;
}
