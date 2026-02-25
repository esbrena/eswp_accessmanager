<?php
/*
Plugin Name: Renovaciones CIE
Description: Sistema completo de gestión de renovaciones y accesos.
Version: 1.0
Author: CIE
*/

if (!defined('ABSPATH')) exit;

/* =====================================================
   1️⃣ CPT SOLICITUDES
===================================================== */

add_action('init', function () {

    register_post_type('solicitud', [
        'labels' => [
            'name' => 'Solicitudes',
            'singular_name' => 'Solicitud'
        ],
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-clock',
        'supports' => ['title', 'author', 'custom-fields'],
    ]);
});


/* =====================================================
   2️⃣ CREAR SOLICITUD
===================================================== */

function cie_crear_solicitud($user_id, $meses) {

    $pendiente = get_posts([
        'post_type' => 'solicitud',
        'author' => $user_id,
        'post_status' => 'pending',
        'numberposts' => 1
    ]);

    if ($pendiente) {
        return new WP_Error('pendiente', 'Ya tienes una solicitud pendiente.');
    }

    return wp_insert_post([
        'post_type' => 'solicitud',
        'post_status' => 'pending',
        'post_author' => $user_id,
        'post_title' => 'Renovación ' . $meses . ' mes(es)',
        'meta_input' => [
            'meses' => $meses,
            'fecha_solicitud' => current_time('mysql')
        ]
    ]);
}


/* =====================================================
   3️⃣ SHORTCODE + AJAX
===================================================== */

add_action('wp_enqueue_scripts', function () {

    wp_register_script('cie_ajax', '', ['jquery'], null, true);

    wp_add_inline_script('cie_ajax', "
        jQuery(document).ready(function($){
            $('#cie-form-renovacion').on('submit', function(e){
                e.preventDefault();
                var meses = $('#cie_meses').val();
                $.post(cie_ajax.ajax_url,{
                    action:'cie_enviar_renovacion',
                    nonce: cie_ajax.nonce,
                    meses: meses
                }, function(resp){
                    alert(resp.data);
                    if(resp.success) location.reload();
                });
            });
        });
    ");

    wp_localize_script('cie_ajax', 'cie_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cie_nonce')
    ]);

    wp_enqueue_script('cie_ajax');
});


add_shortcode('renovar_acceso', function () {

    if (!is_user_logged_in()) return '<p>Debes iniciar sesión.</p>';

    ob_start(); ?>
    <form id="cie-form-renovacion">
        <select id="cie_meses">
            <option value="1">1 mes</option>
            <option value="3">3 meses</option>
            <option value="6">6 meses</option>
        </select>
        <button type="submit">Solicitar renovación</button>
    </form>
    <?php return ob_get_clean();
});

add_action('wp_ajax_cie_enviar_renovacion', 'cie_ajax_enviar');

function cie_ajax_enviar() {

    check_ajax_referer('cie_nonce', 'nonce');

    if (!is_user_logged_in())
        wp_send_json_error('Debes iniciar sesión.');

    $user_id = get_current_user_id();
    $meses = intval($_POST['meses']);

    $res = cie_crear_solicitud($user_id, $meses);

    if (is_wp_error($res))
        wp_send_json_error($res->get_error_message());

    wp_send_json_success('Solicitud enviada correctamente.');
}


/* =====================================================
   4️⃣ APROBAR / RECHAZAR DESDE ADMIN
===================================================== */

add_action('admin_init', function () {

    if (!isset($_GET['cie_accion'], $_GET['solicitud_id'])) return;

    if (!current_user_can('manage_options')) return;

    $id = intval($_GET['solicitud_id']);
    $accion = sanitize_text_field($_GET['cie_accion']);

    $post = get_post($id);
    if (!$post) return;

    $user_id = $post->post_author;
    $meses = get_post_meta($id, 'meses', true);

    if ($accion === 'aprobar') {

        $use_period = get_user_meta($user_id, 'use_period', true);
        if (!$use_period) return;

        $fechas = explode('—', $use_period);
        $fecha_inicio = trim($fechas[0]);
        $fecha_fin = DateTime::createFromFormat('d/m/Y', trim($fechas[1]));
        $fecha_fin->modify("+{$meses} months");

        $nuevo = $fecha_inicio . ' — ' . $fecha_fin->format('d/m/Y');
        update_user_meta($user_id, 'use_period', $nuevo);

        wp_update_post(['ID'=>$id,'post_status'=>'publish']);

        $user = get_userdata($user_id);
        wp_mail($user->user_email, 'Solicitud aprobada', 'Tu acceso ha sido renovado hasta '.$fecha_fin->format('d/m/Y'));

    }

    if ($accion === 'rechazar') {

        wp_update_post(['ID'=>$id,'post_status'=>'draft']);

        $user = get_userdata($user_id);
        wp_mail($user->user_email, 'Solicitud rechazada', 'Tu solicitud fue rechazada.');
    }

    wp_redirect(admin_url('edit.php?post_type=solicitud'));
    exit;
});


/* =====================================================
   5️⃣ MENÚ ADMIN GESTIÓN ACCESOS
===================================================== */

add_action('admin_menu', function(){

    add_menu_page(
        'Gestión Accesos',
        'Accesos',
        'manage_options',
        'cie_accesos',
        'cie_render_admin',
        'dashicons-shield-alt',
        25
    );
});


function cie_render_admin(){

    echo '<div class="wrap"><h1>Gestión de Accesos</h1>';
    echo '<table class="widefat striped"><thead>
    <tr><th>Usuario</th><th>Email</th><th>Periodo</th><th>Estado</th></tr>
    </thead><tbody>';

    $users = get_users();

    foreach($users as $u){

        $periodo = get_user_meta($u->ID,'use_period',true);
        if(!$periodo) continue;

        $fechas = explode('—',$periodo);
        $fin = DateTime::createFromFormat('d/m/Y',trim($fechas[1]));
        $hoy = new DateTime();

        $estado = 'Activo';

        if($fin < $hoy) $estado = '<span style="color:red">Caducado</span>';
        elseif($fin <= (clone $hoy)->modify('+15 days'))
            $estado = '<span style="color:orange">Próximo a caducar</span>';

        echo "<tr>
        <td>{$u->display_name}</td>
        <td>{$u->user_email}</td>
        <td>{$periodo}</td>
        <td>{$estado}</td>
        </tr>";
    }

    echo '</tbody></table></div>';
}


/* =====================================================
   6️⃣ CRON AVISO 15 DÍAS
===================================================== */

register_activation_hook(__FILE__, function(){
    if(!wp_next_scheduled('cie_cron_aviso')){
        wp_schedule_event(time(), 'daily', 'cie_cron_aviso');
    }
});

register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('cie_cron_aviso');
});


add_action('cie_cron_aviso', function(){

    $users = get_users();
    $hoy = new DateTime();

    foreach($users as $u){

        $periodo = get_user_meta($u->ID,'use_period',true);
        if(!$periodo) continue;

        $fechas = explode('—',$periodo);
        $fin = DateTime::createFromFormat('d/m/Y',trim($fechas[1]));

        if(!$fin) continue;

        $diff = $hoy->diff($fin)->days;

        if($fin > $hoy && $diff == 15){

            if(get_user_meta($u->ID,'cie_aviso_enviado',true)) continue;

            wp_mail(
                $u->user_email,
                'Tu acceso caduca en 15 días',
                'Tu acceso caduca el '.$fin->format('d/m/Y').'. Renueva tu acceso.'
            );

            update_user_meta($u->ID,'cie_aviso_enviado',1);
        }

        if($fin > $hoy && $diff > 15){
            delete_user_meta($u->ID,'cie_aviso_enviado');
        }
    }

});