<?php
ob_start();
/*
Plugin Name: WooCommerce Custom Redirect
Description: Redirige a los clientes a una URL específica después de comprar productos específicos, en este caso la pagina debe contener el shortcode del formulario.
Version: 1.2
Author: United Fixers
*/

// Evita el acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}
//se crea la tabla nueva
register_activation_hook(__FILE__, 'custom_redirects_table');
function custom_redirects_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_redirects_r2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        redirect_url text NOT NULL,
        items int NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Añadir menú de configuración en el panel de administración
add_action('admin_menu', 'wcr_add_admin_menu');
function wcr_add_admin_menu()
{
    add_submenu_page(
        'options-general.php',
        'Custom Redirects',
        'Custom Redirects',
        'manage_options',
        'woocommerce-custom-redirect',
        'wcr_admin_page'
    );
}
function get_custom_redirects($product_id = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_redirects_r2';
    if ($product_id != null) {
        $redirect_data = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id,items,redirect_url FROM $table_name where product_id = %d",
            $product_id
        ));
    } else {
        $redirect_data = $wpdb->get_results("SELECT product_id,items,redirect_url FROM $table_name");
    }


    return $redirect_data;
}
// Página de configuración del plugin
function wcr_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Procesar eliminación de redirección
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['product_id'])) {
        check_admin_referer('wcr_delete_redirect');
        $product_id = sanitize_text_field($_GET['product_id']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'custom_redirects_r2';
        $redirect_data = $wpdb->get_results($wpdb->prepare(
            "DELETE FROM $table_name WHERE product_id = %d",
            $product_id
        ));

        wp_redirect(remove_query_arg(array('action', 'product_id', '_wpnonce')));
        echo '<div class="notice notice-success"><p>Redirección eliminada.</p></div>';
    }

    // Guardar configuración
    if (isset($_POST['wcr_save_settings'])) {
        check_admin_referer('wcr_save_settings');
        $redirect_data = [];

        if (!empty($_POST['product_ids']) && !empty($_POST['redirect_url']) && !empty($_POST['n_items'])) {
            $product_ids = intval(sanitize_text_field($_POST['product_ids']));
            $redirect_url = sanitize_text_field($_POST['redirect_url']);
            $items = sanitize_text_field($_POST['n_items']);

            if (!empty($product_ids)) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'custom_redirects_r2';
                $wpdb->insert(
                    $table_name,
                    array(
                        'product_id' => $product_ids,
                        'redirect_url' => $redirect_url,
                        'items' => $items
                    ),
                    array('%d', '%s', '%d')
                );
            }

            echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Por favor, completa todos los campos.</p></div>';
        }
    }

    // Obtener datos guardados
    $redirect_data = get_custom_redirects();

    var_dump(gettype($redirect_data));
    // Formulario de configuración
?>
    <div class="wrap">
        <h1>WooCommerce Custom Redirect</h1>
        <p>Redirige a los clientes a una URL específica después de comprar productos específicos, en este caso la pagina
            debe contener el shortcode [threefold_formulario] del formulario.</p>
        <form method="post" action="">
            <?php wp_nonce_field('wcr_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="product_ids">ID del Producto</label></th>
                    <td>
                        <input type="number" min="1" name="product_ids" id="product_ids" required>
                        <p class="description">Ingresa el ID del producto.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="n_items">Cantidad de items</label></th>
                    <td>
                        <input type="number" min="1" name="n_items" id="n_items" required>
                        <p class="description">Ingresa el numero de items.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="redirect_url">URL de Redirección</label></th>
                    <td>
                        <input type="text" name="redirect_url" id="redirect_url" required title="Formato invalido"
                            pattern="^(?!.*(http:\/\/|https:\/\/|www\.|\.com|\.org|\.net|\.io|\.co)).*$">
                        <p class="description">Ingrese el identificador (slug) de la página interna a la cual desea
                            redirigir.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Guardar Configuración', 'primary', 'wcr_save_settings'); ?>
        </form>

        <h2>Redirecciones Configuradas</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID del Producto</th>
                    <th>URL de Redirección</th>
                    <th>Cantidad de items</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($redirect_data)) : ?>
                    <?php foreach ($redirect_data as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item->product_id); ?></td>
                            <td><a target="__blank"
                                    href="<?php echo esc_url(site_url($item->redirect_url)); ?>"><?php echo esc_url(site_url($item->redirect_url)); ?></a>
                            </td>
                            <td>
                                <?php echo esc_html($item->items); ?>
                            </td>
                            <td>
                                <a href="<?php echo wp_nonce_url(
                                                add_query_arg(array(
                                                    'action' => 'delete',
                                                    'product_id' => $item->product_id
                                                )),
                                                'wcr_delete_redirect'
                                            ); ?>" class="button button-secondary">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="3">No hay redirecciones configuradas.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
}

// Redirigir después de una compra exitosa
add_action('template_redirect', 'wcr_redirect_after_purchase');
function wcr_redirect_after_purchase()
{
    if (is_wc_endpoint_url('order-received')) {
        $order_id = absint(get_query_var('order-received'));
        $order = wc_get_order($order_id);
        $items = 0;
        if ($order) {


            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $redirect_data = get_custom_redirects($product_id);
                error_log(print_r($redirect_data, true));
                foreach ($redirect_data as $data) {
                    // Verifica si los elementos existen en el array antes de asignarlos
                    $items = isset($data->items) ? $data->items : null;
                    $product_id = isset($data->product_id) ? $data->product_id : null;
                    $url = isset($data->redirect_url) ? $data->redirect_url : 'formulario';

                    if (isset($product_id)) {
                        $code = $items . "OC" . $order_id;
                        setcookie('threefold_id', $code, time() + 3600, "/");
                        ob_end_clean();
                        wp_redirect(site_url($url));
                        exit;
                    }
                }
            }
        }
    }
}
if (!headers_sent()) {
    ob_end_flush();
}
