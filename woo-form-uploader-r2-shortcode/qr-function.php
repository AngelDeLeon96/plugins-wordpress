<?php
require_once __DIR__ . '/phpqrcode/phpqrcode.php';

function generate_qr_send_email($url, $email, $name, $size = 10, $margin = 1)
{
    // Generar el código QR en un buffer
    ob_start();
    QRcode::png($url, null, QR_ECLEVEL_H, $size, $margin);
    $qr_image = ob_get_clean();
    $logo_path = plugin_dir_path(__FILE__) . 'logo.png';

    // Verificar si el archivo del logo existe
    if (!file_exists($logo_path)) {
        return 'Error: No se encontró el archivo del logo.';
    }
    // Crear una imagen a partir del código QR generado
    $qr = imagecreatefromstring($qr_image);
    $logo = imagecreatefrompng($logo_path);

    // Tamaños
    $qr_width = imagesx($qr);
    $qr_height = imagesy($qr);
    $logo_width = imagesx($logo);
    $logo_height = imagesy($logo);

    // Calcular la posición del logo
    $logo_qr_width = $qr_width / 5;
    $logo_qr_height = $qr_height / 5;
    $logo_x = ($qr_width - $logo_qr_width) / 2;
    $logo_y = ($qr_height - $logo_qr_height) / 2;

    // Redimensionar el logo
    $logo_resized = imagecreatetruecolor($logo_qr_width, $logo_qr_height);
    imagecopyresampled($logo_resized, $logo, 0, 0, 0, 0, $logo_qr_width, $logo_qr_height, $logo_width, $logo_height);

    // Superponer el logo en el código QR
    imagecopymerge($qr, $logo_resized, $logo_x, $logo_y, 0, 0, $logo_qr_width, $logo_qr_height, 100);

    // Guardar la imagen final
    $output_file = plugin_dir_path(__FILE__) . $name . '.png';
    imagepng($qr, $output_file);

    // Liberar memoria
    imagedestroy($qr);
    imagedestroy($logo);
    imagedestroy($logo_resized);

    // Enviar el correo electrónico con el QR adjunto
    $to = $email;
    $subject = 'Tu Código QR Personalizado';
    $message = 'Aquí tienes tu código QR personalizado.';
    $headers = array('Content-Type: text/html; charset=UTF-8');

    $attachments = array($output_file);

    $mail_sent = wp_mail($to, $subject, $message, $headers, $attachments);

    if ($mail_sent) {
        error_log('Correo enviado a ' . $email);
    } else {
        error_log('Hubo un error al enviar el correo.');
    }
}
