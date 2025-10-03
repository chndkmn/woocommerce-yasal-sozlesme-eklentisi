<?php
/**
 * Plugin Name: Yasal Sözleşme PDF Kayıt
 * Description: WooCommerce checkout'ta KVKK ve Mesafeli Satış sözleşmelerini onaylatır, PDF oluşturur, sipariş notuna ekler ve sözleşme kayıtlarını yönetim panelinde saklar.
 * Version: 1.6.1
 * Author: WebMind
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Dompdf yükleme
require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

/**
 * Admin menü ve alt menüler
 */
add_action( 'admin_menu', 'yspr_admin_menu' );
function yspr_admin_menu() {
    add_menu_page(
        'Yasal Sözleşmeler',
        'Sözleşme Ayarları',
        'manage_options',
        'yspr-settings',
        'yspr_settings_page',
        'dashicons-media-document'
    );
    add_submenu_page(
        'yspr-settings',
        'Sözleşme Kayıtları',
        'Sözleşme Kayıtları',
        'manage_options',
        'yspr-log',
        'yspr_log_page'
    );
}

/**
 * Ayar kayıtları
 */
add_action( 'admin_init', 'yspr_settings_init' );
function yspr_settings_init() {
    register_setting( 'yspr_settings_group', 'yspr_logo' );
    register_setting( 'yspr_settings_group', 'yspr_kvkk' );
    register_setting( 'yspr_settings_group', 'yspr_mesafeli' );
    register_setting( 'yspr_settings_group', 'yspr_pdf_log' );

    add_settings_section( 'yspr_main_section', '', null, 'yspr-settings' );
    add_settings_field( 'yspr_logo', 'PDF Logo', 'yspr_logo_render', 'yspr-settings', 'yspr_main_section' );
    add_settings_field( 'yspr_kvkk', 'KVKK Metni', 'yspr_kvkk_render', 'yspr-settings', 'yspr_main_section' );
    add_settings_field( 'yspr_mesafeli', 'Mesafeli Satış Sözleşmesi', 'yspr_mesafeli_render', 'yspr-settings', 'yspr_main_section' );
}

function yspr_logo_render() {
    $logo = esc_attr( get_option( 'yspr_logo', '' ) );
    printf(
        '<input type="text" id="yspr_logo" name="yspr_logo" value="%s" style="width:60%%;" />',
        $logo
    );
    echo '<button class="button" id="yspr_logo_upload">Medya Yükle</button>';
    echo "<script>
        jQuery(function($){
            $('#yspr_logo_upload').on('click', function(e){
                e.preventDefault();
                var frame = wp.media({ multiple: false });
                frame.on('select', function() {
                    var url = frame.state().get('selection').first().toJSON().url;
                    $('#yspr_logo').val(url);
                });
                frame.open();
            });
        });
    </script>";
}

function yspr_kvkk_render() {
    $content = get_option( 'yspr_kvkk', '' );
    wp_editor( $content, 'yspr_kvkk', [
        'textarea_name' => 'yspr_kvkk',
        'media_buttons' => true,
        'textarea_rows' => 10,
    ] );
}

function yspr_mesafeli_render() {
    $content = get_option( 'yspr_mesafeli', '' );
    wp_editor( $content, 'yspr_mesafeli', [
        'textarea_name' => 'yspr_mesafeli',
        'media_buttons' => true,
        'textarea_rows' => 10,
    ] );
}

/**
 * Ayarlar sayfası render
 */
function yspr_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>Sözleşme Ayarları</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields( 'yspr_settings_group' );
    do_settings_sections( 'yspr-settings' );
    submit_button();
    echo '</form>';
    echo '</div>';
}

/**
 * Kayıtlar sayfası
 */
function yspr_log_page() {
    $log = get_option( 'yspr_pdf_log', [] );
    echo '<div class="wrap"><h1>Sözleşme Kayıtları</h1>';
    if ( empty( $log ) ) {
        echo '<p>Henüz kayıtlı sözleşme bulunmuyor.</p>';
    } else {
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Tarih</th><th>Sipariş No</th><th>Müşteri</th><th>PDF</th>';
        echo '</tr></thead><tbody>';
        foreach ( array_reverse( $log ) as $entry ) {
            echo '<tr>';
            echo '<td>' . esc_html( $entry['date'] ) . '</td>';
            echo '<td><a href="' . esc_url( admin_url( 'post.php?post=' . intval( $entry['order_id'] ) . '&action=edit' ) ) . '">' . intval( $entry['order_id'] ) . '</a></td>';
            if ( intval( $entry['user_id'] ) > 0 ) {
                echo '<td><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . intval( $entry['user_id'] ) ) ) . '">' . esc_html( $entry['name'] ) . '</a></td>';
            } else {
                echo '<td>' . esc_html( $entry['name'] ) . '</td>';
            }
            echo '<td><a href="' . esc_url( $entry['file'] ) . '" target="_blank">Görüntüle</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

/**
 * Checkout onay kısmı
 */
add_action( 'woocommerce_review_order_before_submit', 'yspr_terms_display', 9 );
function yspr_terms_display() {
    $kvkk = wpautop( wp_kses_post( get_option( 'yspr_kvkk', '' ) ) );
    $mes  = wpautop( wp_kses_post( get_option( 'yspr_mesafeli', '' ) ) );
    echo '<div id="yspr_terms_checkboxes">';
    echo '<div style="border:1px solid #ccc;padding:10px;max-height:150px;overflow:auto;margin-bottom:5px;">';
    echo '<strong>KVKK</strong>' . $kvkk;
    echo '</div>';
    echo '<p><label><input type="checkbox" name="yspr_kvkk" required> KVKK metnini okudum ve kabul ediyorum.</label></p>';
    echo '<div style="border:1px solid #ccc;padding:10px;max-height:150px;overflow:auto;margin-bottom:5px;">';
    echo '<strong>Mesafeli Satış</strong>' . $mes;
    echo '</div>';
    echo '<p><label><input type="checkbox" name="yspr_mesafeli" required> Mesafeli Satış Sözleşmesini okudum ve kabul ediyorum.</label></p>';
    echo '</div>';
}
add_action( 'woocommerce_checkout_process', 'yspr_terms_validate' );
function yspr_terms_validate() {
    if ( empty( $_POST['yspr_kvkk'] ) ) {
        wc_add_notice( 'KVKK metnini kabul etmelisiniz.', 'error' );
    }
    if ( empty( $_POST['yspr_mesafeli'] ) ) {
        wc_add_notice( 'Mesafeli Satış Sözleşmesini kabul etmelisiniz.', 'error' );
    }
}

/**
 * Sipariş meta kaydı
 */
add_action( 'woocommerce_checkout_create_order', 'yspr_save_meta', 20, 2 );
function yspr_save_meta( $order, $data ) {
    $order->update_meta_data( '_yspr_kvkk', 'onaylandı' );
    $order->update_meta_data( '_yspr_mesafeli', 'onaylandı' );
}

/**
 * PDF oluşturma fonksiyonu
 */
function yspr_generate_pdf_for_order( $order_id ) {
    $upload = wp_upload_dir();
    $dir    = $upload['basedir'] . '/yspr-sozlesmeler';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $order = wc_get_order( $order_id );
    $name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $date  = date_i18n( 'Y-m-d H:i:s' );
    $ua    = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    $ip    = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $logo  = get_option( 'yspr_logo' ) ?: get_site_icon_url();
    $kvkk  = wpautop( wp_kses_post( get_option( 'yspr_kvkk', '' ) ) );
    $mes   = wpautop( wp_kses_post( get_option( 'yspr_mesafeli', '' ) ) );

    $html  = '<html><body style="font-family:DejaVu Sans;">';
    $html .= '<div style="text-align:center;margin-bottom:20px;">';
    if ( $logo ) {
        $html .= '<img src="' . esc_url( $logo ) . '" style="width:150px;"/>';
    }
    $html .= '<h2>Yasal Sözleşme Onayı</h2></div>';
    $html .= '<p><strong>Sipariş No:</strong> ' . $order_id . '</p>';
    $html .= '<p><strong>İsim:</strong> ' . esc_html( $name ) . '</p>';
    $html .= '<p><strong>Tarih:</strong> ' . esc_html( $date ) . '</p>';
    $html .= '<p><strong>Tarayıcı:</strong> ' . esc_html( $ua ) . '</p>';
    $html .= '<p><strong>IP Adresi:</strong> ' . esc_html( $ip ) . '</p>';
    $html .= '<h3>KVKK Metni</h3><div>' . $kvkk . '</div>';
    $html .= '<h3>Mesafeli Satış Sözleşmesi</h3><div>' . $mes . '</div>';
    $html .= '</body></html>';

    $pdf = new Dompdf();
    $pdf->set_option('isRemoteEnabled', true);
    $pdf->loadHtml( $html );
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();

    $file = $dir . '/sozlesme-' . $order_id . '.pdf';
    file_put_contents( $file, $pdf->output() );
    return $file;
}

/**
 * Thank you sayfası ve loglama
 */
add_action( 'woocommerce_thankyou', 'yspr_thankyou_pdf' );
function yspr_thankyou_pdf( $order_id ) {
    // PDF oluştur
    $file   = yspr_generate_pdf_for_order( $order_id );
    $order  = wc_get_order( $order_id );
    $upload = wp_upload_dir();

    // Sipariş notu
    $order->add_order_note(
        'Yasal sözleşme PDF oluşturuldu. <a href="' . esc_url( $upload['baseurl'] . '/yspr-sozlesmeler/sozlesme-' . $order_id . '.pdf' ) . '" target="_blank">Görüntüle</a>'
    );

    // Log kaydı
    $log = get_option( 'yspr_pdf_log' );
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    // Kullanıcı bilgileri
    $user_id = $order->get_user_id();
    $name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $date    = date_i18n( 'Y-m-d H:i:s' );

    $log[] = [
        'order_id' => $order_id,
        'user_id'  => $user_id,
        'name'     => $name,
        'date'     => $date,
        'file'     => $upload['baseurl'] . '/yspr-sozlesmeler/sozlesme-' . $order_id . '.pdf'
    ];
    update_option( 'yspr_pdf_log', $log );
}
?>
