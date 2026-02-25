<?php
/*
Plugin Name: Renovaciones CIE
Description: Sistema completo de gestion de renovaciones y accesos.
Version: 1.1.0
Author: CIE
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CIE_RENEWAL_THRESHOLD_DAYS')) {
    define('CIE_RENEWAL_THRESHOLD_DAYS', 15);
}

if (!defined('CIE_NOTICE_META_KEY')) {
    define('CIE_NOTICE_META_KEY', 'cie_aviso_enviado');
}

if (!defined('CIE_HISTORY_META_KEY')) {
    define('CIE_HISTORY_META_KEY', 'cie_renewal_history');
}

/* =====================================================
   HELPERS GENERALES
===================================================== */

function cie_get_wp_timezone() {
    if (function_exists('wp_timezone')) {
        return wp_timezone();
    }

    $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    if (!$timezone_string) {
        $timezone_string = 'UTC';
    }

    return new DateTimeZone($timezone_string);
}

function cie_allowed_roles() {
    return ['cie_user', 'cie_user_new'];
}

function cie_admin_capability() {
    return apply_filters('cie_admin_capability', 'manage_options');
}

function cie_user_has_allowed_role($user_or_id) {
    $user = is_numeric($user_or_id) ? get_userdata((int) $user_or_id) : $user_or_id;
    if (!$user || empty($user->roles)) {
        return false;
    }

    return count(array_intersect((array) $user->roles, cie_allowed_roles())) > 0;
}

function cie_parse_use_period($period) {
    $period = trim((string) $period);
    if ($period === '') {
        return null;
    }

    $parts = preg_split('/\s*[—-]\s*/u', $period);
    if (!is_array($parts) || count($parts) < 2) {
        return null;
    }

    $start = DateTimeImmutable::createFromFormat('d/m/Y', trim($parts[0]), cie_get_wp_timezone());
    $end = DateTimeImmutable::createFromFormat('d/m/Y', trim($parts[1]), cie_get_wp_timezone());

    if (!$start || !$end) {
        return null;
    }

    return [
        'start' => $start->setTime(0, 0, 0),
        'end' => $end->setTime(0, 0, 0),
    ];
}

function cie_format_use_period(DateTimeInterface $start, DateTimeInterface $end) {
    return $start->format('d/m/Y') . ' — ' . $end->format('d/m/Y');
}

function cie_get_user_period_data($user_id) {
    $raw = get_user_meta((int) $user_id, 'use_period', true);
    if (!$raw) {
        return null;
    }

    $parsed = cie_parse_use_period($raw);
    if (!$parsed) {
        return null;
    }

    return [
        'raw' => $raw,
        'start' => $parsed['start'],
        'end' => $parsed['end'],
    ];
}

function cie_get_days_remaining($user_id) {
    $period = cie_get_user_period_data($user_id);
    if (!$period) {
        return null;
    }

    $today = new DateTimeImmutable('today', cie_get_wp_timezone());
    $seconds = $period['end']->getTimestamp() - $today->getTimestamp();

    return (int) floor($seconds / DAY_IN_SECONDS);
}

function cie_get_access_status_data($user_id) {
    $period = cie_get_user_period_data($user_id);
    if (!$period) {
        return [
            'code' => 'sin_periodo',
            'label' => 'Sin periodo',
            'html' => '<span style="color:#646970;">Sin periodo</span>',
            'days' => null,
            'period' => '',
        ];
    }

    $days = cie_get_days_remaining($user_id);
    $status = [
        'code' => 'activo',
        'label' => 'Activo',
        'html' => '<span style="color:#008a20;font-weight:600;">Activo</span>',
        'days' => $days,
        'period' => $period['raw'],
    ];

    if ($days !== null && $days < 0) {
        $status['code'] = 'caducado';
        $status['label'] = 'Caducado';
        $status['html'] = '<span style="color:#b32d2e;font-weight:600;">Caducado</span>';
    } elseif ($days !== null && $days <= CIE_RENEWAL_THRESHOLD_DAYS) {
        $status['code'] = 'proximo';
        $status['label'] = 'Proximo a caducar';
        $status['html'] = '<span style="color:#b26200;font-weight:600;">Proximo a caducar</span>';
    }

    return $status;
}

function cie_get_pending_request_id($user_id) {
    $pending = get_posts([
        'post_type' => 'solicitud',
        'author' => (int) $user_id,
        'post_status' => 'pending',
        'numberposts' => 1,
        'fields' => 'ids',
    ]);

    return !empty($pending) ? (int) $pending[0] : 0;
}

function cie_user_has_any_request($user_id) {
    $posts = get_posts([
        'post_type' => 'solicitud',
        'author' => (int) $user_id,
        'post_status' => ['pending', 'publish', 'draft'],
        'numberposts' => 1,
        'fields' => 'ids',
    ]);

    return !empty($posts);
}

function cie_validate_request_eligibility($user_id) {
    $user = get_userdata((int) $user_id);
    if (!$user) {
        return new WP_Error('cie_no_user', 'Usuario no valido.');
    }

    if (!cie_user_has_allowed_role($user)) {
        return new WP_Error('cie_role_denied', 'Este formulario solo esta disponible para usuarios cie_user y cie_user_new.');
    }

    $days = cie_get_days_remaining($user->ID);
    if ($days === null) {
        return new WP_Error('cie_no_period', 'No tienes un periodo de acceso configurado.');
    }

    if ($days > CIE_RENEWAL_THRESHOLD_DAYS) {
        return new WP_Error('cie_too_early', 'La solicitud solo se habilita cuando faltan 15 dias o menos de acceso.');
    }

    $pending_id = cie_get_pending_request_id($user->ID);
    if ($pending_id) {
        return new WP_Error('cie_pending', 'Ya tienes una solicitud pendiente.');
    }

    return true;
}

function cie_get_user_renewal_history($user_id) {
    $history = get_user_meta((int) $user_id, CIE_HISTORY_META_KEY, true);
    return is_array($history) ? $history : [];
}

function cie_get_renewal_source_label($source) {
    $labels = [
        'solicitud_aprobada' => 'Solicitud aprobada',
        'manual_admin' => 'Renovacion manual admin',
    ];

    return isset($labels[$source]) ? $labels[$source] : ucfirst(str_replace('_', ' ', (string) $source));
}

function cie_add_user_renewal_history($user_id, array $entry) {
    $history = cie_get_user_renewal_history($user_id);
    array_unshift($history, $entry);

    if (count($history) > 100) {
        $history = array_slice($history, 0, 100);
    }

    update_user_meta((int) $user_id, CIE_HISTORY_META_KEY, $history);
}

function cie_extend_user_access($user_id, $months, $source = 'manual_admin', $actor_user_id = 0, $note = '', $request_id = 0) {
    $user_id = (int) $user_id;
    $months = (int) $months;
    $actor_user_id = (int) $actor_user_id;
    $request_id = (int) $request_id;

    if ($months < 1) {
        return new WP_Error('cie_invalid_months', 'La cantidad de meses no es valida.');
    }

    $period = cie_get_user_period_data($user_id);
    if (!$period) {
        return new WP_Error('cie_no_period', 'El usuario no tiene periodo de acceso configurado.');
    }

    $today = new DateTimeImmutable('today', cie_get_wp_timezone());
    $base_end = ($period['end'] < $today) ? $today : $period['end'];
    $new_end = $base_end->modify('+' . $months . ' months');

    if (!$new_end) {
        return new WP_Error('cie_period_error', 'No fue posible calcular el nuevo periodo.');
    }

    $new_period = cie_format_use_period($period['start'], $new_end);
    $old_period = $period['raw'];

    update_user_meta($user_id, 'use_period', $new_period);

    $new_days = cie_get_days_remaining($user_id);
    if ($new_days !== null && $new_days > CIE_RENEWAL_THRESHOLD_DAYS) {
        delete_user_meta($user_id, CIE_NOTICE_META_KEY);
    }

    cie_add_user_renewal_history($user_id, [
        'timestamp' => current_time('mysql'),
        'source' => $source,
        'months' => $months,
        'old_period' => $old_period,
        'new_period' => $new_period,
        'actor' => $actor_user_id,
        'note' => $note,
        'request_id' => $request_id,
    ]);

    return [
        'new_period' => $new_period,
        'new_end' => $new_end,
    ];
}

function cie_get_admin_page_url($args = []) {
    return add_query_arg($args, admin_url('admin.php?page=cie_gestion_acceso'));
}

function cie_build_user_request_map(array $user_ids) {
    $map = [
        'any' => [],
        'pending' => [],
        'latest' => [],
    ];

    if (empty($user_ids)) {
        return $map;
    }

    $requests = get_posts([
        'post_type' => 'solicitud',
        'post_status' => ['pending', 'publish', 'draft'],
        'posts_per_page' => -1,
        'author__in' => $user_ids,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    foreach ($requests as $request) {
        $author = (int) $request->post_author;
        if (!$author) {
            continue;
        }

        $map['any'][$author] = true;

        if (!isset($map['latest'][$author])) {
            $map['latest'][$author] = [
                'id' => (int) $request->ID,
                'status' => $request->post_status,
            ];
        }

        if ($request->post_status === 'pending' && !isset($map['pending'][$author])) {
            $map['pending'][$author] = (int) $request->ID;
        }
    }

    return $map;
}

/* =====================================================
   HISTORICO + ESTADO EN HTML (USO EXTERNO)
===================================================== */

function cie_get_estado_acceso_usuario_html($user_id) {
    $user = get_userdata((int) $user_id);
    if (!$user) {
        return '<p>Usuario no encontrado.</p>';
    }

    $status = cie_get_access_status_data($user->ID);
    $history = cie_get_user_renewal_history($user->ID);
    $roles = implode(', ', (array) $user->roles);
    $days_text = ($status['days'] === null) ? 'N/A' : (string) $status['days'];

    ob_start();
    ?>
    <div class="cie-user-overview" style="padding:8px 0;">
        <ul style="margin:0 0 12px 16px;list-style:disc;">
            <li><strong>Usuario:</strong> <?php echo esc_html($user->display_name); ?> (ID <?php echo (int) $user->ID; ?>)</li>
            <li><strong>Email:</strong> <?php echo esc_html($user->user_email); ?></li>
            <li><strong>Roles:</strong> <?php echo esc_html($roles ?: '-'); ?></li>
            <li><strong>Periodo:</strong> <?php echo esc_html($status['period'] ?: 'Sin periodo'); ?></li>
            <li><strong>Estado:</strong> <?php echo wp_kses_post($status['html']); ?></li>
            <li><strong>Dias restantes:</strong> <?php echo esc_html($days_text); ?></li>
        </ul>

        <h4 style="margin:8px 0;">Historico de renovaciones</h4>
        <?php if (empty($history)) : ?>
            <p style="margin:0;">Sin renovaciones registradas.</p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Fuente</th>
                        <th>Meses</th>
                        <th>Periodo anterior</th>
                        <th>Nuevo periodo</th>
                        <th>Administrador</th>
                        <th>Nota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $entry) : ?>
                        <?php
                        $actor = !empty($entry['actor']) ? get_userdata((int) $entry['actor']) : null;
                        $actor_name = $actor ? $actor->display_name : '-';
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($entry['timestamp'] ?? '-')); ?></td>
                            <td><?php echo esc_html(cie_get_renewal_source_label((string) ($entry['source'] ?? 'manual_admin'))); ?></td>
                            <td><?php echo esc_html((string) ($entry['months'] ?? '-')); ?></td>
                            <td><?php echo esc_html((string) ($entry['old_period'] ?? '-')); ?></td>
                            <td><?php echo esc_html((string) ($entry['new_period'] ?? '-')); ?></td>
                            <td><?php echo esc_html($actor_name); ?></td>
                            <td><?php echo esc_html((string) ($entry['note'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function cie_render_estado_acceso_usuario_html($user_id) {
    echo cie_get_estado_acceso_usuario_html($user_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/* =====================================================
   CPT SOLICITUDES
===================================================== */

add_action('init', function () {
    register_post_type('solicitud', [
        'labels' => [
            'name' => 'Solicitudes',
            'singular_name' => 'Solicitud',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'cie_gestion_acceso',
        'menu_icon' => 'dashicons-clock',
        'supports' => ['title', 'author', 'custom-fields'],
    ]);
});

/* =====================================================
   SHORTCODE + AJAX
===================================================== */

add_action('wp_enqueue_scripts', function () {
    wp_register_script('cie_ajax', '', ['jquery'], '1.1.0', true);

    wp_add_inline_script('cie_ajax', "
        jQuery(function($){
            $(document)
                .off('submit.cieRenovacion', '.cie-form-renovacion')
                .on('submit.cieRenovacion', '.cie-form-renovacion', function(e){
                    e.preventDefault();

                    var \$form = $(this);
                    var \$btn = \$form.find('button[type=\"submit\"]');
                    var \$msg = \$form.find('.cie-renovacion-respuesta');

                    \$btn.prop('disabled', true);
                    \$msg.removeClass('ok err').text('Enviando solicitud...');

                    $.post(cie_ajax.ajax_url, {
                        action: 'cie_enviar_renovacion',
                        nonce: cie_ajax.nonce,
                        meses: \$form.find('[name=\"cie_meses\"]').val(),
                        notify_email: \$form.find('[name=\"notify_email\"]').val(),
                        notify_nonce: \$form.find('[name=\"notify_nonce\"]').val()
                    })
                    .done(function(resp){
                        if (resp && resp.success) {
                            \$msg.addClass('ok').text(resp.data || 'Solicitud enviada correctamente.');
                            \$form.find('select,button').prop('disabled', true);
                            return;
                        }

                        var errorText = (resp && resp.data) ? resp.data : 'No se pudo enviar la solicitud.';
                        \$msg.addClass('err').text(errorText);
                        \$btn.prop('disabled', false);
                    })
                    .fail(function(){
                        \$msg.addClass('err').text('No se pudo enviar la solicitud.');
                        \$btn.prop('disabled', false);
                    });
                });
        });
    ");

    wp_localize_script('cie_ajax', 'cie_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cie_nonce'),
    ]);

    wp_enqueue_script('cie_ajax');
});

add_shortcode('renovar_acceso', function ($atts) {
    if (!is_user_logged_in()) {
        return '<p>Debes iniciar sesion.</p>';
    }

    $user_id = get_current_user_id();
    $eligibility = cie_validate_request_eligibility($user_id);

    if (is_wp_error($eligibility)) {
        return '<p>' . esc_html($eligibility->get_error_message()) . '</p>';
    }

    $atts = shortcode_atts(['email' => ''], $atts, 'renovar_acceso');
    $notify_email = sanitize_email((string) $atts['email']);
    if (!$notify_email || !is_email($notify_email)) {
        $notify_email = get_option('admin_email');
    }

    $notify_nonce = wp_create_nonce('cie_notify_' . strtolower($notify_email));

    ob_start();
    ?>
    <form class="cie-form-renovacion" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select name="cie_meses" required>
            <option value="1">1 mes</option>
            <option value="3">3 meses</option>
            <option value="6">6 meses</option>
            <option value="12">12 meses</option>
        </select>
        <input type="hidden" name="notify_email" value="<?php echo esc_attr($notify_email); ?>">
        <input type="hidden" name="notify_nonce" value="<?php echo esc_attr($notify_nonce); ?>">
        <button type="submit">Solicitar renovacion</button>
        <small style="display:block;flex-basis:100%;">La solicitud se enviara a: <?php echo esc_html($notify_email); ?></small>
        <div class="cie-renovacion-respuesta" style="flex-basis:100%;font-weight:600;"></div>
    </form>
    <style>
        .cie-renovacion-respuesta.ok { color:#008a20; }
        .cie-renovacion-respuesta.err { color:#b32d2e; }
    </style>
    <?php
    return ob_get_clean();
});

add_action('wp_ajax_cie_enviar_renovacion', 'cie_ajax_enviar');

function cie_ajax_enviar() {
    check_ajax_referer('cie_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Debes iniciar sesion.');
    }

    $user_id = get_current_user_id();
    $eligibility = cie_validate_request_eligibility($user_id);
    if (is_wp_error($eligibility)) {
        wp_send_json_error($eligibility->get_error_message());
    }

    $meses = isset($_POST['meses']) ? (int) $_POST['meses'] : 0;
    if (!in_array($meses, [1, 3, 6, 12], true)) {
        wp_send_json_error('Selecciona una cantidad de meses valida.');
    }

    $notify_email = isset($_POST['notify_email']) ? sanitize_email(wp_unslash($_POST['notify_email'])) : '';
    $notify_nonce = isset($_POST['notify_nonce']) ? sanitize_text_field(wp_unslash($_POST['notify_nonce'])) : '';

    if (
        !$notify_email ||
        !is_email($notify_email) ||
        !wp_verify_nonce($notify_nonce, 'cie_notify_' . strtolower($notify_email))
    ) {
        $notify_email = get_option('admin_email');
    }

    $res = cie_crear_solicitud($user_id, $meses, $notify_email);
    if (is_wp_error($res)) {
        wp_send_json_error($res->get_error_message());
    }

    wp_send_json_success('Solicitud enviada correctamente.');
}

/* =====================================================
   CREAR SOLICITUD + NOTIFICACION ADMIN
===================================================== */

function cie_crear_solicitud($user_id, $meses, $notify_email = '') {
    $eligibility = cie_validate_request_eligibility($user_id);
    if (is_wp_error($eligibility)) {
        return $eligibility;
    }

    $notify_email = sanitize_email((string) $notify_email);
    if (!$notify_email || !is_email($notify_email)) {
        $notify_email = get_option('admin_email');
    }

    $request_id = wp_insert_post([
        'post_type' => 'solicitud',
        'post_status' => 'pending',
        'post_author' => (int) $user_id,
        'post_title' => 'Renovacion ' . (int) $meses . ' mes(es)',
        'meta_input' => [
            'meses' => (int) $meses,
            'fecha_solicitud' => current_time('mysql'),
            'notify_email' => $notify_email,
        ],
    ]);

    if (is_wp_error($request_id) || !$request_id) {
        return new WP_Error('cie_request_create_error', 'No fue posible crear la solicitud.');
    }

    cie_notify_request_target((int) $request_id, $notify_email);

    return (int) $request_id;
}

function cie_notify_request_target($request_id, $notify_email = '') {
    $request = get_post((int) $request_id);
    if (!$request || $request->post_type !== 'solicitud') {
        return;
    }

    $user = get_userdata((int) $request->post_author);
    if (!$user) {
        return;
    }

    $notify_email = sanitize_email((string) $notify_email);
    if (!$notify_email || !is_email($notify_email)) {
        $notify_email = get_option('admin_email');
    }

    $months = (int) get_post_meta($request->ID, 'meses', true);
    $period = get_user_meta($user->ID, 'use_period', true);
    $status = cie_get_access_status_data($user->ID);
    $days = ($status['days'] === null) ? 'N/A' : (string) $status['days'];

    $approve_url = wp_nonce_url(
        admin_url('admin-post.php?action=cie_solicitud_aprobar&solicitud_id=' . (int) $request->ID),
        'cie_solicitud_aprobar_' . (int) $request->ID
    );

    $revoke_url = wp_nonce_url(
        admin_url('admin.php?page=cie_gestion_acceso&cie_action=rechazar_form&solicitud_id=' . (int) $request->ID),
        'cie_solicitud_rechazar_form_' . (int) $request->ID
    );

    $subject = 'Nueva solicitud de renovacion de acceso';
    $message = "Se recibio una solicitud de renovacion.\n\n";
    $message .= "Usuario: " . $user->display_name . " (ID " . (int) $user->ID . ")\n";
    $message .= "Email usuario: " . $user->user_email . "\n";
    $message .= "Meses solicitados: " . $months . "\n";
    $message .= "Periodo actual: " . ($period ?: 'Sin periodo') . "\n";
    $message .= "Dias restantes: " . $days . "\n\n";
    $message .= "Aceptar acceso: " . $approve_url . "\n";
    $message .= "Revocar solicitud: " . $revoke_url . "\n\n";
    $message .= "Al revocar podras personalizar el correo que se enviara al usuario.";

    wp_mail($notify_email, $subject, $message);
}

/* =====================================================
   APROBAR / REVOCAR SOLICITUDES
===================================================== */

add_action('admin_post_cie_solicitud_aprobar', 'cie_admin_aprobar_solicitud');

function cie_admin_aprobar_solicitud() {
    if (!current_user_can(cie_admin_capability())) {
        wp_die('No autorizado.');
    }

    $request_id = isset($_GET['solicitud_id']) ? (int) $_GET['solicitud_id'] : 0;
    check_admin_referer('cie_solicitud_aprobar_' . $request_id);

    $request = get_post($request_id);
    if (!$request || $request->post_type !== 'solicitud') {
        wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'solicitud_no_encontrada']));
        exit;
    }

    if ($request->post_status !== 'pending') {
        wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'solicitud_ya_resuelta']));
        exit;
    }

    $user_id = (int) $request->post_author;
    $months = (int) get_post_meta($request_id, 'meses', true);
    if ($months < 1) {
        $months = 1;
    }

    $result = cie_extend_user_access(
        $user_id,
        $months,
        'solicitud_aprobada',
        get_current_user_id(),
        'Solicitud aprobada por administrador.',
        $request_id
    );

    if (is_wp_error($result)) {
        wp_safe_redirect(cie_get_admin_page_url([
            'cie_notice' => 'error',
            'cie_error' => $result->get_error_message(),
        ]));
        exit;
    }

    wp_update_post([
        'ID' => $request_id,
        'post_status' => 'publish',
    ]);

    update_post_meta($request_id, 'cie_resultado', 'aprobada');
    update_post_meta($request_id, 'cie_resuelta_en', current_time('mysql'));
    update_post_meta($request_id, 'cie_resuelta_por', get_current_user_id());

    $user = get_userdata($user_id);
    if ($user) {
        wp_mail(
            $user->user_email,
            'Solicitud aprobada',
            'Tu acceso ha sido renovado. Nuevo periodo: ' . $result['new_period']
        );
    }

    wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'solicitud_aprobada']));
    exit;
}

add_action('admin_post_cie_solicitud_rechazar', 'cie_admin_rechazar_solicitud');

function cie_admin_rechazar_solicitud() {
    if (!current_user_can(cie_admin_capability())) {
        wp_die('No autorizado.');
    }

    $request_id = isset($_POST['solicitud_id']) ? (int) $_POST['solicitud_id'] : 0;
    check_admin_referer('cie_solicitud_rechazar_' . $request_id, 'cie_reject_nonce');

    $request = get_post($request_id);
    if (!$request || $request->post_type !== 'solicitud') {
        wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'solicitud_no_encontrada']));
        exit;
    }

    if ($request->post_status !== 'pending') {
        wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'solicitud_ya_resuelta']));
        exit;
    }

    $message = isset($_POST['mensaje_rechazo']) ? sanitize_textarea_field(wp_unslash($_POST['mensaje_rechazo'])) : '';
    if ($message === '') {
        $message = 'Tu solicitud de renovacion fue revocada por administracion.';
    }

    wp_update_post([
        'ID' => $request_id,
        'post_status' => 'draft',
    ]);

    update_post_meta($request_id, 'cie_resultado', 'revocada');
    update_post_meta($request_id, 'cie_resuelta_en', current_time('mysql'));
    update_post_meta($request_id, 'cie_resuelta_por', get_current_user_id());
    update_post_meta($request_id, 'cie_mensaje_revocacion', $message);

    $user = get_userdata((int) $request->post_author);
    if ($user) {
        wp_mail($user->user_email, 'Solicitud revocada', $message);
    }

    wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'solicitud_revocada']));
    exit;
}

/* =====================================================
   RENOVAR ACCESO DESDE TABLA ADMIN (MODAL)
===================================================== */

add_action('admin_post_cie_renovar_usuario', 'cie_admin_renovar_usuario');

function cie_admin_renovar_usuario() {
    if (!current_user_can(cie_admin_capability())) {
        wp_die('No autorizado.');
    }

    check_admin_referer('cie_admin_renovar_usuario', 'cie_admin_renew_nonce');

    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $months = isset($_POST['meses']) ? (int) $_POST['meses'] : 0;
    $note = isset($_POST['nota']) ? sanitize_textarea_field(wp_unslash($_POST['nota'])) : '';

    if ($user_id <= 0 || $months < 1) {
        wp_safe_redirect(cie_get_admin_page_url([
            'cie_notice' => 'error',
            'cie_error' => 'Datos invalidos para renovar el acceso.',
        ]));
        exit;
    }

    $result = cie_extend_user_access(
        $user_id,
        $months,
        'manual_admin',
        get_current_user_id(),
        $note
    );

    if (is_wp_error($result)) {
        wp_safe_redirect(cie_get_admin_page_url([
            'cie_notice' => 'error',
            'cie_error' => $result->get_error_message(),
        ]));
        exit;
    }

    $user = get_userdata($user_id);
    if ($user) {
        wp_mail(
            $user->user_email,
            'Acceso renovado',
            'Tu acceso fue renovado por administracion. Nuevo periodo: ' . $result['new_period']
        );
    }

    wp_safe_redirect(cie_get_admin_page_url(['cie_notice' => 'acceso_renovado']));
    exit;
}

/* =====================================================
   MENU ADMIN (SECCION UNICA "GESTION ACCESO")
===================================================== */

add_action('admin_menu', function () {
    $capability = cie_admin_capability();

    add_menu_page(
        'Gestion acceso',
        'Gestion acceso',
        $capability,
        'cie_gestion_acceso',
        'cie_render_admin_page',
        'dashicons-shield-alt',
        25
    );

    add_submenu_page(
        'cie_gestion_acceso',
        'Accesos',
        'Accesos',
        $capability,
        'cie_gestion_acceso',
        'cie_render_admin_page'
    );

    add_submenu_page(
        'cie_gestion_acceso',
        'Solicitudes',
        'Solicitudes',
        $capability,
        'edit.php?post_type=solicitud'
    );

    // Compatibilidad con la URL historica: ?page=cie_accesos
    add_submenu_page(
        null,
        'Accesos',
        'Accesos',
        $capability,
        'cie_accesos',
        'cie_render_admin_page_legacy_redirect'
    );
});

function cie_render_admin_page_legacy_redirect() {
    wp_safe_redirect(cie_get_admin_page_url());
    exit;
}

function cie_render_admin_notices() {
    if (!isset($_GET['cie_notice'])) {
        return;
    }

    $notice = sanitize_key(wp_unslash($_GET['cie_notice']));
    $messages = [
        'solicitud_aprobada' => ['updated', 'Solicitud aprobada y acceso renovado.'],
        'solicitud_revocada' => ['updated', 'Solicitud revocada correctamente.'],
        'acceso_renovado' => ['updated', 'Acceso renovado correctamente desde la tabla.'],
        'solicitud_no_encontrada' => ['error', 'No se encontro la solicitud indicada.'],
        'solicitud_ya_resuelta' => ['error', 'La solicitud ya fue procesada previamente.'],
    ];

    if ($notice === 'error') {
        $error = isset($_GET['cie_error']) ? sanitize_text_field(wp_unslash($_GET['cie_error'])) : 'Ocurrio un error.';
        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        return;
    }

    if (isset($messages[$notice])) {
        $type = ($messages[$notice][0] === 'updated') ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($type) . '"><p>' . esc_html($messages[$notice][1]) . '</p></div>';
    }
}

function cie_render_reject_form_box() {
    $action = isset($_GET['cie_action']) ? sanitize_key(wp_unslash($_GET['cie_action'])) : '';
    if ($action !== 'rechazar_form') {
        return;
    }

    $request_id = isset($_GET['solicitud_id']) ? (int) $_GET['solicitud_id'] : 0;
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!$request_id || !wp_verify_nonce($nonce, 'cie_solicitud_rechazar_form_' . $request_id)) {
        echo '<div class="notice notice-error"><p>Enlace de revocacion invalido o expirado.</p></div>';
        return;
    }

    $request = get_post($request_id);
    if (!$request || $request->post_type !== 'solicitud') {
        echo '<div class="notice notice-error"><p>No se encontro la solicitud.</p></div>';
        return;
    }

    if ($request->post_status !== 'pending') {
        echo '<div class="notice notice-error"><p>La solicitud ya fue procesada previamente.</p></div>';
        return;
    }

    $user = get_userdata((int) $request->post_author);
    if (!$user) {
        echo '<div class="notice notice-error"><p>No se encontro el usuario de la solicitud.</p></div>';
        return;
    }

    ?>
    <div class="card" style="max-width:900px;margin:16px 0;padding:16px;">
        <h2 style="margin-top:0;">Revocar solicitud #<?php echo (int) $request_id; ?></h2>
        <p>
            <strong>Usuario:</strong> <?php echo esc_html($user->display_name); ?>
            (<?php echo esc_html($user->user_email); ?>)
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cie_solicitud_rechazar">
            <input type="hidden" name="solicitud_id" value="<?php echo (int) $request_id; ?>">
            <?php wp_nonce_field('cie_solicitud_rechazar_' . $request_id, 'cie_reject_nonce'); ?>
            <p>
                <label for="cie-mensaje-rechazo"><strong>Mensaje para el usuario</strong></label><br>
                <textarea id="cie-mensaje-rechazo" name="mensaje_rechazo" rows="5" style="width:100%;">Tu solicitud de renovacion fue revocada por administracion.</textarea>
            </p>
            <p>
                <button type="submit" class="button button-primary">Revocar y enviar correo</button>
            </p>
        </form>
    </div>
    <?php
}

function cie_render_admin_page() {
    if (!current_user_can(cie_admin_capability())) {
        return;
    }

    wp_enqueue_script('jquery');

    $request_filter = isset($_GET['cie_request_filter']) ? sanitize_key(wp_unslash($_GET['cie_request_filter'])) : 'all';
    if (!in_array($request_filter, ['all', 'pending'], true)) {
        $request_filter = 'all';
    }

    $users = get_users([
        'role__in' => cie_allowed_roles(),
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);

    $request_map = cie_build_user_request_map(array_map(static function ($u) {
        return (int) $u->ID;
    }, $users));

    echo '<div class="wrap">';
    echo '<h1>Gestion acceso</h1>';

    cie_render_admin_notices();
    cie_render_reject_form_box();

    ?>
    <form method="get" style="margin:14px 0;">
        <input type="hidden" name="page" value="cie_gestion_acceso">
        <label for="cie_request_filter"><strong>Filtrar por solicitud:</strong></label>
        <select id="cie_request_filter" name="cie_request_filter">
            <option value="all" <?php selected($request_filter, 'all'); ?>>Todos</option>
            <option value="pending" <?php selected($request_filter, 'pending'); ?>>Solo pendientes</option>
        </select>
        <button class="button">Aplicar</button>
    </form>
    <?php

    echo '<table class="widefat striped">';
    echo '<thead><tr>
        <th>Usuario</th>
        <th>Email</th>
        <th>Rol</th>
        <th>Periodo</th>
        <th>Dias restantes</th>
        <th>Estado acceso</th>
        <th>Solicitud enviada</th>
        <th>Solicitud pendiente</th>
        <th>Acciones</th>
        <th>Estado + historico</th>
    </tr></thead><tbody>';

    $rows = 0;

    foreach ($users as $user) {
        $status = cie_get_access_status_data($user->ID);
        $days = $status['days'];
        $days_label = ($days === null) ? 'N/A' : (string) $days;
        $has_request = !empty($request_map['any'][$user->ID]);
        $pending_id = isset($request_map['pending'][$user->ID]) ? (int) $request_map['pending'][$user->ID] : 0;
        $can_manual_renew = ($days !== null && $days <= CIE_RENEWAL_THRESHOLD_DAYS);

        if ($request_filter === 'pending' && !$pending_id) {
            continue;
        }

        $rows++;
        $roles = implode(', ', (array) $user->roles);
        $overview_html = cie_get_estado_acceso_usuario_html($user->ID);

        echo '<tr>';
        echo '<td>' . esc_html($user->display_name) . '</td>';
        echo '<td>' . esc_html($user->user_email) . '</td>';
        echo '<td>' . esc_html($roles) . '</td>';
        echo '<td>' . esc_html($status['period'] ?: 'Sin periodo') . '</td>';
        echo '<td>' . esc_html($days_label) . '</td>';
        echo '<td>' . wp_kses_post($status['html']) . '</td>';
        echo '<td>' . ($has_request ? 'Si' : 'No') . '</td>';

        if ($pending_id) {
            $pending_link = admin_url('post.php?post=' . $pending_id . '&action=edit');
            echo '<td><a href="' . esc_url($pending_link) . '">Pendiente (#' . (int) $pending_id . ')</a></td>';
        } else {
            echo '<td>No</td>';
        }

        echo '<td>';
        if ($can_manual_renew) {
            echo '<button class="button button-secondary cie-open-renew-modal" data-user-id="' . (int) $user->ID . '" data-user-name="' . esc_attr($user->display_name) . '">Renovar</button>';
        } else {
            echo '<span style="color:#646970;">-</span>';
        }
        echo '</td>';

        echo '<td><details><summary>Ver</summary>' . $overview_html . '</details></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</tr>';
    }

    if ($rows === 0) {
        echo '<tr><td colspan="10">No hay usuarios para el filtro seleccionado.</td></tr>';
    }

    echo '</tbody></table>';

    ?>
    <div id="cie-renew-modal" style="display:none;">
        <div class="cie-renew-modal-backdrop"></div>
        <div class="cie-renew-modal-card">
            <button type="button" class="button-link cie-renew-close" style="float:right;">Cerrar</button>
            <h2>Renovar acceso</h2>
            <p id="cie-renew-user-label" style="margin-bottom:12px;"></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cie_renovar_usuario">
                <input type="hidden" name="user_id" id="cie-renew-user-id" value="">
                <?php wp_nonce_field('cie_admin_renovar_usuario', 'cie_admin_renew_nonce'); ?>
                <p>
                    <label for="cie-renew-months"><strong>Meses a sumar</strong></label><br>
                    <select name="meses" id="cie-renew-months" required>
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="6">6</option>
                        <option value="12">12</option>
                    </select>
                </p>
                <p>
                    <label for="cie-renew-note"><strong>Nota interna (opcional)</strong></label><br>
                    <textarea id="cie-renew-note" name="nota" rows="3" style="width:100%;"></textarea>
                </p>
                <p>
                    <button class="button button-primary" type="submit">Actualizar periodo</button>
                </p>
            </form>
        </div>
    </div>
    <style>
        #cie-renew-modal { position:fixed; inset:0; z-index:9999; }
        #cie-renew-modal .cie-renew-modal-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.5); }
        #cie-renew-modal .cie-renew-modal-card {
            position:relative;
            z-index:2;
            max-width:540px;
            margin:8vh auto;
            background:#fff;
            border-radius:8px;
            padding:16px;
            box-shadow:0 8px 24px rgba(0,0,0,.18);
        }
    </style>
    <script>
        jQuery(function($){
            function closeModal(){
                $('#cie-renew-modal').hide();
            }

            $(document).on('click', '.cie-open-renew-modal', function(e){
                e.preventDefault();
                var userId = $(this).data('user-id');
                var userName = $(this).data('user-name');
                $('#cie-renew-user-id').val(userId);
                $('#cie-renew-user-label').text('Usuario: ' + userName + ' (ID ' + userId + ')');
                $('#cie-renew-modal').show();
            });

            $(document).on('click', '.cie-renew-close, #cie-renew-modal .cie-renew-modal-backdrop', function(e){
                e.preventDefault();
                closeModal();
            });
        });
    </script>
    <?php

    echo '</div>';
}

/* =====================================================
   CRON AVISO (15 DIAS O MENOS, SOLO ROLES CIE)
===================================================== */

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('cie_cron_aviso')) {
        wp_schedule_event(time(), 'daily', 'cie_cron_aviso');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('cie_cron_aviso');
});

add_action('cie_cron_aviso', function () {
    $users = get_users([
        'role__in' => cie_allowed_roles(),
    ]);

    foreach ($users as $user) {
        $days = cie_get_days_remaining($user->ID);
        if ($days === null) {
            continue;
        }

        if ($days <= CIE_RENEWAL_THRESHOLD_DAYS) {
            if (get_user_meta($user->ID, CIE_NOTICE_META_KEY, true)) {
                continue;
            }

            $period = cie_get_user_period_data($user->ID);
            $end_text = $period ? $period['end']->format('d/m/Y') : 'N/A';
            $subject = ($days < 0)
                ? 'Tu acceso ha caducado'
                : 'Tu acceso caduca en ' . $days . ' dia(s)';

            $message = ($days < 0)
                ? 'Tu acceso caduco el ' . $end_text . '. Solicita tu renovacion.'
                : 'Tu acceso caduca el ' . $end_text . '. Ya puedes solicitar tu renovacion.';

            wp_mail($user->user_email, $subject, $message);
            update_user_meta($user->ID, CIE_NOTICE_META_KEY, 1);
        } else {
            delete_user_meta($user->ID, CIE_NOTICE_META_KEY);
        }
    }
});