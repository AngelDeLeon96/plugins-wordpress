<?php

/**
 * Plugin Name: WooCommerce R2 Integration
 * Description: Permite a los clientes acceder a sus archivos subidos y almacenados en Cloudflare R2, require AWS S3 SDK y se debe completar la configuracion 
 * Version: 1.1
 * Author: United Fixers
 */

// Si este archivo es llamado directamente, abortar
if (!defined('WPINC')) {
    die;
}

// Verificar si el autoloader de Composer existe
require_once __DIR__ . '../../../aws/aws-autoloader.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class WooCommerce_R2_Files_Access
{

    private $s3_client;
    private $bucket_name;
    private $options;
    private $option_name = 'wc_r2_files_access_settings';

    public function __construct()
    {
        // Cargar opciones
        $this->options = get_option($this->option_name, [
            'endpoint' => '',
            'access_key' => '',
            'secret_key' => '',
            'bucket_name' => '',
            'public_domain' => '',
        ]);

        // Agregar un nuevo endpoint en "My Account"
        add_action('init', array($this, 'register_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_files_menu_item'));
        add_action('woocommerce_account_mis-archivos_endpoint', array($this, 'my_files_content'));

        // Agregar menú de administración
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Inicializar el cliente R2 si las credenciales están configuradas
        if (!empty($this->options['access_key']) && !empty($this->options['secret_key']) && !empty($this->options['endpoint'])) {
            $this->init_r2_client();
            $this->bucket_name = $this->options['bucket_name'];
        }
    }

    /**
     * Agrega página al menú de administración
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'options-general.php',
            'Configuración de conexion con R2',
            'R2 Config',
            'manage_options',
            'wc-r2-files-settings',
            array($this, 'admin_page')
        );
    }

    /**
     * Registra las opciones de configuración
     */
    public function register_settings()
    {
        register_setting(
            'wc_r2_files_settings',
            $this->option_name,
            array($this, 'sanitize_settings')
        );

        add_settings_section(
            'wc_r2_files_section',
            'Configuración de Cloudflare R2',
            array($this, 'settings_section_callback'),
            'wc-r2-files-settings'
        );

        add_settings_field(
            'endpoint',
            'Endpoint R2',
            array($this, 'endpoint_callback'),
            'wc-r2-files-settings',
            'wc_r2_files_section'
        );

        add_settings_field(
            'access_key',
            'Access Key ID',
            array($this, 'access_key_callback'),
            'wc-r2-files-settings',
            'wc_r2_files_section'
        );

        add_settings_field(
            'secret_key',
            'Secret Access Key',
            array($this, 'secret_key_callback'),
            'wc-r2-files-settings',
            'wc_r2_files_section'
        );

        add_settings_field(
            'bucket_name',
            'Nombre del Bucket',
            array($this, 'bucket_name_callback'),
            'wc-r2-files-settings',
            'wc_r2_files_section'
        );

        add_settings_field(
            'public_domain',
            'Dominio',
            array($this, 'public_domain_callback'),
            'wc-r2-files-settings',
            'wc_r2_files_section'
        );

        add_settings_field(
            'test_connection',
            'Probar Conexión',
            array($this, 'test_connection_callback'),
            'wc-r2-files-settings',
            'wc_r2_files_section'
        );
    }

    /**
     * Sanitiza las opciones antes de guardarlas
     */
    public function sanitize_settings($input)
    {
        $new_input = array();

        if (isset($input['endpoint']))
            $new_input['endpoint'] = sanitize_text_field($input['endpoint']);

        if (isset($input['access_key']))
            $new_input['access_key'] = sanitize_text_field($input['access_key']);

        if (isset($input['secret_key']))
            $new_input['secret_key'] = sanitize_text_field($input['secret_key']);

        if (isset($input['bucket_name']))
            $new_input['bucket_name'] = sanitize_text_field($input['bucket_name']);

        if (isset($input['public_domain']))
            $new_input['public_domain'] = sanitize_text_field($input['public_domain']);

        return $new_input;
    }

    /**
     * Callback para la sección de configuración
     */
    public function settings_section_callback()
    {
        echo '<p>Configura la conexión a Cloudflare R2 para permitir que los clientes accedan a sus archivos.</p>';
    }

    /**
     * Callback para el campo endpoint
     */
    public function endpoint_callback()
    {
        $endpoint = isset($this->options['endpoint']) ? esc_attr($this->options['endpoint']) : '';
        echo '<input type="text" id="endpoint" name="' . $this->option_name . '[endpoint]" value="' . $endpoint . '" class="regular-text" placeholder="https://ACCOUNT_ID.r2.cloudflarestorage.com" />';
        echo '<p class="description">El endpoint de tu bucket R2. Formato: https://ACCOUNT_ID.r2.cloudflarestorage.com</p>';
    }

    /**
     * Callback para el campo access_key
     */
    public function access_key_callback()
    {
        $access_key = isset($this->options['access_key']) ? esc_attr($this->options['access_key']) : '';
        echo '<input type="text" id="access_key" name="' . $this->option_name . '[access_key]" value="' . $access_key . '" class="regular-text" />';
        echo '<p class="description">Tu Access Key ID de Cloudflare R2</p>';
    }

    /**
     * Callback para el campo secret_key
     */
    public function secret_key_callback()
    {
        $secret_key = isset($this->options['secret_key']) ? esc_attr($this->options['secret_key']) : '';
        echo '<input type="password" id="secret_key" name="' . $this->option_name . '[secret_key]" value="' . $secret_key . '" class="regular-text" />';
        echo '<p class="description">Tu Secret Access Key de Cloudflare R2</p>';
    }

    /**
     * Callback para el campo bucket_name
     */
    public function bucket_name_callback()
    {
        $bucket_name = isset($this->options['bucket_name']) ? esc_attr($this->options['bucket_name']) : '';
        echo '<input type="text" id="bucket_name" name="' . $this->option_name . '[bucket_name]" value="' . $bucket_name . '" class="regular-text" />';
        echo '<p class="description">El nombre de tu bucket en R2</p>';
    }

    /**
     * Callback para el campo dominio
     */
    public function public_domain_callback()
    {
        $public_domain = isset($this->options['public_domain']) ? esc_attr($this->options['public_domain']) : '';
        echo '<input type="text" id="public_domain" name="' . $this->option_name . '[public_domain]" value="' . $public_domain . '" class="regular-text" />';
        echo '<p class="description">El dominio publico en R2, incluir protocolo.</p>';
    }

    /**
     * Callback para el botón de prueba de conexión
     */
    public function test_connection_callback()
    {
        echo '<button type="button" id="test_r2_connection" class="button button-secondary">Probar Conexión</button>';
        echo '<span id="test_connection_result" style="margin-left: 10px;"></span>';

        // Script para realizar la prueba de conexión por AJAX
?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#test_r2_connection').on('click', function() {
                    var data = {
                        'action': 'test_r2_connection',
                        'endpoint': $('#endpoint').val(),
                        'access_key': $('#access_key').val(),
                        'secret_key': $('#secret_key').val(),
                        'bucket_name': $('#bucket_name').val(),
                        'public_domain': $('#public_domain').val(),
                        'nonce': '<?php echo wp_create_nonce('test_r2_connection_nonce'); ?>'
                    };

                    $('#test_connection_result').html('<span style="color:#666;">Probando conexión...</span>');

                    $.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            $('#test_connection_result').html('<span style="color:green;">✓ ' + response
                                .data + '</span>');
                        } else {
                            $('#test_connection_result').html('<span style="color:red;">✗ ' + response
                                .data + '</span>');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Renderiza la página de administración
     */
    public function admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
    ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wc_r2_files_settings');
                do_settings_sections('wc-r2-files-settings');
                submit_button('Guardar Configuración');
                ?>
            </form>
        </div>
    <?php
    }

    /**
     * Inicializa la conexión con Cloudflare R2
     */
    private function init_r2_client()
    {
        try {
            $this->s3_client = new S3Client([
                'endpoint' => $this->options['endpoint'],
                'region' => 'auto',
                'version' => 'latest',
                'credentials' => [
                    'key' => $this->options['access_key'],
                    'secret' => $this->options['secret_key'],
                ],
                'use_path_style_endpoint' => true
            ]);
        } catch (Exception $e) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="error"><p>';
                echo 'Error al inicializar el cliente R2: ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }

    /**
     * Registra el nuevo endpoint en WooCommerce My Account
     */
    public function register_endpoints()
    {
        add_rewrite_endpoint('mis-archivos', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }

    /**
     * Agrega el ítem "Mis Archivos" al menú de My Account
     */
    public function add_my_files_menu_item($items)
    {
        $items['mis-archivos'] = 'Mis Archivos';
        return $items;
    }

    /**
     * Contenido de la página "Mis Archivos"
     */
    public function my_files_content()
    {
        // Verificar si el cliente R2 está configurado
        if (!$this->s3_client) {
            echo '<p>El acceso a los archivos no está configurado. Por favor, contacta al administrador del sitio.</p>';
            return;
        }

        $customer_id = get_current_user_id();

        if ($customer_id <= 0) {
            echo '<p>Debes estar conectado para ver tus archivos.</p>';
            return;
        }

        // Obtener todos los pedidos del cliente
        $customer_orders = wc_get_orders(array(
            'customer_id' => $customer_id,
        ));

        if (empty($customer_orders)) {
            echo '<p>No tienes pedidos con archivos adjuntos.</p>';
            return;
        }

        echo '<h2>Mis Archivos Subidos</h2>';

        foreach ($customer_orders as $order) {
            $order_id = $order->get_id();
            $order_number = $order->get_order_number();
            $order_date = $order->get_date_created()->date_i18n('d/m/Y');

            // Intentar obtener archivos para este pedido
            $files = $this->get_r2_files_for_order($order_id);

            if (!empty($files)) {
                echo '<div class="woocommerce-order-files">';
                echo '<h3>Pedido #' . esc_html($order_number) . ' - ' . esc_html($order_date) . '</h3>';
                echo '<table class="woocommerce-orders-table">';
                echo '<thead><tr>';
                echo '<th>Nombre del archivo</th>';
                echo '<th>Tamaño</th>';
                echo '<th>Última modificación</th>';
                echo '<th>Acciones</th>';
                echo '</tr></thead>';
                echo '<tbody>';

                foreach ($files as $file) {
                    $filename = basename($file['Key']);
                    $size = size_format($file['Size']);
                    $last_modified = date('d/m/Y H:i', strtotime($file['LastModified']));
                    $download_url = $this->generate_public_url($file['Key']);

                    echo '<tr>';
                    echo '<td>' . esc_html($filename) . '</td>';
                    echo '<td>' . esc_html($size) . '</td>';
                    echo '<td>' . esc_html($last_modified) . '</td>';
                    echo '<td><a href="' . esc_url($download_url) . '" class="button">Descargar</a></td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
                echo '</div>';
            }
        }

        // Script para estilos adicionales
    ?>
        <style>
            .woocommerce-order-files {
                margin-bottom: 2em;
            }

            .woocommerce-orders-table {
                width: 100%;
                border-collapse: collapse;
            }

            .woocommerce-orders-table th,
            .woocommerce-orders-table td {
                padding: 10px;
                border: 1px solid #ddd;
                text-align: left;
            }

            .woocommerce-orders-table th {
                background-color: #f8f8f8;
                font-weight: bold;
            }
        </style>
<?php
    }

    /**
     * Obtiene los archivos de R2 para un pedido específico
     */
    private function get_r2_files_for_order($order_id)
    {
        try {
            // Usar el ID del pedido como prefijo para listar los objetos
            $prefix = 'order_' . $order_id . '/';

            $objects = $this->s3_client->listObjectsV2([
                'Bucket' => $this->bucket_name,
                'Prefix' => $prefix
            ]);

            return isset($objects['Contents']) ? $objects['Contents'] : [];
        } catch (AwsException $e) {
            error_log('Error al listar archivos de R2: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Genera una URL firmada para descargar un archivo
     */
    private function generate_download_url($file_key)
    {
        try {
            // Crear URL prefirmada con tiempo de expiración
            $cmd = $this->s3_client->getCommand('GetObject', [
                'Bucket' => $this->bucket_name,
                'Key' => $file_key
            ]);

            // La URL será válida por 15 minutos
            $request = $this->s3_client->createPresignedRequest($cmd, '+15 minutes');

            return (string) $request->getUri();
        } catch (AwsException $e) {
            error_log('Error al generar URL de descarga: ' . $e->getMessage());
            return '#';
        }
    }

    private function generate_public_url($file_key)
    {
        try {
            $url = $this->options['public_domain'] . $file_key;

            return $url;
        } catch (AwsException $e) {
            error_log('Error al generar URL de descarga: ' . $e->getMessage());
            return '#';
        }
    }
}

// Registrar el endpoint AJAX para probar la conexión
add_action('wp_ajax_test_r2_connection', 'test_r2_connection_callback');

function test_r2_connection_callback()
{
    // Verificar nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_r2_connection_nonce')) {
        wp_send_json_error('Error de seguridad. Por favor, recarga la página e intenta de nuevo.');
    }

    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No tienes permisos para realizar esta acción.');
    }

    // Obtener datos de la solicitud
    $endpoint = isset($_POST['endpoint']) ? sanitize_text_field($_POST['endpoint']) : '';
    $access_key = isset($_POST['access_key']) ? sanitize_text_field($_POST['access_key']) : '';
    $secret_key = isset($_POST['secret_key']) ? sanitize_text_field($_POST['secret_key']) : '';
    $bucket_name = isset($_POST['bucket_name']) ? sanitize_text_field($_POST['bucket_name']) : '';
    $public_domain = isset($_POST['public_domain']) ? sanitize_text_field($_POST['public_domain']) : '';

    // Validar datos
    if (empty($endpoint) || empty($access_key) || empty($secret_key) || empty($bucket_name) || empty($public_domain)) {
        wp_send_json_error('Todos los campos son obligatorios.');
    }

    try {
        // Cargar el SDK de AWS
        if (!class_exists('Aws\S3\S3Client')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }

        // Inicializar cliente R2
        $s3_client = new Aws\S3\S3Client([
            'endpoint' => $endpoint,
            'region' => 'auto',
            'version' => 'latest',
            'credentials' => [
                'key' => $access_key,
                'secret' => $secret_key,
            ],
            'use_path_style_endpoint' => true
        ]);

        // Probar listando objetos
        $result = $s3_client->listObjectsV2([
            'Bucket' => $bucket_name,
            'MaxKeys' => 1
        ]);

        wp_send_json_success('Conexión exitosa con Cloudflare R2.');
    } catch (Exception $e) {
        wp_send_json_error('Error de conexión: ' . $e->getMessage());
    }
}

// Hook para crear punto de acceso para descargar archivos
add_action('wp', 'handle_r2_file_download');

function handle_r2_file_download()
{
    if (isset($_GET['r2_download']) && isset($_GET['order_id']) && isset($_GET['file'])) {
        // Validar que el usuario tenga permiso para descargar este archivo
        $order_id = intval($_GET['order_id']);
        $file = sanitize_text_field($_GET['file']);

        // Verificar si el usuario puede acceder a este pedido
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() != get_current_user_id()) {
            wp_die('No tienes permiso para acceder a este archivo.', 'Error de Acceso', array('response' => 403));
        }

        // Aquí iría la lógica para obtener el archivo de R2 y servirlo al usuario
        // En lugar de redireccionar a URL firmada, podrías transmitir el archivo directamente
    }
}

// Inicializar el plugin
$woocommerce_r2_files = new WooCommerce_R2_Files_Access();
