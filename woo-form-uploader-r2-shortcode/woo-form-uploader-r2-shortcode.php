<?php

/**
 * Plugin Name: Formulario de Subida de Archivos a R2
 * Description: Shortcode para insertar un formulario de subida de archivos a Cloudflare R2 vinculado a pedidos, debe estar instalado AWS SDK y configurado el plugin R2 Integration, utilizar el shortcode [threefold_formulario] colocarlo en una pagina.
 * Version: 1.2
 * Author: United Fixers
 */
require_once __DIR__ . '/uploader-to-r2.php';
// Evitar acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Crear tabla para almacenar los archivos subidos
register_activation_hook(__FILE__, 'threefold_create_uploads_table');

function threefold_create_uploads_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'threefold_uploads_r2';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        uuid varchar(36) NOT NULL,
        name varchar(255) NOT NULL,
        type varchar(255) NULL,
        size bigint(20) NULL,
        public_url text NOT NULL,
        order_id bigint(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY order_id (order_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Función que genera el formulario de subida de archivos
 */
function threefold_formulario_shortcode()
{
    // Buffer de salida para capturar el HTML
    ob_start();

    // Verificar que el usuario esté logueado
    if (!is_user_logged_in()) {
        return '<p>Debes <a href="' . esc_url(wp_login_url()) . '">iniciar sesión</a> para acceder a este formulario.</p>';
    }

    // Verificar que la cookie necesaria exista y sanitizarla
    if (!isset($_COOKIE['threefold_id'])) {
        return '<h3 style="color: #641e16;">No se ha encontrado ningún pedido pendiente que requiera la subida de archivos. Si considera que esto es un error, por favor, contacte con los administradores.</h3>';
    }

    $data = sanitize_text_field($_COOKIE['threefold_id']);
    $separated = explode("OC", $data);
    $order_id = isset($separated[1]) ? intval($separated[1]) : 0;
    $max_fotos = isset($separated[0]) ? intval($separated[0]) : 0;

    if ($order_id <= 0 || $max_fotos <= 0) {
        return '<p>Datos inválidos.</p>';
    }
?>
    <div class="container mx-auto p-4">
        <h2 class="text">
            Sube hasta <?php echo esc_html($max_fotos); ?> archivos para tu pedido #<?php echo esc_html($order_id); ?>
        </h2>
        <span>*Debe subir al menos un archivo.</span>
        <br>
        <span>**Asegúrese de tener listos todos los archivos que desea subir antes de comenzar.</span>
        <br>
        <span>***Si no sube todos los archivos permitidos en el pack, deberá contactar al administrador, ya que no podrá
            hacerlo posteriormente.
        </span>
        </br>
        <form action="<?php echo esc_url(add_query_arg('threefold_process', '1', site_url())); ?>" method="post"
            enctype="multipart/form-data">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
            <input type="hidden" name="max_fotos" value="<?php echo esc_attr($max_fotos); ?>">
            <?php wp_nonce_field('upload_photos', 'photo_upload_nonce'); ?>
            <?php for ($i = 1; $i <= $max_fotos; $i++): ?>
                <div class="mb-3">
                    <label for="formFile<?php echo $i; ?>" class="form-label">Archivo: <?php echo $i; ?>:</label>
                    <input placeholder="Escoger archivo" <?php echo ($i == 1) ? 'required' : ''; ?> class="form-control"
                        type="file" id="formFile<?php echo $i; ?>" name="archivos[]" accept="image/png, image/jpeg, video/*">
                </div>
            <?php endfor; ?>
            <br>
            <button class="btn btn-primary" type="submit">Subir archivos</button>
        </form>
    </div>
<?php
    // Devolver el contenido capturado
    return ob_get_clean();
}

// Registrar el shortcode
add_shortcode('threefold_formulario', 'threefold_formulario_shortcode');

/**
 * Función para subir un archivo a R2
 */
function threefold_subir_archivo($s3Client, $bucket, $archivoTmp, $nombre, $tipo)
{
    try {
        if (!file_exists($archivoTmp) || empty($archivoTmp)) {
            throw new Exception("El archivo temporal no existe o está vacío: {$archivoTmp}");
        }

        $tamanioArchivo = filesize($archivoTmp);
        // Si el archivo es menor a 100MB, usar una subida normal
        if ($tamanioArchivo < 100 * 1024 * 1024) {
            $result = $s3Client->putObject([
                'Bucket'      => $bucket,
                'Key'         => $nombre,
                'Body'        => fopen($archivoTmp, 'rb'),
                'ContentType' => $tipo
            ]);
            return $result;
        } else {
            // Subida multipart para archivos grandes
            $uploader = new \Aws\S3\MultipartUploader($s3Client, fopen($archivoTmp, 'rb'), [
                'bucket' => $bucket,
                'key'    => $nombre,
                'part_size' => 10 * 1024 * 1024, // 10MB por parte
            ]);

            $result = $uploader->upload();
            return $result;
        }
    } catch (Exception $e) {
        error_log("Error al subir " . $nombre . ": " . $e->getMessage());
        return false;
    }
}

/**
 * Función para subir archivos a Cloudflare R2
 */


/**
 * Manejar el procesamiento de la subida de archivos
 */
function threefold_procesar_subida()
{
    // Verificar si se está procesando el formulario
    if (!isset($_GET['threefold_process']) || $_GET['threefold_process'] !== '1') {
        return;
    }

    // Verificar que sea una solicitud POST con archivos
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivos'])) {
        wp_redirect(home_url());
        exit;
    }

    // Verificar nonce para seguridad
    if (!isset($_POST['photo_upload_nonce']) || !wp_verify_nonce($_POST['photo_upload_nonce'], 'upload_photos')) {
        wp_die('Error de seguridad. Por favor, inténtalo de nuevo.');
    }

    // Verificar que el usuario esté logueado
    if (!is_user_logged_in()) {
        wp_redirect(wp_login_url());
        exit;
    }

    // Verificar datos del formulario
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if ($order_id <= 0) {
        wp_die('ID de pedido inválido.');
    }

    // Iniciar procesamiento
    try {
        // Subir archivos a R2
        $success = threefold_upload_to_cloudflare_r2($_FILES, $order_id);

        // Eliminar la cookie después de procesar
        setcookie('threefold_id', '', time() - 3600, '/');

        // Redireccionar según el resultado
        if ($success) {
            // Redireccionar a la cuenta con mensaje de éxito
            wp_redirect(add_query_arg('upload_status', 'success', site_url('/my-account')));
        } else {
            // Redireccionar con mensaje de error
            wp_redirect(add_query_arg('upload_status', 'error', site_url('/my-account')));
        }
        exit;
    } catch (Exception $e) {
        error_log('Error al procesar subida: ' . $e->getMessage());
        wp_die('Error al procesar la subida de archivos: ' . esc_html($e->getMessage()));
    }
}
add_action('template_redirect', 'threefold_procesar_subida', 5);

/**
 * Mostrar mensajes de estado después de la subida
 */
function threefold_mostrar_mensaje()
{
    if (isset($_GET['upload_status'])) {
        if ($_GET['upload_status'] === 'success') {
            wc_add_notice('Archivos subidas correctamente para tu pedido!', 'success');
        } elseif ($_GET['upload_status'] === 'error') {
            wc_add_notice('Hubo un problema al subir algunas archivos. Por favor, inténtalo de nuevo.', 'error');
        }
    }
}
add_action('woocommerce_account_dashboard', 'threefold_mostrar_mensaje', 5);


/**
 * Agregar script para verificar tipos de archivo antes de subir
 */
function threefold_enqueue_scripts()
{
    if (has_shortcode(get_post()->post_content, 'threefold_formulario')) {
        // Crear un script en línea para validación de archivos
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $("form").on("submit", function(e) {
                    var validFiles = true;
                    var allowedTypes = ["image/jpeg", "image/png", "video/mp4", "video/avi", "video/mpeg", "video/quicktime"];
                    
                    $("input[type=file]").each(function() {
                        if (this.files.length > 0) {
                            var file = this.files[0];
                            if (allowedTypes.indexOf(file.type) === -1) {
                                validFiles = false;
                                alert("Tipo de archivo no permitido: " + file.name + "\nPor favor, sube imágenes (JPG, PNG) o videos (MP4, AVI).");
                                return false;
                            }
                        }
                    });
                    
                    if (!validFiles) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
        ');
    }
}
add_action('wp_enqueue_scripts', 'threefold_enqueue_scripts');

/**
 * Función auxiliar para mostrar archivos subidos en la administración de pedidos
 * (Opcional - solo si quieres mostrar los archivos en el panel de administración)
 */
function threefold_mostrar_archivos_admin($order)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'threefold_uploads_r2';

    $files = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at DESC",
        $order->get_id()
    ));

    if (!empty($files) && is_array($files)) {
        echo '<h3>Archivos subidos por el cliente</h3>';
        echo '<ul>';
        foreach ($files as $file) {
            echo '<li>';
            echo '<a href="' . esc_url($file->public_url) . '" target="_blank">' .
                esc_html(basename($file->name)) . '</a>';
            echo ' - Subido: ' . esc_html($file->created_at);
            echo '</li>';
        }
        echo '</ul>';
    }
}
add_action('woocommerce_admin_order_data_after_billing_address', 'threefold_mostrar_archivos_admin', 10, 1);
