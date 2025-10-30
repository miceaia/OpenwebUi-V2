<?php
/**
 * Core functionality for the OpenWebUI User Sync plugin.
 *
 * @package OpenWebUI\UserSync
 */

// ⭐ SEGURIDAD 1: Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class OpenWebUI_User_Sync {
    
    private $api_url;
    private $api_key;
    private static $instance = null;
    private $send_emails;
    private $groups_cache = array();
    private $remote_user_cache = array();
    
    // ⭐ SEGURIDAD 3: Cache de resultados para evitar llamadas duplicadas
    private $api_cache = array();
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // ⭐ SEGURIDAD 4: Sanitizar opciones al cargar
        $api_url_option = get_option('openwebui_api_url', '');
        if (empty($api_url_option)) {
            $api_url_option = get_option('owui_base_url', '');
        }

        $api_key_option = get_option('openwebui_api_key', '');
        if (empty($api_key_option)) {
            $api_key_option = get_option('owui_api_key', '');
        }

        $this->api_url = $this->sanitize_api_url($api_url_option);
        $this->api_key = $this->sanitize_api_key($api_key_option);
        $this->send_emails = sanitize_text_field(get_option('openwebui_send_emails', 'yes'));
        $this->groups_cache = $this->load_groups_cache();
        
        // Hooks de administración
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Enlace de ajustes
        add_filter('plugin_action_links_' . OPENWEBUI_SYNC_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        
        // ⭐ SEGURIDAD 5: AJAX con verificación de permisos
        add_action('wp_ajax_test_openwebui_api', array($this, 'test_api_ajax'));
        add_action('wp_ajax_sync_user_to_openwebui', array($this, 'sync_user_ajax'));
        add_action('wp_ajax_unsync_user_from_openwebui', array($this, 'unsync_user_ajax'));
        add_action('wp_ajax_get_courses_list', array($this, 'get_courses_ajax'));
        add_action('wp_ajax_preview_users_by_date', array($this, 'preview_users_by_date_ajax'));
        add_action('wp_ajax_preview_users_by_course', array($this, 'preview_users_by_course_ajax'));
        add_action('wp_ajax_get_course_tags', array($this, 'get_course_tags_ajax'));
        add_action('wp_ajax_bilateral_sync_users', array($this, 'bilateral_sync_ajax'));
        add_action('wp_ajax_sync_openwebui_groups', array($this, 'sync_openwebui_groups_ajax'));
        add_action('wp_ajax_load_group_panel_courses', array($this, 'load_group_panel_courses_ajax'));
        add_action('wp_ajax_assign_group_members', array($this, 'assign_group_members_ajax'));
        add_action('wp_ajax_remove_group_members', array($this, 'remove_group_members_ajax'));
        add_action('wp_ajax_preview_course_group_panel', array($this, 'preview_course_group_panel_ajax'));

        // Sincronización automática
        add_action('user_register', array($this, 'sync_new_user_immediately'), 10, 1);
        add_action('profile_update', array($this, 'check_password_change'), 10, 2);

        // Sincronización automática de grupos con LearnDash
        add_action('ld_added_group_access', array($this, 'handle_ld_added_group_access'), 10, 2);
        add_action('ld_removed_group_access', array($this, 'handle_ld_removed_group_access'), 10, 2);
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Limpieza automática
        add_action('wp_scheduled_delete', array($this, 'cleanup_old_transients'));
        
        // ⭐ SEGURIDAD 6: Limpieza de logs antiguos
        add_action('wp_scheduled_delete', array($this, 'cleanup_old_logs'));
    }
    
    /**
     * ⭐ SEGURIDAD 7: Prevenir clonación
     */
    private function __clone() {}
    
    /**
     * ⭐ SEGURIDAD 8: Prevenir deserialización
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * ⭐ SEGURIDAD 9: Rate limiting para API
     */
    private function check_rate_limit($user_id = 0) {
        $key = 'openwebui_rate_limit_' . ($user_id ? $user_id : 'global');
        $requests = get_transient($key);
        
        if ($requests === false) {
            set_transient($key, 1, OPENWEBUI_RATE_LIMIT_WINDOW);
            return true;
        }
        
        if ($requests >= OPENWEBUI_MAX_REQUESTS_PER_WINDOW) {
            $this->log_sync($user_id, 'warning', 'Rate limit excedido - bloqueando request');
            return false;
        }
        
        set_transient($key, $requests + 1, OPENWEBUI_RATE_LIMIT_WINDOW);
        return true;
    }
    
    /**
     * Limpiar transients antiguos
     */
    public function cleanup_old_transients() {
        global $wpdb;
        
        // ⭐ SEGURIDAD 10: Limpiar con prepared statement
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            AND option_value < %d",
            $wpdb->esc_like('_transient_syncing_user_') . '%',
            time() - 3600
        ));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_timeout_syncing_user_') . '%'
        ));
    }
    
    /**
     * ⭐ SEGURIDAD 11: Limpiar logs antiguos (más de 30 días)
     */
    public function cleanup_old_logs() {
        $logs = get_option('openwebui_sync_logs', array());

        if (empty($logs)) {
            return;
        }
        
        $cutoff_date = strtotime('-30 days');
        $filtered_logs = array_filter($logs, function($log) use ($cutoff_date) {
            $log_timestamp = strtotime($log['date']);
            return $log_timestamp >= $cutoff_date;
        });
        
        update_option('openwebui_sync_logs', array_values($filtered_logs), false);
    }

    /**
     * Cargar y sanitizar la caché de grupos
     */
    private function load_groups_cache() {
        $cache = get_option('openwebui_groups_cache', array());

        if (!is_array($cache)) {
            return array(
                'groups' => array(),
                'updated_at' => ''
            );
        }

        $groups = array();

        if (isset($cache['groups']) && is_array($cache['groups'])) {
            foreach ($cache['groups'] as $group) {
                $sanitized = $this->sanitize_group_entry($group);
                if (!empty($sanitized)) {
                    $groups[] = $sanitized;
                }
            }
        }

        $updated_at = isset($cache['updated_at']) ? sanitize_text_field($cache['updated_at']) : '';
        $updated_label = '';

        if ($updated_at !== '') {
            $formatted = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $updated_at);
            if (!empty($formatted)) {
                $updated_label = sanitize_text_field($formatted);
            }
        }

        return array(
            'groups' => $groups,
            'updated_at' => $updated_at,
            'updated_label' => $updated_label
        );
    }

    /**
     * Sanitizar entrada de grupo individual
     */
    private function sanitize_group_entry($group) {
        if (!is_array($group)) {
            return array();
        }

        $id = '';
        $id_fields = array('id', '_id', 'uuid', 'slug', 'key');

        foreach ($id_fields as $field) {
            if (isset($group[$field]) && $group[$field] !== '') {
                $id = sanitize_text_field((string) $group[$field]);
                break;
            }
        }

        if ($id === '') {
            return array();
        }

        $name = '';
        $name_fields = array('name', 'title', 'label', 'display_name');

        foreach ($name_fields as $field) {
            if (isset($group[$field]) && $group[$field] !== '') {
                $name = sanitize_text_field($group[$field]);
                break;
            }
        }

        if ($name === '') {
            $name = sprintf(__('Grupo %s', 'openwebui-sync'), $id);
        }

        $description = '';
        $description_fields = array('description', 'details', 'summary');

        foreach ($description_fields as $field) {
            if (isset($group[$field]) && $group[$field] !== '') {
                $description = sanitize_text_field($group[$field]);
                break;
            }
        }

        $member_count = 0;
        $member_fields = array('member_count', 'members', 'users');

        foreach ($member_fields as $field) {
            if (isset($group[$field])) {
                if (is_numeric($group[$field])) {
                    $member_count = absint($group[$field]);
                } elseif (is_array($group[$field])) {
                    $member_count = count($group[$field]);
                }
                break;
            }
        }

        return array(
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'member_count' => $member_count
        );
    }

    /**
     * Obtener los cursos con su estado de mapeo para el panel de grupos.
     */
    private function get_group_panel_courses() {
        if (!defined('LEARNDASH_VERSION') && !post_type_exists('sfwd-courses')) {
            return array();
        }

        $course_ids = get_posts(array(
            'post_type' => 'sfwd-courses',
            'post_status' => array('publish', 'draft', 'pending'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids'
        ));

        if (empty($course_ids)) {
            return array();
        }

        $groups_lookup = array();
        if (!empty($this->groups_cache['groups'])) {
            foreach ($this->groups_cache['groups'] as $group) {
                if (!empty($group['id']) && !empty($group['name'])) {
                    $groups_lookup[(string) $group['id']] = $group['name'];
                }
            }
        }

        $courses = array();

        foreach ($course_ids as $course_id) {
            $course_id = absint($course_id);
            $raw_title = get_the_title($course_id);
            $title = $raw_title ? wp_strip_all_tags($raw_title) : '';
            $group_id = get_post_meta($course_id, 'owui_group_id', true);
            $group_id = is_string($group_id) ? sanitize_text_field($group_id) : '';
            $group_label = '';

            if ($group_id !== '' && isset($groups_lookup[$group_id])) {
                $group_label = $groups_lookup[$group_id];
            }

            $enrolled = $this->count_course_users($course_id);
            $edit_link = get_edit_post_link($course_id, 'raw');

            $courses[] = array(
                'id' => $course_id,
                'title' => sanitize_text_field($title),
                'group_id' => $group_id,
                'group_label' => $group_label ? sanitize_text_field($group_label) : '',
                'enrolled' => absint($enrolled),
                'enrolled_label' => sanitize_text_field(sprintf(
                    /* translators: %d: number of enrolled users */
                    _n('%d alumno', '%d alumnos', $enrolled, 'openwebui-sync'),
                    $enrolled
                )),
                'edit_url' => $edit_link ? esc_url_raw($edit_link) : '',
                'search' => strtolower(sanitize_text_field($title))
            );
        }

        return $courses;
    }

    /**
     * Resolver el identificador de OpenWebUI para un email determinado.
     */
    private function get_openwebui_user_id_by_email($email) {
        $email = strtolower(sanitize_email($email));

        if (empty($email)) {
            return 0;
        }

        if (isset($this->remote_user_cache[$email])) {
            return $this->remote_user_cache[$email];
        }

        $endpoints = array(
            '/api/v1/users?email=%s',
            '/api/users?email=%s',
            '/users?email=%s'
        );

        foreach ($endpoints as $pattern) {
            $endpoint = sprintf($pattern, rawurlencode($email));
            $response = $this->call_openwebui_api_get($endpoint);

            if (!$response['success']) {
                continue;
            }

            $user_node = $this->extract_user_from_payload($response['data']);

            if (!empty($user_node)) {
                $user_id = $this->resolve_user_id_from_node($user_node);
                if ($user_id) {
                    $this->remote_user_cache[$email] = $user_id;
                    return $user_id;
                }
            }
        }

        $this->remote_user_cache[$email] = 0;
        return 0;
    }

    /**
     * Extraer un nodo de usuario desde estructuras anidadas.
     */
    private function extract_user_from_payload($payload) {
        if (!is_array($payload)) {
            return array();
        }

        if ($this->resolve_user_id_from_node($payload)) {
            return $payload;
        }

        $candidate_keys = array('data', 'user', 'users', 'items', 'results');

        foreach ($candidate_keys as $key) {
            if (isset($payload[$key])) {
                $candidate = $this->extract_user_from_payload($payload[$key]);
                if (!empty($candidate)) {
                    return $candidate;
                }
            }
        }

        if (array_values($payload) === $payload) {
            foreach ($payload as $value) {
                if (is_array($value)) {
                    $candidate = $this->extract_user_from_payload($value);
                    if (!empty($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return array();
    }

    /**
     * Determinar el identificador del usuario desde distintos formatos de respuesta.
     */
    private function resolve_user_id_from_node($node) {
        if (!is_array($node)) {
            return 0;
        }

        $fields = array('id', '_id', 'userId', 'uuid');

        foreach ($fields as $field) {
            if (isset($node[$field]) && '' !== $node[$field]) {
                if (is_numeric($node[$field])) {
                    return (int) $node[$field];
                }

                $value = sanitize_text_field((string) $node[$field]);
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return 0;
    }

    /**
     * Buscar grupo por ID en caché
     */
    private function find_group_by_id($group_id) {
        if (empty($group_id) || empty($this->groups_cache['groups'])) {
            return null;
        }

        foreach ($this->groups_cache['groups'] as $group) {
            if ((string) $group['id'] === (string) $group_id) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Buscar un grupo por nombre dentro de la caché local.
     */
    private function find_group_by_name($group_name) {
        if (empty($group_name) || empty($this->groups_cache['groups'])) {
            return null;
        }

        foreach ($this->groups_cache['groups'] as $group) {
            if (!isset($group['name'])) {
                continue;
            }

            if (0 === strcasecmp((string) $group['name'], (string) $group_name)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Refrescar la caché de grupos desde la API de OpenWebUI.
     */
    private function refresh_groups_cache_from_api() {
        $result = $this->fetch_openwebui_groups_from_api();

        if (!$result['success']) {
            return $result;
        }

        $groups = isset($result['groups']) && is_array($result['groups']) ? $result['groups'] : array();
        $updated_at = current_time('mysql');
        $updated_label = '';

        if (!empty($updated_at)) {
            $formatted = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $updated_at);
            if (!empty($formatted)) {
                $updated_label = sanitize_text_field($formatted);
            }
        }

        $this->groups_cache = array(
            'groups' => $groups,
            'updated_at' => $updated_at,
            'updated_label' => $updated_label,
        );

        update_option('openwebui_groups_cache', $this->groups_cache, false);

        $response = array(
            'success' => true,
            'groups' => $groups,
        );

        if (!empty($result['notice'])) {
            $response['notice'] = $result['notice'];
        }

        return $response;
    }

    /**
     * Obtener un grupo de OpenWebUI por nombre, creando si es necesario.
     *
     * @param string $group_name Nombre del grupo.
     * @param bool   $create_if_missing Crear el grupo si no existe.
     * @return array
     */
    private function get_openwebui_group_by_name($group_name, $create_if_missing = false) {
        $group_name = trim((string) $group_name);

        if ($group_name === '') {
            return array(
                'success' => false,
                'message' => esc_html__('Nombre de grupo inválido.', 'openwebui-sync'),
            );
        }

        $cached_group = $this->find_group_by_name($group_name);

        if ($cached_group) {
            return array(
                'success' => true,
                'group' => $cached_group,
            );
        }

        $refreshed = $this->refresh_groups_cache_from_api();
        if (!$refreshed['success']) {
            return $refreshed;
        }

        $cached_group = $this->find_group_by_name($group_name);
        if ($cached_group) {
            return array(
                'success' => true,
                'group' => $cached_group,
            );
        }

        if (!$create_if_missing) {
            return array(
                'success' => true,
                'group' => null,
                'message' => esc_html__('El grupo no existe en OpenWebUI.', 'openwebui-sync'),
            );
        }

        $created = $this->create_openwebui_group($group_name);
        if (!$created['success']) {
            return $created;
        }

        $refreshed = $this->refresh_groups_cache_from_api();
        if (!$refreshed['success']) {
            return $refreshed;
        }

        $cached_group = $this->find_group_by_name($group_name);
        if ($cached_group) {
            return array(
                'success' => true,
                'group' => $cached_group,
            );
        }

        return array(
            'success' => false,
            'message' => esc_html__('El grupo se creó pero no se pudo confirmar en la caché local.', 'openwebui-sync'),
        );
    }

    /**
     * Crear un grupo de OpenWebUI a partir del nombre indicado.
     */
    private function create_openwebui_group($group_name) {
        $group_name = sanitize_text_field($group_name);

        if ($group_name === '') {
            return array(
                'success' => false,
                'message' => esc_html__('Nombre de grupo inválido.', 'openwebui-sync'),
            );
        }

        $payloads = array(
            array('name' => $group_name),
            array('title' => $group_name),
            array('group' => array('name' => $group_name)),
        );

        $endpoints = array(
            '/api/v1/groups',
            '/api/groups',
            '/groups',
            '/api/v1/admin/groups',
        );

        $last_error = esc_html__('No se pudo crear el grupo en OpenWebUI.', 'openwebui-sync');

        foreach ($endpoints as $endpoint) {
            foreach ($payloads as $payload) {
                $result = $this->call_openwebui_api($endpoint, $payload);

                if ($result['success']) {
                    return array('success' => true, 'data' => isset($result['data']) ? $result['data'] : array());
                }

                if (!empty($result['message'])) {
                    $last_error = $result['message'];
                }
            }
        }

        return array(
            'success' => false,
            'message' => $last_error,
        );
    }

    /**
     * Añadir un usuario remoto a un grupo de OpenWebUI.
     */
    private function add_remote_user_to_openwebui_group($remote_user_id, $remote_group_id) {
        $remote_user_id = (string) $remote_user_id;
        $remote_group_id = (string) $remote_group_id;

        if ($remote_user_id === '' || $remote_group_id === '') {
            return array(
                'success' => false,
                'message' => esc_html__('Datos inválidos para la sincronización de grupo.', 'openwebui-sync'),
            );
        }

        $payloads = array(
            array('userId' => $remote_user_id),
            array('user_id' => $remote_user_id),
            array('userIds' => array($remote_user_id)),
            array('users' => array($remote_user_id)),
            array('members' => array(array('userId' => $remote_user_id))),
        );

        $endpoints = array(
            '/api/v1/groups/%s/members',
            '/api/groups/%s/members',
            '/groups/%s/members',
            '/api/v1/groups/%s/users',
            '/api/groups/%s/users',
        );

        $last_error = esc_html__('No se pudo añadir el usuario al grupo de OpenWebUI.', 'openwebui-sync');

        foreach ($endpoints as $endpoint) {
            $formatted_endpoint = sprintf($endpoint, rawurlencode($remote_group_id));

            foreach ($payloads as $payload) {
                $result = $this->call_openwebui_api($formatted_endpoint, $payload);

                if ($result['success']) {
                    return array('success' => true);
                }

                if (!empty($result['message']) && $this->is_duplicate_group_message($result['message'])) {
                    return array(
                        'success' => true,
                        'duplicate' => true,
                    );
                }

                if (!empty($result['message'])) {
                    $last_error = $result['message'];
                }
            }
        }

        return array(
            'success' => false,
            'message' => $last_error,
        );
    }

    /**
     * Eliminar un usuario remoto de un grupo de OpenWebUI.
     */
    private function remove_remote_user_from_openwebui_group($remote_user_id, $remote_group_id) {
        $remote_user_id = (string) $remote_user_id;
        $remote_group_id = (string) $remote_group_id;

        if ($remote_user_id === '' || $remote_group_id === '') {
            return array(
                'success' => false,
                'message' => esc_html__('Datos inválidos para la sincronización de grupo.', 'openwebui-sync'),
            );
        }

        $endpoints = array(
            '/api/v1/groups/%1$s/members/%2$s',
            '/api/groups/%1$s/members/%2$s',
            '/groups/%1$s/members/%2$s',
            '/api/v1/groups/%1$s/users/%2$s',
            '/api/groups/%1$s/users/%2$s',
        );

        $last_error = esc_html__('No se pudo eliminar el usuario del grupo de OpenWebUI.', 'openwebui-sync');

        foreach ($endpoints as $endpoint) {
            $formatted_endpoint = sprintf(
                $endpoint,
                rawurlencode($remote_group_id),
                rawurlencode($remote_user_id)
            );

            $result = $this->call_openwebui_api_delete($formatted_endpoint);

            if ($result['success']) {
                return array('success' => true);
            }

            if (isset($result['code']) && (int) $result['code'] === 404) {
                return array(
                    'success' => true,
                    'missing' => true,
                );
            }

            if (!empty($result['message'])) {
                $last_error = $result['message'];
            }
        }

        return array(
            'success' => false,
            'message' => $last_error,
        );
    }

    /**
     * Manejar la asignación automática de grupos de LearnDash en OpenWebUI.
     */
    public function handle_ld_added_group_access($user_id, $group_id) {
        $user_id = absint($user_id);
        $group_id = absint($group_id);

        if (!$user_id || !$group_id) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $group_post = get_post($group_id);
        if (!$group_post instanceof WP_Post) {
            return;
        }

        $group_name = trim(wp_strip_all_tags($group_post->post_title));

        if ($group_name === '') {
            $this->log_sync($user_id, 'warning', esc_html__('El grupo de LearnDash no tiene un nombre válido.', 'openwebui-sync'));
            return;
        }

        if (empty($this->api_url) || empty($this->api_key)) {
            $this->log_sync($user_id, 'warning', esc_html__('Sincronización de grupos omitida: la API de OpenWebUI no está configurada.', 'openwebui-sync'));
            return;
        }

        $remote_user_id = get_user_meta($user_id, '_openwebui_user_id', true);

        if (empty($remote_user_id)) {
            $remote_user_id = $this->get_openwebui_user_id_by_email($user->user_email);

            if (!empty($remote_user_id)) {
                update_user_meta($user_id, '_openwebui_user_id', sanitize_text_field((string) $remote_user_id));
            }
        }

        if (empty($remote_user_id)) {
            $this->log_sync($user_id, 'warning', sprintf(
                esc_html__('El usuario no tiene un ID válido de OpenWebUI para sincronizar con el grupo "%s".', 'openwebui-sync'),
                sanitize_text_field($group_name)
            ));
            return;
        }

        $group_lookup = $this->get_openwebui_group_by_name($group_name, true);

        if (empty($group_lookup['success'])) {
            $message = isset($group_lookup['message']) ? $group_lookup['message'] : esc_html__('Error desconocido al preparar el grupo remoto.', 'openwebui-sync');
            $this->log_sync($user_id, 'error', sprintf(
                esc_html__('No se pudo preparar el grupo "%1$s" en OpenWebUI: %2$s', 'openwebui-sync'),
                sanitize_text_field($group_name),
                sanitize_text_field((string) $message)
            ));
            return;
        }

        if (empty($group_lookup['group']) || empty($group_lookup['group']['id'])) {
            $message = isset($group_lookup['message']) ? $group_lookup['message'] : esc_html__('No se recibió información del grupo remoto.', 'openwebui-sync');
            $this->log_sync($user_id, 'error', sprintf(
                esc_html__('No se pudo localizar el grupo "%1$s" en OpenWebUI: %2$s', 'openwebui-sync'),
                sanitize_text_field($group_name),
                sanitize_text_field((string) $message)
            ));
            return;
        }

        $remote_group_id = $group_lookup['group']['id'];

        $assignment = $this->add_remote_user_to_openwebui_group($remote_user_id, $remote_group_id);

        if (empty($assignment['success'])) {
            $message = isset($assignment['message']) ? $assignment['message'] : esc_html__('Error desconocido.', 'openwebui-sync');
            $this->log_sync($user_id, 'error', sprintf(
                esc_html__('Error al añadir al usuario al grupo "%1$s": %2$s', 'openwebui-sync'),
                sanitize_text_field($group_name),
                sanitize_text_field((string) $message)
            ));
            return;
        }

        if (!empty($assignment['duplicate'])) {
            $this->log_sync($user_id, 'info', sprintf(
                esc_html__('El usuario ya pertenecía al grupo "%s" en OpenWebUI.', 'openwebui-sync'),
                sanitize_text_field($group_name)
            ));
            return;
        }

        $this->log_sync($user_id, 'info', sprintf(
            esc_html__('Usuario sincronizado con el grupo "%s" en OpenWebUI.', 'openwebui-sync'),
            sanitize_text_field($group_name)
        ));
    }

    /**
     * Manejar la baja automática de grupos de LearnDash en OpenWebUI.
     */
    public function handle_ld_removed_group_access($user_id, $group_id) {
        $user_id = absint($user_id);
        $group_id = absint($group_id);

        if (!$user_id || !$group_id) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $group_post = get_post($group_id);
        if (!$group_post instanceof WP_Post) {
            return;
        }

        $group_name = trim(wp_strip_all_tags($group_post->post_title));

        if ($group_name === '') {
            $this->log_sync($user_id, 'warning', esc_html__('El grupo de LearnDash no tiene un nombre válido.', 'openwebui-sync'));
            return;
        }

        if (empty($this->api_url) || empty($this->api_key)) {
            $this->log_sync($user_id, 'warning', esc_html__('Sincronización de grupos omitida: la API de OpenWebUI no está configurada.', 'openwebui-sync'));
            return;
        }

        $remote_user_id = get_user_meta($user_id, '_openwebui_user_id', true);

        if (empty($remote_user_id)) {
            $remote_user_id = $this->get_openwebui_user_id_by_email($user->user_email);

            if (!empty($remote_user_id)) {
                update_user_meta($user_id, '_openwebui_user_id', sanitize_text_field((string) $remote_user_id));
            }
        }

        if (empty($remote_user_id)) {
            $this->log_sync($user_id, 'info', sprintf(
                esc_html__('El usuario no tiene un ID de OpenWebUI, se omite la baja en el grupo "%s".', 'openwebui-sync'),
                sanitize_text_field($group_name)
            ));
            return;
        }

        $group_lookup = $this->get_openwebui_group_by_name($group_name, false);

        if (empty($group_lookup['success'])) {
            $message = isset($group_lookup['message']) ? $group_lookup['message'] : esc_html__('Error desconocido al buscar el grupo remoto.', 'openwebui-sync');
            $this->log_sync($user_id, 'error', sprintf(
                esc_html__('No se pudo localizar el grupo "%1$s" en OpenWebUI: %2$s', 'openwebui-sync'),
                sanitize_text_field($group_name),
                sanitize_text_field((string) $message)
            ));
            return;
        }

        if (empty($group_lookup['group']) || empty($group_lookup['group']['id'])) {
            $this->log_sync($user_id, 'info', sprintf(
                esc_html__('El grupo "%s" no existe en OpenWebUI, no se realizaron cambios.', 'openwebui-sync'),
                sanitize_text_field($group_name)
            ));
            return;
        }

        $remote_group_id = $group_lookup['group']['id'];

        $removal = $this->remove_remote_user_from_openwebui_group($remote_user_id, $remote_group_id);

        if (empty($removal['success'])) {
            $message = isset($removal['message']) ? $removal['message'] : esc_html__('Error desconocido.', 'openwebui-sync');
            $this->log_sync($user_id, 'error', sprintf(
                esc_html__('Error al eliminar al usuario del grupo "%1$s": %2$s', 'openwebui-sync'),
                sanitize_text_field($group_name),
                sanitize_text_field((string) $message)
            ));
            return;
        }

        if (!empty($removal['missing'])) {
            $this->log_sync($user_id, 'info', sprintf(
                esc_html__('El usuario no pertenecía al grupo "%s" en OpenWebUI.', 'openwebui-sync'),
                sanitize_text_field($group_name)
            ));
            return;
        }

        $this->log_sync($user_id, 'info', sprintf(
            esc_html__('Usuario eliminado del grupo "%s" en OpenWebUI.', 'openwebui-sync'),
            sanitize_text_field($group_name)
        ));
    }
    
    /**
     * Añadir enlace de ajustes
     */
    public function add_settings_link($links) {
        // ⭐ SEGURIDAD 12: Escapar URL
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=openwebui-sync')) . '">' . 
                        esc_html__('Ajustes', 'openwebui-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Mostrar avisos administrativos
     */
    public function admin_notices() {
        // ⭐ SEGURIDAD 13: Validar $_GET con filter_input
        $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_STRING);
        
        if (empty($this->api_url) && $page === 'openwebui-sync') {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html__('OpenWebUI Sync:', 'openwebui-sync') . '</strong> ' . 
                 esc_html__('Por favor, configura la URL de la API para comenzar a sincronizar usuarios.', 'openwebui-sync') . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Cargar scripts de administración
     */
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_openwebui-sync' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'openwebui-sync-admin',
            OPENWEBUI_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            OPENWEBUI_SYNC_VERSION
        );

        wp_enqueue_script(
            'openwebui-sync-admin',
            OPENWEBUI_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            OPENWEBUI_SYNC_VERSION,
            true
        );

        // ⭐ SEGURIDAD 14: Nonce único por sesión
        wp_localize_script('openwebui-sync-admin', 'openwebuiData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('openwebui_sync_nonce'),
            'groups' => $this->groups_cache['groups'],
            'groupsUpdated' => $this->groups_cache['updated_at'],
            'groupsUpdatedLabel' => $this->groups_cache['updated_label'],
            'groupPanel' => array(
                'courses' => $this->get_group_panel_courses(),
                'autoRefreshGroups' => empty($this->groups_cache['groups'])
            ),
            'strings' => array(
                'testing' => esc_html__('Probando conexión...', 'openwebui-sync'),
                'syncing' => esc_html__('Sincronizando...', 'openwebui-sync'),
                'success' => esc_html__('Éxito', 'openwebui-sync'),
                'error' => esc_html__('Error', 'openwebui-sync'),
                'loading' => esc_html__('Cargando...', 'openwebui-sync'),
                'confirm_sync' => esc_html__('¿Confirmar sincronización de estos usuarios?', 'openwebui-sync'),
                'confirm_unsync' => esc_html__('¿Estás seguro de que deseas desmarcar este usuario como sincronizado?', 'openwebui-sync'),
                'select_dates' => esc_html__('Por favor, selecciona ambas fechas', 'openwebui-sync'),
                'select_course' => esc_html__('Por favor, selecciona un curso', 'openwebui-sync'),
                'all_tags' => esc_html__('🏷️ Todas las etiquetas', 'openwebui-sync'),
                'groups_refreshing' => esc_html__('Sincronizando grupos...', 'openwebui-sync'),
                'groups_refreshed' => esc_html__('Grupos actualizados correctamente.', 'openwebui-sync'),
                'groups_refreshed_count' => esc_html__('Se recuperaron %d grupos.', 'openwebui-sync'),
                'groups_refresh_error' => esc_html__('No se pudieron actualizar los grupos.', 'openwebui-sync'),
                'group_sync_required' => esc_html__('⚠️ Primero sincroniza los grupos de OpenWebUI para habilitar esta acción.', 'openwebui-sync'),
                'select_group' => esc_html__('Selecciona un grupo', 'openwebui-sync'),
                'assign_group' => esc_html__('Añadir usuarios al grupo', 'openwebui-sync'),
                'assigning_group' => esc_html__('Asignando grupo...', 'openwebui-sync'),
                'confirm_group_assignment' => esc_html__('¿Deseas añadir estos usuarios al grupo seleccionado?', 'openwebui-sync'),
                'group_assignment_error' => esc_html__('Error al asignar el grupo', 'openwebui-sync'),
                'group_assignment_complete' => esc_html__('Asignación de grupo completada', 'openwebui-sync'),
                'group_assignment_skipped' => esc_html__('Usuarios omitidos por no estar sincronizados', 'openwebui-sync'),
                'group_assignment_already' => esc_html__('Usuarios que ya estaban en el grupo', 'openwebui-sync'),
                'group_assignment_added' => esc_html__('Usuarios añadidos al grupo', 'openwebui-sync'),
                'group_assignment_failed' => esc_html__('Usuarios con errores al asignar el grupo', 'openwebui-sync'),
                'panel_course_placeholder' => esc_html__('Selecciona un curso para comenzar.', 'openwebui-sync'),
                'panel_group_placeholder' => esc_html__('Selecciona un grupo para comenzar.', 'openwebui-sync'),
                'panel_preview_placeholder' => esc_html__('Selecciona un curso y un grupo para previsualizar la sincronización.', 'openwebui-sync'),
                'panel_no_courses' => esc_html__('No se encontraron cursos de LearnDash.', 'openwebui-sync'),
                'panel_no_groups' => esc_html__('Sin grupos sincronizados. Usa “Actualizar lista de grupos”.', 'openwebui-sync'),
                'panel_course_group_none' => esc_html__('— Sin asignar —', 'openwebui-sync'),
                'panel_preview_loading' => esc_html__('Cargando previsualización...', 'openwebui-sync'),
                'panel_preview_empty' => esc_html__('No se encontraron usuarios matriculados en este curso.', 'openwebui-sync'),
                'panel_users_total' => esc_html__('Total matriculados', 'openwebui-sync'),
                'panel_users_synced' => esc_html__('Marcados como sincronizados', 'openwebui-sync'),
                'panel_users_pending' => esc_html__('Pendientes en OpenWebUI', 'openwebui-sync'),
                'panel_sync_note' => esc_html__('Solo se procesarán los usuarios marcados como sincronizados en WordPress.', 'openwebui-sync'),
                'panel_action_add' => esc_html__('Añadir alumnos al grupo', 'openwebui-sync'),
                'panel_action_remove' => esc_html__('Quitar alumnos del grupo', 'openwebui-sync'),
                'panel_action_processing' => esc_html__('Procesando...', 'openwebui-sync'),
                'panel_remove_confirm' => esc_html__('¿Quieres quitar a estos usuarios del grupo seleccionado?', 'openwebui-sync'),
                'panel_preview_more' => esc_html__('Mostrando una vista previa de los primeros %d usuarios.', 'openwebui-sync'),
                'panel_user_synced' => esc_html__('Sincronizado', 'openwebui-sync'),
                'panel_user_pending' => esc_html__('Pendiente', 'openwebui-sync'),
                'group_removal_complete' => esc_html__('Eliminación del grupo completada', 'openwebui-sync'),
                'group_removal_removed' => esc_html__('Usuarios eliminados del grupo', 'openwebui-sync'),
                'group_removal_missing' => esc_html__('Usuarios no encontrados en OpenWebUI', 'openwebui-sync'),
                'group_removal_failed' => esc_html__('Usuarios con errores al eliminar del grupo', 'openwebui-sync'),
                'panel_refresh_empty' => esc_html__('La llamada fue correcta pero no se recibieron grupos desde OpenWebUI.', 'openwebui-sync'),
                'panel_edit_course' => esc_html__('Editar curso', 'openwebui-sync'),
                'panel_loading_courses' => esc_html__('Cargando cursos…', 'openwebui-sync'),
                'panel_courses_error' => esc_html__('No se pudieron cargar los cursos de LearnDash.', 'openwebui-sync'),
                'panel_loading_groups' => esc_html__('Cargando grupos…', 'openwebui-sync'),
                'panel_groups_error' => esc_html__('No se pudieron cargar los grupos de OpenWebUI.', 'openwebui-sync'),
                'panel_refreshing_groups' => esc_html__('Sincronizando grupos…', 'openwebui-sync'),
                'panel_groups_updated_at' => esc_html__('Última actualización: %s', 'openwebui-sync'),
                'panel_groups_never' => esc_html__('Aún no se han sincronizado grupos.', 'openwebui-sync'),
                'panel_table_user' => esc_html__('Usuario', 'openwebui-sync'),
                'panel_table_email' => esc_html__('Email', 'openwebui-sync'),
                'panel_table_status' => esc_html__('Estado', 'openwebui-sync')
            )
        ));
    }

    
    /**
     * Añadir menú de administración
     */
    public function add_admin_menu() {
        add_options_page(
            esc_html__('OpenWebUI Sync', 'openwebui-sync'),
            esc_html__('OpenWebUI Sync', 'openwebui-sync'),
            'manage_options',
            'openwebui-sync',
            array($this, 'admin_page')
        );
    }
    
    /**
     * ⭐ SEGURIDAD 16: Registrar configuraciones con validación
     */
    public function register_settings() {
        register_setting('openwebui_settings', 'openwebui_api_url', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_api_url'),
            'default' => ''
        ));
        
        register_setting('openwebui_settings', 'openwebui_api_key', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_api_key'),
            'default' => ''
        ));
        
        register_setting('openwebui_settings', 'openwebui_send_emails', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => 'yes'
        ));
    }
    
    /**
     * ⭐ SEGURIDAD 17: Sanitizar URL de API
     */
    public function sanitize_api_url($url) {
        $url = esc_url_raw(trim($url));
        
        // Validar que sea una URL válida con https
        if (!empty($url)) {
            $parsed = parse_url($url);
            if (!isset($parsed['scheme']) || $parsed['scheme'] !== 'https') {
                add_settings_error(
                    'openwebui_api_url',
                    'invalid_url',
                    __('La URL debe usar HTTPS para mayor seguridad.', 'openwebui-sync'),
                    'warning'
                );
                // Convertir a https si es http
                if (isset($parsed['scheme']) && $parsed['scheme'] === 'http') {
                    $url = str_replace('http://', 'https://', $url);
                }
            }
        }
        
        return rtrim($url, '/');
    }
    
    /**
     * ⭐ SEGURIDAD 18: Sanitizar API key
     */
    public function sanitize_api_key($key) {
        // Solo permitir caracteres alfanuméricos, guiones y guiones bajos
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
    }
    
    /**
     * ⭐ SEGURIDAD 19: Sanitizar checkbox
     */
    public function sanitize_checkbox($value) {
        return ($value === 'yes' || $value === '1' || $value === 'on') ? 'yes' : 'no';
    }
    
    /**
     * ⭐ SEGURIDAD 20: AJAX con verificación estricta
     */
    public function test_api_ajax() {
        // Verificar nonce
        check_ajax_referer('openwebui_sync_nonce', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        // Verificar rate limit
        if (!$this->check_rate_limit()) {
            wp_send_json_error(esc_html__('Demasiadas solicitudes. Intenta de nuevo en un minuto.', 'openwebui-sync'));
        }
        
        if (empty($this->api_url)) {
            wp_send_json_error(esc_html__('Configura la URL de la API primero', 'openwebui-sync'));
        }
        
        $test_data = array(
            'username' => 'test_' . wp_generate_password(8, false),
            'email' => 'test_' . time() . '@example.com',
            'password' => 'educarescuidar',
            'name' => 'Test User'
        );
        
        $endpoints = array('/api/v1/auths/signup', '/api/auths/signup', '/auth/register', '/api/auth/register');
        
        foreach ($endpoints as $endpoint) {
            $result = $this->call_openwebui_api($endpoint, $test_data);
            if ($result['success']) {
                wp_send_json_success(sprintf(esc_html__('Conexión exitosa (endpoint: %s)', 'openwebui-sync'), esc_html($endpoint)));
            }
        }
        
        wp_send_json_error(esc_html__('No se pudo conectar. Verifica la URL y que OpenWebUI esté accesible.', 'openwebui-sync'));
    }
    
    /**
     * ⭐ SEGURIDAD 21: Sincronizar usuarios con validación mejorada
     */
    public function sync_user_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        // Verificar rate limit
        if (!$this->check_rate_limit()) {
            wp_send_json_error(esc_html__('Demasiadas solicitudes. Intenta de nuevo en un minuto.', 'openwebui-sync'));
        }
        
        // Sincronización masiva
        if (isset($_POST['sync_all']) && $_POST['sync_all'] == 'true') {
            $filter_type = isset($_POST['filter_type']) ? sanitize_text_field($_POST['filter_type']) : '';
            $filter_value = isset($_POST['filter_value']) ? $_POST['filter_value'] : '';
            
            $unsynced_users = $this->get_filtered_users($filter_type, $filter_value);
            $processed = 0;
            $synced = 0;
            $errors = 0;
            
            foreach ($unsynced_users as $user) {
                $result = $this->sync_user($user->ID);
                $processed++;
                
                if ($result['success']) {
                    $synced++;
                } else {
                    $errors++;
                }
                
                // Pausa cada 3 usuarios
                if ($processed % 3 == 0 && $processed < count($unsynced_users)) {
                    sleep(1);
                }
                
                // Detener si hay demasiados errores consecutivos
                if ($errors > 5) {
                    wp_send_json_error(esc_html__('Demasiados errores. Verifica la configuración de la API.', 'openwebui-sync'));
                }
            }
            
            wp_send_json_success(array(
                'message' => esc_html__('Sincronización completada', 'openwebui-sync'),
                'processed' => $processed,
                'synced' => $synced,
                'errors' => $errors
            ));
        }
        
        // Sincronización individual
        if (isset($_POST['user_id'])) {
            $user_id = absint($_POST['user_id']);
            
            if (!$user_id) {
                wp_send_json_error(esc_html__('ID de usuario no válido', 'openwebui-sync'));
            }
            
            $result = $this->sync_user($user_id);
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
        }
        
        wp_send_json_error(esc_html__('Solicitud inválida', 'openwebui-sync'));
    }
    
    /**
     * ⭐ SEGURIDAD 22: Desmarcar usuario con validación
     */
    public function unsync_user_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(esc_html__('ID de usuario no válido', 'openwebui-sync'));
        }
        
        // Verificar que el usuario existe
        if (!get_userdata($user_id)) {
            wp_send_json_error(esc_html__('Usuario no encontrado', 'openwebui-sync'));
        }
        
        delete_user_meta($user_id, '_openwebui_synced');
        delete_user_meta($user_id, '_openwebui_sync_date');
        delete_user_meta($user_id, '_openwebui_sync_attempts');
        
        wp_send_json_success(esc_html__('Usuario desmarcado correctamente', 'openwebui-sync'));
    }
    
    /**
     * ⭐ SEGURIDAD 23: Prevenir ataques de inyección SQL
     */
    public function get_courses_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        $courses = array();
        
        // LearnDash
        if (defined('LEARNDASH_VERSION') || post_type_exists('sfwd-courses')) {
            $ld_courses = get_posts(array(
                'post_type' => 'sfwd-courses',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC',
                'no_found_rows' => true, // ⭐ Optimización
                'update_post_meta_cache' => false, // ⭐ Optimización
                'update_post_term_cache' => true
            ));
            
            foreach ($ld_courses as $course) {
                $enrolled_count = $this->count_course_users($course->ID);
                $tag_ids = array();
                
                $course_tags = get_the_terms($course->ID, 'ld_course_tag');
                if ($course_tags && !is_wp_error($course_tags)) {
                    foreach ($course_tags as $tag) {
                        $tag_ids[] = 'ld_' . absint($tag->term_id);
                    }
                }
                
                $general_tags = get_the_terms($course->ID, 'post_tag');
                if ($general_tags && !is_wp_error($general_tags)) {
                    foreach ($general_tags as $tag) {
                        $tag_ids[] = 'general_' . absint($tag->term_id);
                    }
                }
                
                $courses[] = array(
                    'id' => absint($course->ID),
                    'title' => esc_html($course->post_title) . ' (' . absint($enrolled_count) . ' usuarios)',
                    'type' => 'learndash',
                    'tags' => implode(',', array_map('esc_attr', $tag_ids)),
                    'search_text' => strtolower(sanitize_text_field($course->post_title))
                );
            }
        }
        
        // LifterLMS
        if (class_exists('LifterLMS')) {
            $llms_courses = get_posts(array(
                'post_type' => 'course',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false
            ));
            
            foreach ($llms_courses as $course) {
                $enrolled_count = $this->count_course_users($course->ID);
                $courses[] = array(
                    'id' => absint($course->ID),
                    'title' => esc_html($course->post_title) . ' (' . absint($enrolled_count) . ' usuarios)',
                    'type' => 'lifterlms',
                    'tags' => '',
                    'search_text' => strtolower(sanitize_text_field($course->post_title))
                );
            }
        }
        
        // Tutor LMS
        if (function_exists('tutor')) {
            $tutor_courses = get_posts(array(
                'post_type' => 'courses',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false
            ));
            
            foreach ($tutor_courses as $course) {
                $enrolled_count = $this->count_course_users($course->ID);
                $courses[] = array(
                    'id' => absint($course->ID),
                    'title' => esc_html($course->post_title) . ' (' . absint($enrolled_count) . ' usuarios)',
                    'type' => 'tutor',
                    'tags' => '',
                    'search_text' => strtolower(sanitize_text_field($course->post_title))
                );
            }
        }
        
        wp_send_json_success($courses);
    }
    
    /**
     * ⭐ SEGURIDAD 24: Validación de fechas
     */
    public function preview_users_by_date_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        
        // ⭐ Validar formato de fecha
        if (!$this->validate_date($date_from) || !$this->validate_date($date_to)) {
            wp_send_json_error(esc_html__('Formato de fecha inválido', 'openwebui-sync'));
        }
        
        // ⭐ Validar que date_from sea anterior a date_to
        if (strtotime($date_from) > strtotime($date_to)) {
            wp_send_json_error(esc_html__('La fecha de inicio debe ser anterior a la fecha de fin', 'openwebui-sync'));
        }
        
        $args = array(
            'meta_query' => array(
                array(
                    'key' => '_openwebui_synced',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'date_query' => array(
                'after' => $date_from,
                'before' => $date_to,
                'inclusive' => true
            ),
            'number' => 1000, // ⭐ Límite de seguridad
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered')
        );
        
        $users = get_users($args);
        $users_data = array();
        
        foreach ($users as $user) {
            $users_data[] = array(
                'id' => absint($user->ID),
                'login' => esc_html($user->user_login),
                'email' => esc_html($user->user_email),
                'registered' => esc_html(mysql2date('d/m/Y', $user->user_registered))
            );
        }
        
        wp_send_json_success(array(
            'users' => $users_data,
            'count' => count($users_data)
        ));
    }
    
    /**
     * ⭐ SEGURIDAD 25: Validador de fechas
     */
    private function validate_date($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
    
    /**
     * Obtener tags de cursos
     */
    public function get_course_tags_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        $tags = array();
        
        if (defined('LEARNDASH_VERSION') || post_type_exists('sfwd-courses')) {
            $course_tags = get_terms(array(
                'taxonomy' => 'ld_course_tag',
                'hide_empty' => false,
                'number' => 100 // ⭐ Límite de seguridad
            ));
            
            if (!is_wp_error($course_tags) && !empty($course_tags)) {
                foreach ($course_tags as $tag) {
                    $tags[] = array(
                        'id' => 'ld_' . absint($tag->term_id),
                        'name' => esc_html($tag->name),
                        'count' => absint($tag->count),
                        'slug' => esc_attr($tag->slug),
                        'type' => 'ld_course_tag'
                    );
                }
            }
            
            // Tags generales
            $general_tags = get_terms(array(
                'taxonomy' => 'post_tag',
                'hide_empty' => false,
                'number' => 100
            ));
            
            if (!is_wp_error($general_tags) && !empty($general_tags)) {
                foreach ($general_tags as $tag) {
                    // Verificar si hay cursos con esta etiqueta
                    $courses_with_tag = get_posts(array(
                        'post_type' => 'sfwd-courses',
                        'posts_per_page' => 1,
                        'post_status' => 'publish',
                        'fields' => 'ids',
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'post_tag',
                                'field' => 'term_id',
                                'terms' => $tag->term_id,
                            ),
                        ),
                    ));
                    
                    if (!empty($courses_with_tag)) {
                        // Contar cursos
                        $course_count = count(get_posts(array(
                            'post_type' => 'sfwd-courses',
                            'posts_per_page' => -1,
                            'post_status' => 'publish',
                            'fields' => 'ids',
                            'tax_query' => array(
                                array(
                                    'taxonomy' => 'post_tag',
                                    'field' => 'term_id',
                                    'terms' => $tag->term_id,
                                ),
                            ),
                        )));
                        
                        $tags[] = array(
                            'id' => 'general_' . absint($tag->term_id),
                            'name' => esc_html($tag->name) . ' (General)',
                            'count' => absint($course_count),
                            'slug' => esc_attr($tag->slug),
                            'type' => 'post_tag'
                        );
                    }
                }
            }
        }
        
        // Ordenar alfabéticamente
        usort($tags, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        wp_send_json_success($tags);
    }
    
    /**
     * Previsualizar usuarios por curso
     */
    public function preview_users_by_course_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }
        
        $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;
        
        if (!$course_id) {
            wp_send_json_error(esc_html__('ID de curso no válido', 'openwebui-sync'));
        }
        
        $enrolled_users = $this->get_course_enrolled_users($course_id);
        
        if (empty($enrolled_users)) {
            wp_send_json_success(array('users' => array(), 'count' => 0));
            return;
        }
        
        $args = array(
            'include' => array_map('absint', $enrolled_users),
            'meta_query' => array(
                array(
                    'key' => '_openwebui_synced',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'number' => 1000,
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered')
        );
        
        $users = get_users($args);
        $users_data = array();
        
        foreach ($users as $user) {
            $users_data[] = array(
                'id' => absint($user->ID),
                'login' => esc_html($user->user_login),
                'email' => esc_html($user->user_email),
                'registered' => esc_html(mysql2date('d/m/Y', $user->user_registered))
            );
        }
        
        wp_send_json_success(array(
            'users' => $users_data,
            'count' => count($users_data)
        ));
    }

    /**
     * Previsualización extendida para el panel de grupos.
     */
    public function preview_course_group_panel_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }

        $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;

        if (!$course_id) {
            wp_send_json_error(esc_html__('ID de curso no válido', 'openwebui-sync'));
        }

        $enrolled_users = $this->get_course_enrolled_users($course_id);

        if (empty($enrolled_users)) {
            wp_send_json_success(array(
                'course_id' => $course_id,
                'total' => 0,
                'synced' => 0,
                'pending' => 0,
                'preview' => array(),
                'preview_limit' => 25,
                'has_more' => false
            ));
        }

        $user_ids = array_map('absint', $enrolled_users);
        $preview_limit = 25;

        $users = get_users(array(
            'include' => $user_ids,
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered'),
            'number' => count($user_ids)
        ));

        $preview = array();
        $synced_count = 0;

        foreach ($users as $index => $user) {
            $synced = (bool) get_user_meta($user->ID, '_openwebui_synced', true);
            if ($synced) {
                $synced_count++;
            }

            if ($index < $preview_limit) {
                $sync_date = get_user_meta($user->ID, '_openwebui_sync_date', true);

                $preview[] = array(
                    'id' => absint($user->ID),
                    'login' => esc_html($user->user_login),
                    'email' => esc_html($user->user_email),
                    'registered' => esc_html(mysql2date('d/m/Y', $user->user_registered)),
                    'synced' => $synced,
                    'sync_date' => $sync_date ? esc_html(mysql2date('d/m/Y H:i', $sync_date)) : ''
                );
            }
        }

        $total = count($user_ids);
        $pending = max(0, $total - $synced_count);

        wp_send_json_success(array(
            'course_id' => $course_id,
            'total' => $total,
            'synced' => $synced_count,
            'pending' => $pending,
            'preview' => $preview,
            'preview_limit' => $preview_limit,
            'has_more' => $total > $preview_limit
        ));
    }
    
    /**
     * ⭐ SEGURIDAD 26: Sincronización con múltiples protecciones
     */
    public function sync_new_user_immediately($user_id) {
        $user_id = absint($user_id);
        
        // No sincronizar durante importaciones
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            $this->log_sync($user_id, 'info', 'Sincronización omitida: WordPress en modo importación');
            return;
        }
        
        // Verificar configuración
        if (empty($this->api_url)) {
            $this->log_sync($user_id, 'warning', 'Sincronización omitida: API no configurada');
            return;
        }
        
        // Evitar bucles con transient
        $transient_key = 'syncing_user_' . $user_id;
        if (get_transient($transient_key)) {
            $this->log_sync($user_id, 'warning', 'Sincronización ya en proceso - evitando bucle');
            return;
        }
        
        // Verificar límite de intentos
        $attempts = absint(get_user_meta($user_id, '_openwebui_sync_attempts', true));
        if ($attempts >= OPENWEBUI_MAX_SYNC_ATTEMPTS) {
            $this->log_sync($user_id, 'error', sprintf('Máximo de intentos alcanzado (%d)', OPENWEBUI_MAX_SYNC_ATTEMPTS));
            return;
        }
        
        // Verificar rate limit
        if (!$this->check_rate_limit($user_id)) {
            $this->log_sync($user_id, 'warning', 'Rate limit excedido - reintentando más tarde');
            return;
        }
        
        // Marcar como "en proceso"
        set_transient($transient_key, true, 60);
        
        try {
            $this->log_sync($user_id, 'info', sprintf('Iniciando sincronización (intento %d/%d)', $attempts + 1, OPENWEBUI_MAX_SYNC_ATTEMPTS));
            
            $result = $this->sync_user($user_id);
            
            if ($result['success']) {
                $this->log_sync($user_id, 'success', 'Usuario sincronizado automáticamente');
                delete_user_meta($user_id, '_openwebui_sync_attempts');
            } else {
                $this->log_sync($user_id, 'error', 'Error: ' . $result['message']);
                update_user_meta($user_id, '_openwebui_sync_attempts', $attempts + 1);
            }
        } catch (Exception $e) {
            $this->log_sync($user_id, 'error', 'Excepción: ' . esc_html($e->getMessage()));
            update_user_meta($user_id, '_openwebui_sync_attempts', $attempts + 1);
        } finally {
            delete_transient($transient_key);
        }
    }
    
    /**
     * Verificar cambio de contraseña
     */
    public function check_password_change($user_id, $old_user_data) {
        $user = get_userdata($user_id);
        
        if ($user && $user->user_pass !== $old_user_data->user_pass) {
            $this->log_sync($user_id, 'info', 'Contraseña actualizada en WordPress');
        }
    }
    
    /**
     * ⭐ SEGURIDAD 27: Sincronizar usuario con validaciones
     */
    private function sync_user($user_id) {
        $user_id = absint($user_id);
        $user = get_userdata($user_id);
        
        if (!$user) {
            return array('success' => false, 'message' => esc_html__('Usuario no encontrado', 'openwebui-sync'));
        }
        
        if (get_user_meta($user_id, '_openwebui_synced', true)) {
            return array('success' => true, 'message' => esc_html__('Usuario ya sincronizado', 'openwebui-sync'));
        }
        
        // ⭐ Validar email
        if (!is_email($user->user_email)) {
            return array('success' => false, 'message' => esc_html__('Email de usuario inválido', 'openwebui-sync'));
        }
        
        $temp_password = 'educarescuidar';
        
        $user_data = array(
            'username' => sanitize_user($user->user_login),
            'email' => sanitize_email($user->user_email),
            'password' => $temp_password,
            'name' => sanitize_text_field($user->display_name ? $user->display_name : $user->user_login)
        );
        
        $endpoints = array('/api/v1/auths/signup', '/api/auths/signup', '/auth/register', '/api/auth/register');
        
        foreach ($endpoints as $endpoint) {
            $result = $this->call_openwebui_api($endpoint, $user_data);
            
            if ($result['success']) {
                update_user_meta($user_id, '_openwebui_synced', true);
                update_user_meta($user_id, '_openwebui_sync_date', current_time('mysql'));
                delete_user_meta($user_id, '_openwebui_sync_attempts');
                $this->log_sync($user_id, 'success', 'Usuario creado en OpenWebUI');
                $this->send_credentials_email($user, $temp_password, true);
                return array('success' => true, 'message' => esc_html__('Usuario sincronizado', 'openwebui-sync'));
            }
            
            if (stripos($result['message'], 'already exists') !== false || 
                stripos($result['message'], '409') !== false ||
                stripos($result['message'], 'duplicate') !== false) {
                
                update_user_meta($user_id, '_openwebui_synced', true);
                update_user_meta($user_id, '_openwebui_sync_date', current_time('mysql'));
                delete_user_meta($user_id, '_openwebui_sync_attempts');
                $this->log_sync($user_id, 'success', 'Usuario ya existía en OpenWebUI');
                $this->send_credentials_email($user, $temp_password, false);
                return array('success' => true, 'message' => esc_html__('Usuario ya existe', 'openwebui-sync'));
            }
        }
        
        $this->log_sync($user_id, 'error', $result['message']);
        return array('success' => false, 'message' => $result['message']);
    }
    
    /**
     * ⭐ SEGURIDAD 28: API call con protecciones SSL y timeout
     */
    private function call_openwebui_api($endpoint, $data) {
        if (empty($this->api_url)) {
            return array('success' => false, 'message' => esc_html__('URL no configurada', 'openwebui-sync'));
        }
        
        // ⭐ Validar endpoint
        if (!preg_match('/^\/[\w\/-]+(?:\?[\w\-\.=%&]+)?$/', $endpoint)) {
            return array('success' => false, 'message' => esc_html__('Endpoint inválido', 'openwebui-sync'));
        }
        
        $url = rtrim($this->api_url, '/') . $endpoint;
        
        // ⭐ Verificar cache
        $cache_key = md5($url . json_encode($data));
        if (isset($this->api_cache[$cache_key])) {
            return $this->api_cache[$cache_key];
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );
        
        if (!empty($this->api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }
        
        $args = array(
            'method' => 'POST',
            'timeout' => OPENWEBUI_API_TIMEOUT,
            'redirection' => 3, // ⭐ Reducido de 5 a 3
            'httpversion' => '1.1',
            'headers' => $headers,
            'body' => wp_json_encode($data),
            'sslverify' => true, // ⭐ Siempre verificar SSL
            'blocking' => true
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log(sprintf('OpenWebUI API Error: %s - %s', esc_html($endpoint), esc_html($error_message)));
            
            $result = array('success' => false, 'message' => $error_message);
            $this->api_cache[$cache_key] = $result;
            return $result;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code < 200 || $code >= 300) {
            error_log(sprintf('OpenWebUI API: %s - HTTP %d - Body: %s', esc_html($endpoint), absint($code), esc_html(substr($body, 0, 200))));
        }
        
        if ($code >= 200 && $code < 300) {
            $result = array('success' => true, 'message' => 'OK', 'data' => json_decode($body, true));
            $this->api_cache[$cache_key] = $result;
            return $result;
        }
        
        $result = array('success' => false, 'message' => sprintf('HTTP %d: %s', $code, substr($body, 0, 100)));
        $this->api_cache[$cache_key] = $result;
        return $result;
    }

    /**
     * ⭐ NUEVA: Llamada GET a la API de OpenWebUI
     */
    private function call_openwebui_api_get($endpoint) {
        if (empty($this->api_url)) {
            return array('success' => false, 'message' => esc_html__('URL no configurada', 'openwebui-sync'));
        }

        // Validar endpoint
        if (!preg_match('/^\/[a-zA-Z0-9\/_\.-]+(?:\?[a-zA-Z0-9\/_\.%&=+-]*)?$/', $endpoint)) {
            return array('success' => false, 'message' => esc_html__('Endpoint inválido', 'openwebui-sync'));
        }

        $url = rtrim($this->api_url, '/') . $endpoint;

        // Verificar cache
        $cache_key = 'get_' . md5($url);
        if (isset($this->api_cache[$cache_key])) {
            return $this->api_cache[$cache_key];
        }

        $headers = array(
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );

        if (!empty($this->api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }

        $args = array(
            'method' => 'GET',
            'timeout' => OPENWEBUI_API_TIMEOUT,
            'redirection' => 3,
            'httpversion' => '1.1',
            'headers' => $headers,
            'sslverify' => true,
            'blocking' => true
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log(sprintf('OpenWebUI API GET Error: %s - %s', esc_html($endpoint), esc_html($error_message)));

            $result = array('success' => false, 'message' => $error_message);
            $this->api_cache[$cache_key] = $result;
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            error_log(sprintf('OpenWebUI API GET: %s - HTTP %d - Body: %s', esc_html($endpoint), absint($code), esc_html(substr($body, 0, 200))));
        }

        if ($code >= 200 && $code < 300) {
            $result = array('success' => true, 'message' => 'OK', 'data' => json_decode($body, true));
            $this->api_cache[$cache_key] = $result;
            return $result;
        }

        $result = array('success' => false, 'message' => sprintf('HTTP %d: %s', $code, substr($body, 0, 100)));
        $this->api_cache[$cache_key] = $result;
        return $result;
    }

    /**
     * Realizar una petición DELETE contra la API de OpenWebUI.
     */
    private function call_openwebui_api_delete($endpoint) {
        if (empty($this->api_url)) {
            return array('success' => false, 'message' => esc_html__('URL no configurada', 'openwebui-sync'), 'code' => 0);
        }

        if (!preg_match('/^\/[\w\/-]+(?:\?[\w\-\.=%&]+)?$/', $endpoint)) {
            return array('success' => false, 'message' => esc_html__('Endpoint inválido', 'openwebui-sync'), 'code' => 0);
        }

        $url = rtrim($this->api_url, '/') . $endpoint;

        $headers = array(
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );

        if (!empty($this->api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }

        $args = array(
            'method' => 'DELETE',
            'timeout' => OPENWEBUI_API_TIMEOUT,
            'redirection' => 3,
            'httpversion' => '1.1',
            'headers' => $headers,
            'sslverify' => true,
            'blocking' => true
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
                'code' => 0
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            return array('success' => true, 'message' => 'OK', 'code' => $code);
        }

        return array(
            'success' => false,
            'message' => sprintf('HTTP %d: %s', $code, substr($body, 0, 120)),
            'code' => $code
        );
    }

    /**
     * Obtener grupos disponibles desde la API
     */
    private function fetch_openwebui_groups_from_api() {
        $endpoints = array(
            '/api/v1/groups',
            '/api/groups',
            '/groups',
            '/api/v1/admin/groups'
        );

        $empty_notice = '';

        foreach ($endpoints as $endpoint) {
            $response = $this->call_openwebui_api_get($endpoint);

            if (!$response['success']) {
                continue;
            }

            $payload = isset($response['data']) ? $response['data'] : array();
            $groups = $this->normalize_groups_payload($payload);

            if (!empty($groups)) {
                return array(
                    'success' => true,
                    'groups' => $groups
                );
            }

            // Guardar aviso si la respuesta fue válida pero sin resultados.
            if ($empty_notice === '' && is_array($payload)) {
                $empty_notice = esc_html__('La llamada fue correcta pero no se recibieron grupos desde OpenWebUI.', 'openwebui-sync');
            }
        }

        if ($empty_notice !== '') {
            return array(
                'success' => true,
                'groups' => array(),
                'notice' => $empty_notice
            );
        }

        return array(
            'success' => false,
            'message' => esc_html__('No se pudieron obtener grupos desde la API de OpenWebUI.', 'openwebui-sync')
        );
    }

    /**
     * Normalizar distintas respuestas de la API para obtener la lista de grupos.
     *
     * @param mixed $payload Datos crudos de la API.
     * @return array Lista de grupos sanitizados.
     */
    private function normalize_groups_payload($payload) {
        $groups = array();
        $this->collect_groups_from_payload($payload, $groups);

        if (empty($groups)) {
            return array();
        }

        // Deduplicar por identificador.
        $unique = array();
        foreach ($groups as $group) {
            $unique[$group['id']] = $group;
        }

        return array_values($unique);
    }

    /**
     * Recorrer recursivamente el payload y agregar grupos sanitizados al recolector.
     *
     * @param mixed $payload Entrada a analizar.
     * @param array $collector Referencia donde se almacenan los grupos.
     * @return void
     */
    private function collect_groups_from_payload($payload, array &$collector) {
        if (!is_array($payload)) {
            return;
        }

        $sanitized = $this->sanitize_group_entry($payload);
        if (!empty($sanitized)) {
            $collector[] = $sanitized;
            return;
        }

        if ($payload === array_values($payload)) {
            foreach ($payload as $item) {
                $this->collect_groups_from_payload($item, $collector);
            }
            return;
        }

        $candidate_keys = array('groups', 'data', 'items', 'results', 'payload', 'records');
        $handled = false;

        foreach ($candidate_keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $this->collect_groups_from_payload($payload[$key], $collector);
                $handled = true;
            }
        }

        if ($handled) {
            return;
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $this->collect_groups_from_payload($value, $collector);
            }
        }
    }

    /**
     * Añadir usuario al grupo indicado
     */
    private function add_user_to_group($group_id, $group_name, $user) {
        $email = sanitize_email($user->user_email);
        $username = sanitize_user($user->user_login);

        if (empty($email) && empty($username)) {
            return array(
                'status' => 'error',
                'message' => esc_html__('Datos de usuario inválidos para la asignación.', 'openwebui-sync')
            );
        }

        $payload_variants = array();

        if (!empty($email)) {
            $payload_variants[] = array('email' => $email);
            $payload_variants[] = array('user_email' => $email);
            $payload_variants[] = array('emails' => array($email));
            $payload_variants[] = array('user' => array('email' => $email));
            $payload_variants[] = array('members' => array(array('email' => $email)));
        }

        if (!empty($username)) {
            $payload_variants[] = array('username' => $username);
            $payload_variants[] = array('usernames' => array($username));
            $payload_variants[] = array('user' => array('username' => $username));
        }

        $payload_variants[] = array(
            'user' => array(
                'email' => $email,
                'username' => $username
            )
        );

        $endpoint_patterns = array(
            '/api/v1/groups/%s/members',
            '/api/groups/%s/members',
            '/api/v1/groups/%s/users',
            '/api/groups/%s/users',
            '/groups/%s/members'
        );

        $last_error = esc_html__('No se pudo añadir el usuario al grupo.', 'openwebui-sync');

        foreach ($endpoint_patterns as $pattern) {
            $endpoint = sprintf($pattern, rawurlencode($group_id));

            foreach ($payload_variants as $payload) {
                $result = $this->call_openwebui_api($endpoint, $payload);

                if ($result['success']) {
                    return array(
                        'status' => 'added',
                        'message' => esc_html__('Usuario añadido correctamente.', 'openwebui-sync')
                    );
                }

                if ($this->is_duplicate_group_message($result['message'])) {
                    return array(
                        'status' => 'duplicate',
                        'message' => esc_html__('El usuario ya pertenece a este grupo.', 'openwebui-sync')
                    );
                }

                $last_error = $result['message'];
            }
        }

        return array(
            'status' => 'error',
            'message' => $last_error
        );
    }

    /**
     * Eliminar un usuario de un grupo en OpenWebUI.
     */
    private function remove_user_from_group($group_id, $group_name, $user) {
        $email = sanitize_email($user->user_email);

        if (empty($email)) {
            return array(
                'status' => 'skipped',
                'message' => esc_html__('El usuario no tiene un email válido.', 'openwebui-sync')
            );
        }

        $remote_user_id = $this->get_openwebui_user_id_by_email($email);

        if (!$remote_user_id) {
            return array(
                'status' => 'missing',
                'message' => esc_html__('Usuario no encontrado en OpenWebUI.', 'openwebui-sync')
            );
        }

        $endpoint_patterns = array(
            '/api/v1/groups/%1$s/members/%2$s',
            '/api/groups/%1$s/members/%2$s',
            '/groups/%1$s/members/%2$s'
        );

        $last_error = esc_html__('No se pudo quitar el usuario del grupo.', 'openwebui-sync');

        foreach ($endpoint_patterns as $pattern) {
            $endpoint = sprintf($pattern, rawurlencode($group_id), rawurlencode($remote_user_id));
            $result = $this->call_openwebui_api_delete($endpoint);

            if ($result['success']) {
                return array(
                    'status' => 'removed',
                    'message' => esc_html__('Usuario eliminado del grupo.', 'openwebui-sync')
                );
            }

            if (isset($result['code']) && in_array((int) $result['code'], array(404), true)) {
                return array(
                    'status' => 'missing',
                    'message' => esc_html__('El usuario no estaba asignado al grupo.', 'openwebui-sync')
                );
            }

            $last_error = $result['message'];
        }

        return array(
            'status' => 'error',
            'message' => $last_error
        );
    }

    /**
     * Determinar si un mensaje indica duplicidad
     */
    private function is_duplicate_group_message($message) {
        $message = strtolower((string) $message);

        $keywords = array('already', 'exists', 'duplicate', '409', 'member');

        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⭐ NUEVA: Sincronización bilateral - marcar usuarios que ya existen en OpenWebUI
     */
    public function bilateral_sync_ajax() {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }

        // Verificar nonce
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        // Verificar configuración
        if (empty($this->api_url)) {
            wp_send_json_error(esc_html__('API no configurada', 'openwebui-sync'));
        }

        // Obtener lista de usuarios de OpenWebUI
        $openwebui_users_result = $this->call_openwebui_api_get('/api/v1/users');

        if (!$openwebui_users_result['success']) {
            // Intentar endpoints alternativos
            $alt_endpoints = array('/api/users', '/users');
            foreach ($alt_endpoints as $alt_endpoint) {
                $openwebui_users_result = $this->call_openwebui_api_get($alt_endpoint);
                if ($openwebui_users_result['success']) {
                    break;
                }
            }
        }

        if (!$openwebui_users_result['success']) {
            wp_send_json_error(sprintf(
                esc_html__('No se pudo obtener usuarios de OpenWebUI: %s', 'openwebui-sync'),
                $openwebui_users_result['message']
            ));
        }

        $openwebui_users = isset($openwebui_users_result['data']) ? $openwebui_users_result['data'] : array();

        if (empty($openwebui_users) || !is_array($openwebui_users)) {
            wp_send_json_error(esc_html__('No se encontraron usuarios en OpenWebUI', 'openwebui-sync'));
        }

        // Crear un mapa de emails de OpenWebUI para búsqueda rápida
        $openwebui_emails = array();
        foreach ($openwebui_users as $owu_user) {
            if (isset($owu_user['email']) && !empty($owu_user['email'])) {
                $openwebui_emails[strtolower($owu_user['email'])] = true;
            }
        }

        // Obtener todos los usuarios de WordPress
        $wp_users = get_users(array(
            'fields' => array('ID', 'user_email')
        ));

        $synced_count = 0;
        $already_synced = 0;
        $not_found = 0;

        foreach ($wp_users as $wp_user) {
            $user_id = absint($wp_user->ID);
            $user_email = strtolower(sanitize_email($wp_user->user_email));

            // Verificar si ya está marcado como sincronizado
            if (get_user_meta($user_id, '_openwebui_synced', true)) {
                $already_synced++;
                continue;
            }

            // Verificar si existe en OpenWebUI
            if (isset($openwebui_emails[$user_email])) {
                update_user_meta($user_id, '_openwebui_synced', true);
                update_user_meta($user_id, '_openwebui_sync_date', current_time('mysql'));
                delete_user_meta($user_id, '_openwebui_sync_attempts');
                $this->log_sync($user_id, 'success', 'Usuario marcado como sincronizado (bilateral sync)');
                $synced_count++;
            } else {
                $not_found++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                esc_html__('Sincronización bilateral completada: %d usuarios marcados como sincronizados, %d ya estaban sincronizados, %d no encontrados en OpenWebUI', 'openwebui-sync'),
                $synced_count,
                $already_synced,
                $not_found
            ),
            'synced' => $synced_count,
            'already_synced' => $already_synced,
            'not_found' => $not_found,
            'total_openwebui' => count($openwebui_users),
            'total_wordpress' => count($wp_users)
        ));
    }

    /**
     * Sincronizar listado de grupos desde OpenWebUI
     */
    public function sync_openwebui_groups_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }

        if (empty($this->api_url)) {
            wp_send_json_error(esc_html__('Configura la URL de la API primero', 'openwebui-sync'));
        }

        $groups_result = $this->fetch_openwebui_groups_from_api();

        if (!$groups_result['success']) {
            wp_send_json_error($groups_result['message']);
        }

        $updated_at = current_time('mysql');
        $updated_label = '';

        if ($updated_at) {
            $formatted = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $updated_at);
            if (!empty($formatted)) {
                $updated_label = sanitize_text_field($formatted);
            }
        }

        $this->groups_cache = array(
            'groups' => $groups_result['groups'],
            'updated_at' => $updated_at,
            'updated_label' => $updated_label,
        );

        update_option('openwebui_groups_cache', $this->groups_cache, false);

        $response = array(
            'groups' => $groups_result['groups'],
            'updated_at' => $updated_at,
            'updated_label' => $updated_label,
            'count' => count($groups_result['groups'])
        );

        if (!empty($groups_result['notice'])) {
            $response['notice'] = $groups_result['notice'];
        }

        wp_send_json_success($response);
    }

    /**
     * Obtener los cursos disponibles para el panel de grupos vía AJAX.
     */
    public function load_group_panel_courses_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }

        $courses = $this->get_group_panel_courses();
        $default_course = 0;
        $default_group = '';

        if (!empty($courses)) {
            $first_course = reset($courses);
            if (is_array($first_course)) {
                if (isset($first_course['id'])) {
                    $default_course = absint($first_course['id']);
                }

                if (!empty($first_course['group_id'])) {
                    $default_group = sanitize_text_field($first_course['group_id']);
                }
            }
        }

        wp_send_json_success(array(
            'courses' => $courses,
            'count' => count($courses),
            'default_course' => $default_course,
            'default_group' => $default_group,
        ));
    }

    /**
     * Asignar usuarios a un grupo de OpenWebUI
     */
    public function assign_group_members_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }

        $group_id = isset($_POST['group_id']) ? sanitize_text_field(wp_unslash($_POST['group_id'])) : '';
        $filter_type = isset($_POST['filter_type']) ? sanitize_text_field(wp_unslash($_POST['filter_type'])) : '';
        $filter_value = isset($_POST['filter_value']) ? wp_unslash($_POST['filter_value']) : '';

        if (empty($group_id) || empty($filter_type)) {
            wp_send_json_error(esc_html__('Solicitud inválida', 'openwebui-sync'));
        }

        $group = $this->find_group_by_id($group_id);

        if (!$group) {
            wp_send_json_error(esc_html__('Grupo no encontrado. Sincroniza la lista de grupos nuevamente.', 'openwebui-sync'));
        }

        $users = $this->get_users_for_group_assignment($filter_type, $filter_value);

        if (empty($users)) {
            wp_send_json_error(esc_html__('No se encontraron usuarios para procesar.', 'openwebui-sync'));
        }

        $added = 0;
        $already = 0;
        $skipped = array();
        $errors = array();

        foreach ($users as $user) {
            $user_id = absint($user->ID);

            if (!get_user_meta($user_id, '_openwebui_synced', true)) {
                $skipped[] = esc_html($user->user_email);
                continue;
            }

            if (!$this->check_rate_limit($user_id)) {
                $errors[] = sprintf(
                    esc_html__('Rate limit alcanzado para %s', 'openwebui-sync'),
                    esc_html($user->user_email)
                );
                continue;
            }

            $result = $this->add_user_to_group($group['id'], $group['name'], $user);

            if ($result['status'] === 'added') {
                $added++;
                $this->log_sync($user_id, 'info', sprintf('Usuario añadido al grupo %s', $group['name']));
            } elseif ($result['status'] === 'duplicate') {
                $already++;
                $this->log_sync($user_id, 'info', sprintf('Usuario ya pertenece al grupo %s', $group['name']));
            } else {
                $errors[] = sprintf(
                    '%s: %s',
                    esc_html($user->user_email),
                    esc_html($result['message'])
                );
                $this->log_sync($user_id, 'error', sprintf('Error al añadir al grupo %s: %s', $group['name'], $result['message']));
            }

            // Pequeña pausa cada 5 usuarios para evitar saturar la API
            if (($added + $already + count($errors)) % 5 === 0) {
                sleep(1);
            }
        }

        wp_send_json_success(array(
            'group' => $group,
            'added' => $added,
            'already' => $already,
            'skipped' => $skipped,
            'errors' => $errors
        ));
    }

    /**
     * Eliminar usuarios de un grupo de OpenWebUI.
     */
    public function remove_group_members_ajax() {
        check_ajax_referer('openwebui_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permisos insuficientes', 'openwebui-sync'));
        }

        $group_id = isset($_POST['group_id']) ? sanitize_text_field(wp_unslash($_POST['group_id'])) : '';
        $filter_type = isset($_POST['filter_type']) ? sanitize_text_field(wp_unslash($_POST['filter_type'])) : '';
        $filter_value = isset($_POST['filter_value']) ? wp_unslash($_POST['filter_value']) : '';

        if (empty($group_id) || empty($filter_type)) {
            wp_send_json_error(esc_html__('Solicitud inválida', 'openwebui-sync'));
        }

        $group = $this->find_group_by_id($group_id);

        if (!$group) {
            wp_send_json_error(esc_html__('Grupo no encontrado. Sincroniza la lista de grupos nuevamente.', 'openwebui-sync'));
        }

        $users = $this->get_users_for_group_assignment($filter_type, $filter_value);

        if (empty($users)) {
            wp_send_json_error(esc_html__('No se encontraron usuarios para procesar.', 'openwebui-sync'));
        }

        $removed = 0;
        $missing = 0;
        $skipped = array();
        $errors = array();

        foreach ($users as $user) {
            $user_id = absint($user->ID);

            if (!get_user_meta($user_id, '_openwebui_synced', true)) {
                $skipped[] = esc_html($user->user_email);
                continue;
            }

            if (!$this->check_rate_limit($user_id)) {
                $errors[] = sprintf(
                    esc_html__('Rate limit alcanzado para %s', 'openwebui-sync'),
                    esc_html($user->user_email)
                );
                continue;
            }

            $result = $this->remove_user_from_group($group['id'], $group['name'], $user);

            if ('removed' === $result['status']) {
                $removed++;
                $this->log_sync($user_id, 'info', sprintf('Usuario eliminado del grupo %s', $group['name']));
            } elseif ('missing' === $result['status']) {
                $missing++;
            } elseif ('skipped' === $result['status']) {
                $skipped[] = esc_html($user->user_email);
            } else {
                $errors[] = sprintf(
                    '%s: %s',
                    esc_html($user->user_email),
                    esc_html($result['message'])
                );
                $this->log_sync($user_id, 'error', sprintf('Error al eliminar del grupo %s: %s', $group['name'], $result['message']));
            }

            if (($removed + $missing + count($errors)) % 5 === 0) {
                sleep(1);
            }
        }

        wp_send_json_success(array(
            'group' => $group,
            'removed' => $removed,
            'missing' => $missing,
            'skipped' => $skipped,
            'errors' => $errors
        ));
    }

    /**
     * ⭐ SEGURIDAD 29: Logs con sanitización
     */
    private function log_sync($user_id, $status, $message) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        // Solo loggear errores en producción
        if (!WP_DEBUG && $status !== 'error' && $status !== 'warning') {
            return;
        }
        
        $log = sprintf('[%s] User: %s (ID: %d) - %s - %s',
            current_time('mysql'),
            esc_html($user->user_email),
            absint($user_id),
            strtoupper(sanitize_text_field($status)),
            esc_html($message)
        );
        
        error_log('OpenWebUI Sync: ' . $log);
        
        $logs = get_option('openwebui_sync_logs', array());
        array_unshift($logs, array(
            'date' => current_time('mysql'),
            'user_id' => absint($user_id),
            'email' => esc_html($user->user_email),
            'status' => sanitize_text_field($status),
            'message' => esc_html($message)
        ));
        
        update_option('openwebui_sync_logs', array_slice($logs, 0, 100), false);
    }
    
    /**
     * ⭐ SEGURIDAD 30: Email con sanitización y validación
     */
    private function send_credentials_email($user, $password, $is_new = true) {
        if ($this->send_emails !== 'yes') {
            return;
        }
        
        // Validar email
        if (!is_email($user->user_email)) {
            $this->log_sync($user->ID, 'error', 'Email inválido - no se puede enviar credenciales');
            return;
        }
        
        $to = sanitize_email($user->user_email);
        $site_name = esc_html(get_bloginfo('name'));
        $user_name = esc_html($user->display_name ? $user->display_name : $user->user_login);
        
        if ($is_new) {
            $subject = sprintf(esc_html__('Bienvenido a %s - Tu cuenta en Jimena', 'openwebui-sync'), $site_name);
        } else {
            $subject = sprintf(esc_html__('Tu cuenta en %s - Jimena', 'openwebui-sync'), $site_name);
        }
        
        $my_account_url = esc_url('https://miceanou.com');
        
        $message = $this->get_email_template(array(
            'site_name' => $site_name,
            'user_name' => $user_name,
            'username' => esc_html($user->user_login),
            'password' => esc_html($password),
            'my_account_url' => $my_account_url,
            'is_new_account' => (bool) $is_new
        ));
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, $subject, $message, $headers);
        
        if ($sent) {
            $this->log_sync($user->ID, 'info', 'Email de credenciales enviado');
        } else {
            $this->log_sync($user->ID, 'warning', 'Error al enviar email de credenciales');
        }
    }
    
    /**
     * Template de email
     */
    private function get_email_template($data) {
        $site_name = isset($data['site_name']) ? esc_html($data['site_name']) : esc_html(get_bloginfo('name'));
        $user_name = isset($data['user_name']) ? esc_html($data['user_name']) : '';
        $username = isset($data['username']) ? esc_html($data['username']) : '';
        $password = isset($data['password']) ? esc_html($data['password']) : '';
        $my_account_url = isset($data['my_account_url']) ? esc_url($data['my_account_url']) : '';
        $is_new_account = isset($data['is_new_account']) ? (bool) $data['is_new_account'] : true;
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo $site_name; ?></title>
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f0f0f1; color: #3c434a;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f0f1; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 100%;">
                            <tr>
                                <td style="padding: 40px 40px 20px 40px;">
                                    <h2 style="margin: 0; color: #50b9c8; font-size: 16px; font-weight: 400;">
                                        <?php echo $site_name; ?>
                                    </h2>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 40px 30px 40px;">
                                    <h1 style="margin: 0; color: #3c434a; font-size: 28px; font-weight: 600; line-height: 1.3;">
                                        <?php if ($is_new_account): ?>
                                            Bienvenido a <?php echo $site_name; ?>
                                        <?php else: ?>
                                            Tu cuenta en <?php echo $site_name; ?>
                                        <?php endif; ?>
                                    </h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 40px 20px 40px;">
                                    <p style="margin: 0 0 16px 0; color: #646970; font-size: 16px; line-height: 1.6;">
                                        Hola <?php echo $user_name; ?>,
                                    </p>
                                    <?php if ($is_new_account): ?>
                                        <p style="margin: 0 0 16px 0; color: #646970; font-size: 16px; line-height: 1.6;">
                                            Gracias por crear una cuenta en <?php echo $site_name; ?>, dentro de ella tienes una nueva funcionalidad, nuestra nueva IA, <strong>Jimena</strong> está ya disponible, esta es tu cuenta:
                                        </p>
                                    <?php else: ?>
                                        <p style="margin: 0 0 16px 0; color: #646970; font-size: 16px; line-height: 1.6;">
                                            Ahora tu campus tiene una nueva funcionalidad, nuestra nueva IA, Jimena está ya disponible, esta es tu cuenta:
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 40px;">
                                    <hr style="border: none; border-top: 1px solid #dcdcde; margin: 20px 0;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 20px 40px;">
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="padding-bottom: 12px;">
                                                <strong style="color: #3c434a; font-size: 14px;">Nombre de usuario:</strong>
                                                <span style="color: #646970; font-size: 14px; margin-left: 8px;"><?php echo $username; ?></span>
                                            </td>
                                        </tr>
                                        <?php if ($password): ?>
                                        <tr>
                                            <td style="padding-bottom: 12px;">
                                                <strong style="color: #3c434a; font-size: 14px;">Contraseña:</strong>
                                                <span style="color: #646970; font-size: 14px; margin-left: 8px;"><?php echo $password; ?></span>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 20px 40px 40px 40px;">
                                    <p style="margin: 0 0 16px 0; color: #646970; font-size: 14px; line-height: 1.6;">
                                        Puedes acceder al área de tu cuenta para ver pedidos, cambiar tu contraseña y mucho más a través del siguiente enlace:
                                    </p>
                                    <?php if ($my_account_url): ?>
                                    <p style="margin: 0; padding-top: 10px;">
                                        <a href="<?php echo $my_account_url; ?>" 
                                           style="color: #50b9c8; text-decoration: none; font-size: 14px;">
                                            Mi cuenta
                                        </a>
                                    </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 40px 40px 40px;">
                                    <p style="margin: 0; color: #646970; font-size: 14px; line-height: 1.6;">
                                        Esperamos verte pronto.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 40px;">
                                    <hr style="border: none; border-top: 1px solid #dcdcde; margin: 0;">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px 40px; text-align: center;">
                                    <p style="margin: 0; color: #646970; font-size: 13px;">
                                        <?php echo $site_name; ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Contar usuarios de un curso
     */
    private function count_course_users($course_id) {
        $users = $this->get_course_enrolled_users($course_id);
        return count($users);
    }
    
    /**
     * ⭐ SEGURIDAD 31: Obtener usuarios con prepared statements
     */
    private function get_course_enrolled_users($course_id) {
        $course_id = absint($course_id);
        $users = array();
        
        if (defined('LEARNDASH_VERSION') || post_type_exists('sfwd-courses')) {
            global $wpdb;
            
            if (function_exists('learndash_get_users_for_course')) {
                $ld_users = learndash_get_users_for_course($course_id, array('number' => -1), false);
                if (is_array($ld_users) && !empty($ld_users)) {
                    $users = array_merge($users, array_map('absint', $ld_users));
                }
            }
            
            if (empty($users)) {
                // ⭐ Prepared statement
                $user_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta} 
                    WHERE meta_key = %s 
                    AND meta_value != ''",
                    'course_' . $course_id . '_access_from'
                ));
                
                if (!empty($user_ids)) {
                    $users = array_merge($users, array_map('absint', $user_ids));
                }
            }
            
            // Método adicional: buscar en user_meta con patrón
            if (empty($users)) {
                $enrolled_users = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
                    WHERE meta_key = %s",
                    'learndash_course_enrolled_' . $course_id
                ));
                
                if (!empty($enrolled_users)) {
                    $users = array_merge($users, array_map('absint', $enrolled_users));
                }
            }
            
            // Buscar grupos y sus usuarios
            if (function_exists('learndash_get_course_groups')) {
                $groups = learndash_get_course_groups($course_id);
                if (!empty($groups)) {
                    foreach ($groups as $group_id) {
                        if (function_exists('learndash_get_groups_user_ids')) {
                            $group_users = learndash_get_groups_user_ids($group_id);
                            if (!empty($group_users)) {
                                $users = array_merge($users, array_map('absint', $group_users));
                            }
                        }
                    }
                }
            }
        }
        
        // LifterLMS
        if (class_exists('LifterLMS')) {
            global $wpdb;
            $llms_users = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}lifterlms_user_postmeta 
                WHERE post_id = %d AND meta_key = '_status' AND meta_value = 'enrolled'",
                $course_id
            ));
            if (is_array($llms_users)) {
                $users = array_merge($users, array_map('absint', $llms_users));
            }
        }
        
        // Tutor LMS
        if (function_exists('tutor')) {
            global $wpdb;
            $tutor_users = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}posts 
                WHERE post_parent = %d AND post_type = 'tutor_enrolled'",
                $course_id
            ));
            if (is_array($tutor_users)) {
                $users = array_merge($users, array_map('absint', $tutor_users));
            }
        }
        
        return array_unique(array_filter($users));
    }
    
    /**
     * Obtener usuarios filtrados
     */
    private function get_filtered_users($filter_type, $filter_value) {
        $args = array(
            'meta_query' => array(
                array(
                    'key' => '_openwebui_synced',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'number' => 1000,
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered')
        );
        
        if ($filter_type === 'date' && !empty($filter_value)) {
            $dates = json_decode($filter_value, true);
            if (!empty($dates['from']) || !empty($dates['to'])) {
                $args['date_query'] = array();
                
                if (!empty($dates['from']) && $this->validate_date($dates['from'])) {
                    $args['date_query']['after'] = sanitize_text_field($dates['from']);
                }
                if (!empty($dates['to']) && $this->validate_date($dates['to'])) {
                    $args['date_query']['before'] = sanitize_text_field($dates['to']);
                    $args['date_query']['inclusive'] = true;
                }
            }
        }
        
        if ($filter_type === 'course' && !empty($filter_value)) {
            $course_id = absint($filter_value);
            $enrolled_users = $this->get_course_enrolled_users($course_id);
            
            if (!empty($enrolled_users)) {
                $args['include'] = $enrolled_users;
            } else {
                return array();
            }
        }
        
        return get_users($args);
    }

    /**
     * Obtener usuarios para asignación de grupos (incluye sincronizados)
     */
    private function get_users_for_group_assignment($filter_type, $filter_value) {
        $args = array(
            'number' => 1000,
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered')
        );

        if ($filter_type === 'course') {
            $course_id = absint($filter_value);
            if (!$course_id) {
                return array();
            }

            $enrolled_users = $this->get_course_enrolled_users($course_id);
            if (empty($enrolled_users)) {
                return array();
            }

            $args['include'] = array_map('absint', $enrolled_users);
        } elseif ($filter_type === 'date' && !empty($filter_value)) {
            $dates = json_decode($filter_value, true);

            if (!empty($dates) && is_array($dates)) {
                $args['date_query'] = array();

                if (!empty($dates['from']) && $this->validate_date($dates['from'])) {
                    $args['date_query']['after'] = sanitize_text_field($dates['from']);
                }

                if (!empty($dates['to']) && $this->validate_date($dates['to'])) {
                    $args['date_query']['before'] = sanitize_text_field($dates['to']);
                    $args['date_query']['inclusive'] = true;
                }
            }
        } else {
            return array();
        }

        return get_users($args);
    }
    
    /**
     * Obtener estadísticas
     */
    private function get_sync_stats() {
        $total = count_users();
        $synced = count(get_users(array(
            'meta_key' => '_openwebui_synced',
            'meta_value' => true,
            'fields' => 'ID'
        )));
        
        return array(
            'total' => absint($total['total_users']),
            'synced' => absint($synced),
            'unsynced' => absint($total['total_users']) - absint($synced)
        );
    }
    
    /**
     * Obtener usuarios sin sincronizar
     */
    private function get_unsynced_users() {
        return get_users(array(
            'meta_query' => array(
                array(
                    'key' => '_openwebui_synced',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'number' => 100,
            'orderby' => 'registered',
            'order' => 'DESC',
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered')
        ));
    }
    
    /**
     * Obtener usuarios sincronizados
     */
    private function get_synced_users() {
        return get_users(array(
            'meta_key' => '_openwebui_synced',
            'meta_value' => true,
            'number' => 100,
            'orderby' => 'meta_value',
            'order' => 'DESC',
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered')
        ));
    }
    
    /**
     * Mostrar información del LMS
     */
    private function show_lms_info() {
        $lms_detected = array();
        $total_courses = 0;
        
        if (defined('LEARNDASH_VERSION')) {
            $ld_version = LEARNDASH_VERSION;
            $ld_courses = get_posts(array(
                'post_type' => 'sfwd-courses',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ));
            $lms_detected[] = array(
                'name' => 'LearnDash LMS',
                'version' => esc_html($ld_version),
                'courses' => count($ld_courses),
                'status' => 'active'
            );
            $total_courses += count($ld_courses);
        } elseif (post_type_exists('sfwd-courses')) {
            $ld_courses = get_posts(array(
                'post_type' => 'sfwd-courses',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ));
            $lms_detected[] = array(
                'name' => 'LearnDash LMS',
                'version' => 'Desconocida',
                'courses' => count($ld_courses),
                'status' => 'active'
            );
            $total_courses += count($ld_courses);
        }
        
        if (class_exists('LifterLMS')) {
            $llms_version = defined('LLMS_VERSION') ? LLMS_VERSION : 'Desconocida';
            $llms_courses = get_posts(array(
                'post_type' => 'course',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ));
            $lms_detected[] = array(
                'name' => 'LifterLMS',
                'version' => esc_html($llms_version),
                'courses' => count($llms_courses),
                'status' => 'active'
            );
            $total_courses += count($llms_courses);
        }
        
        if (function_exists('tutor')) {
            $tutor_version = defined('TUTOR_VERSION') ? TUTOR_VERSION : 'Desconocida';
            $tutor_courses = get_posts(array(
                'post_type' => 'courses',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ));
            $lms_detected[] = array(
                'name' => 'Tutor LMS',
                'version' => esc_html($tutor_version),
                'courses' => count($tutor_courses),
                'status' => 'active'
            );
            $total_courses += count($tutor_courses);
        }
        
        if (empty($lms_detected)) {
            echo '<div class="notice notice-error" style="margin: 0;">';
            echo '<p><strong>⚠️ ' . esc_html__('No se detectó ningún sistema LMS activo', 'openwebui-sync') . '</strong></p>';
            echo '<p>' . esc_html__('Este plugin requiere LearnDash LMS, LifterLMS o Tutor LMS para funcionar correctamente.', 'openwebui-sync') . '</p>';
            echo '</div>';
            return;
        }
        
        echo '<div class="notice notice-success" style="margin: 0 0 15px 0;">';
        echo '<p><strong>✓ ' . esc_html__('Sistemas LMS detectados:', 'openwebui-sync') . '</strong></p>';
        echo '</div>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Sistema LMS', 'openwebui-sync') . '</th>';
        echo '<th>' . esc_html__('Versión', 'openwebui-sync') . '</th>';
        echo '<th>' . esc_html__('Cursos Publicados', 'openwebui-sync') . '</th>';
        echo '<th>' . esc_html__('Estado', 'openwebui-sync') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($lms_detected as $lms) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($lms['name']) . '</strong></td>';
            echo '<td>' . esc_html($lms['version']) . '</td>';
            echo '<td>' . absint($lms['courses']) . '</td>';
            echo '<td><span style="color: #46b450;">● ' . esc_html__('Activo', 'openwebui-sync') . '</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        if ($total_courses == 0) {
            echo '<div class="notice notice-warning" style="margin: 15px 0 0 0;">';
            echo '<p>' . esc_html__('No se encontraron cursos publicados. Por favor, crea al menos un curso en tu LMS.', 'openwebui-sync') . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Mostrar tabla de usuarios sincronizados
     */
    private function show_synced_users() {
        $users = $this->get_synced_users();
        
        if (empty($users)) {
            echo '<p><em>' . esc_html__('No hay usuarios sincronizados todavía.', 'openwebui-sync') . '</em></p>';
            return;
        }
        
        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <input type="search" 
                       id="search-synced" 
                       class="openwebui-search" 
                       data-table="synced-users-table"
                       placeholder="<?php echo esc_attr__('Buscar usuario, email...', 'openwebui-sync'); ?>" 
                       style="width: 300px; padding: 5px 10px;">
            </div>
            <div class="alignright">
                <span class="displaying-num"><?php printf(esc_html__('%d usuarios sincronizados', 'openwebui-sync'), count($users)); ?></span>
            </div>
        </div>
        <table id="synced-users-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="sortable"><?php esc_html_e('Usuario', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th class="sortable"><?php esc_html_e('Email', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th class="sortable sort-date"><?php esc_html_e('Fecha Registro', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th class="sortable sort-date asc"><?php esc_html_e('Fecha Sincronización', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th><?php esc_html_e('Acción', 'openwebui-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): 
                    $sync_date = get_user_meta($user->ID, '_openwebui_sync_date', true);
                ?>
                <tr>
                    <td><?php echo esc_html($user->user_login); ?></td>
                    <td><?php echo esc_html($user->user_email); ?></td>
                    <td><?php echo esc_html(mysql2date('d/m/Y', $user->user_registered)); ?></td>
                    <td>
                        <?php 
                        if ($sync_date) {
                            echo esc_html(mysql2date('d/m/Y H:i', $sync_date)); 
                        } else {
                            echo '<em>' . esc_html__('No registrada', 'openwebui-sync') . '</em>';
                        }
                        ?>
                    </td>
                    <td>
                        <button class="button button-small unsync-user-btn" 
                                data-user-id="<?php echo absint($user->ID); ?>"
                                style="color: #d63638;">
                            <?php esc_html_e('Desmarcar', 'openwebui-sync'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Mostrar tabla de usuarios sin sincronizar
     */
    private function show_unsynced_users() {
        $users = $this->get_unsynced_users();
        
        if (empty($users)) {
            echo '<p><em>' . esc_html__('¡Todos los usuarios están sincronizados!', 'openwebui-sync') . '</em></p>';
            return;
        }
        
        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <input type="search" 
                       id="search-unsynced" 
                       class="openwebui-search" 
                       data-table="unsynced-users-table"
                       placeholder="<?php echo esc_attr__('Buscar usuario, email...', 'openwebui-sync'); ?>" 
                       style="width: 300px; padding: 5px 10px;">
            </div>
            <div class="alignright">
                <span class="displaying-num"><?php printf(esc_html__('%d usuarios pendientes', 'openwebui-sync'), count($users)); ?></span>
            </div>
        </div>
        <table id="unsynced-users-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="sortable"><?php esc_html_e('Usuario', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th class="sortable"><?php esc_html_e('Email', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th class="sortable sort-date asc"><?php esc_html_e('Fecha Registro', 'openwebui-sync'); ?> <span class="dashicons dashicons-sort"></span></th>
                    <th><?php esc_html_e('Acción', 'openwebui-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo esc_html($user->user_login); ?></td>
                    <td><?php echo esc_html($user->user_email); ?></td>
                    <td><?php echo esc_html(mysql2date('d/m/Y', $user->user_registered)); ?></td>
                    <td>
                        <button class="button button-small sync-user-btn" 
                                data-user-id="<?php echo absint($user->ID); ?>" 
                                data-user-email="<?php echo esc_attr($user->user_email); ?>">
                            <?php esc_html_e('Sincronizar', 'openwebui-sync'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Página de administración
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos suficientes para acceder a esta página.', 'openwebui-sync'));
        }
        
        $stats = $this->get_sync_stats();
        $groups_updated_at = '';

        if (!empty($this->groups_cache['updated_label'])) {
            $groups_updated_at = $this->groups_cache['updated_label'];
        } elseif (!empty($this->groups_cache['updated_at'])) {
            $groups_updated_at = mysql2date('d/m/Y H:i', $this->groups_cache['updated_at']);
        }
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e('OpenWebUI User Sync', 'openwebui-sync'); ?>
                <span style="font-size: 14px; color: #666; font-weight: normal;">v<?php echo esc_html(OPENWEBUI_SYNC_VERSION); ?></span>
            </h1>
            
            <!-- Estadísticas -->
            <div class="openwebui-stats-box">
                <h2><?php esc_html_e('Estado de Sincronización', 'openwebui-sync'); ?></h2>
                <div class="openwebui-stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo absint($stats['total']); ?></div>
                        <div class="stat-label"><?php esc_html_e('Usuarios Totales', 'openwebui-sync'); ?></div>
                    </div>
                    <div class="stat-item stat-success">
                        <div class="stat-number"><?php echo absint($stats['synced']); ?></div>
                        <div class="stat-label"><?php esc_html_e('Sincronizados', 'openwebui-sync'); ?></div>
                    </div>
                    <div class="stat-item stat-warning">
                        <div class="stat-number"><?php echo absint($stats['unsynced']); ?></div>
                        <div class="stat-label"><?php esc_html_e('Por Sincronizar', 'openwebui-sync'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Configuración -->
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" class="openwebui-form">
                <h2><?php esc_html_e('Configuración de la API', 'openwebui-sync'); ?></h2>
                <?php settings_fields('openwebui_settings'); ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="openwebui_api_url"><?php esc_html_e('URL de la API', 'openwebui-sync'); ?></label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="openwebui_api_url"
                                   name="openwebui_api_url" 
                                   value="<?php echo esc_attr($this->api_url); ?>" 
                                   class="regular-text" 
                                   placeholder="https://asistenteia.miceanou.com">
                            <p class="description">
                                <?php esc_html_e('URL completa de tu instancia de OpenWebUI (sin barra final, debe usar HTTPS)', 'openwebui-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openwebui_api_key"><?php esc_html_e('API Key', 'openwebui-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" 
                                   id="openwebui_api_key"
                                   name="openwebui_api_key" 
                                   value="<?php echo esc_attr($this->api_key); ?>" 
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e('Opcional', 'openwebui-sync'); ?>">
                            <p class="description">
                                <?php esc_html_e('Token de autenticación (si tu API lo requiere)', 'openwebui-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openwebui_send_emails"><?php esc_html_e('Enviar Emails', 'openwebui-sync'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="openwebui_send_emails"
                                       name="openwebui_send_emails" 
                                       value="yes"
                                       <?php checked($this->send_emails, 'yes'); ?>>
                                <?php esc_html_e('Enviar email con credenciales a usuarios nuevos', 'openwebui-sync'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(esc_html__('Guardar Configuración', 'openwebui-sync'), 'primary', 'submit', true); ?>
            </form>
            
            <hr style="margin: 30px 0;">
            
            <!-- Test de API -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Probar Conexión', 'openwebui-sync'); ?></h2>
                <p><?php esc_html_e('Verifica que la configuración de tu API es correcta antes de sincronizar usuarios.', 'openwebui-sync'); ?></p>
                <button type="button" class="button button-primary" id="test-api">
                    <?php esc_html_e('Probar Conexión API', 'openwebui-sync'); ?>
                </button>
                <span id="test-result" style="margin-left: 15px; display: inline-block;"></span>
            </div>

            <hr style="margin: 30px 0;">

            <!-- Sincronización Bilateral -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Sincronización Bilateral', 'openwebui-sync'); ?></h2>
                <p><?php esc_html_e('Detecta automáticamente usuarios de WordPress que ya existen en OpenWebUI y márcalos como sincronizados.', 'openwebui-sync'); ?></p>
                <p style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px; margin: 15px 0;">
                    <strong>ℹ️ <?php esc_html_e('¿Qué hace esta función?', 'openwebui-sync'); ?></strong><br>
                    <?php esc_html_e('Esta herramienta consulta la lista de usuarios de OpenWebUI y marca como sincronizados aquellos usuarios de WordPress que ya existen allí (comparando por email). Esto es útil si ya tienes usuarios creados en OpenWebUI y quieres que aparezcan como sincronizados en WordPress.', 'openwebui-sync'); ?>
                </p>
                <button type="button" class="button button-secondary" id="bilateral-sync" style="background: #2271b1; border-color: #2271b1; color: #fff;">
                    <?php esc_html_e('Sincronizar Bilateralmente', 'openwebui-sync'); ?>
                </button>
                <div id="bilateral-sync-result" style="margin-top: 15px;"></div>
            </div>

            <hr style="margin: 30px 0;">

            <!-- Gestión de grupos -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Grupos de OpenWebUI', 'openwebui-sync'); ?></h2>
                <p><?php esc_html_e('Sincroniza los grupos disponibles en tu instancia de OpenWebUI y gestiona las asignaciones de alumnos por curso.', 'openwebui-sync'); ?></p>

                <div id="openwebui-groups-panel" class="openwebui-groups-panel">
                    <div class="openwebui-groups-column openwebui-groups-column--courses">
                        <div class="openwebui-groups-column-header">
                            <h3><?php esc_html_e('Cursos de LearnDash', 'openwebui-sync'); ?></h3>
                        </div>
                        <label class="screen-reader-text" for="openwebui-course-search"><?php esc_html_e('Buscar cursos de LearnDash', 'openwebui-sync'); ?></label>
                        <input type="search" id="openwebui-course-search" class="openwebui-groups-search" placeholder="<?php esc_attr_e('Buscar cursos…', 'openwebui-sync'); ?>" disabled="disabled" />
                        <div id="openwebui-courses-list" class="openwebui-groups-list" data-placeholder="<?php esc_attr_e('Selecciona un curso para comenzar.', 'openwebui-sync'); ?>" aria-live="polite">
                            <p class="openwebui-groups-empty"><?php esc_html_e('Cargando cursos…', 'openwebui-sync'); ?></p>
                        </div>
                    </div>
                    <div class="openwebui-groups-column openwebui-groups-column--groups">
                        <div class="openwebui-groups-column-header">
                            <h3><?php esc_html_e('Grupos de OpenWebUI', 'openwebui-sync'); ?></h3>
                            <button type="button" class="button button-secondary" id="refresh-groups-btn"><?php esc_html_e('Actualizar lista de grupos', 'openwebui-sync'); ?></button>
                        </div>
                        <div class="openwebui-groups-meta" id="openwebui-groups-meta">
                            <?php if (!empty($groups_updated_at)) : ?>
                                <span class="dashicons dashicons-update"></span>
                                <span><?php printf(esc_html__('Última actualización: %s', 'openwebui-sync'), esc_html($groups_updated_at)); ?></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-info"></span>
                                <span style="color: #d63638; font-weight: 600;"><?php esc_html_e('Sincronizando grupos automáticamente...', 'openwebui-sync'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div id="refresh-groups-result"></div>
                        <div id="openwebui-groups-list" class="openwebui-groups-list" data-placeholder="<?php esc_attr_e('Sin grupos disponibles. Sincroniza para empezar.', 'openwebui-sync'); ?>" aria-live="polite">
                            <?php if (empty($this->groups_cache['groups'])) : ?>
                                <div class="notice notice-info inline" style="margin: 15px 0; padding: 10px;">
                                    <p style="margin: 0;">
                                        <span class="dashicons dashicons-update" style="color: #2271b1;"></span>
                                        <strong><?php esc_html_e('Sincronización Automática', 'openwebui-sync'); ?></strong>
                                    </p>
                                    <p style="margin: 10px 0 0 0;">
                                        <?php esc_html_e('Los grupos de OpenWebUI se están sincronizando automáticamente desde tu instancia. Si no aparecen, pulsa el botón "Actualizar lista de grupos" para intentarlo manualmente.', 'openwebui-sync'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="openwebui-panel-preview" class="openwebui-panel-preview">
                    <p class="openwebui-panel-placeholder"><?php esc_html_e('Selecciona un curso y un grupo para previsualizar la sincronización.', 'openwebui-sync'); ?></p>
                </div>
            </div>

            <hr style="margin: 30px 0;">

            <!-- Información del LMS -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Información del Sistema LMS', 'openwebui-sync'); ?></h2>
                <?php $this->show_lms_info(); ?>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Sincronización masiva con filtros -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Sincronización Masiva', 'openwebui-sync'); ?></h2>
                <p><?php esc_html_e('Sincroniza usuarios según los filtros seleccionados. Primero previsualiza los usuarios antes de confirmar.', 'openwebui-sync'); ?></p>
                
                <?php if ($stats['unsynced'] > 0): ?>
                    <div class="openwebui-filter-box" style="margin: 20px 0; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                        <h3 style="margin-top: 0;"><?php esc_html_e('Filtros de sincronización', 'openwebui-sync'); ?></h3>
                        
                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th scope="row" style="width: 200px;">
                                    <label for="sync-filter-type"><?php esc_html_e('Filtrar por:', 'openwebui-sync'); ?></label>
                                </th>
                                <td style="min-width: 600px;">
                                    <select id="sync-filter-type" style="width: 300px;">
                                        <option value="">-- Seleccionar tipo de filtro --</option>
                                        <option value="date"><?php esc_html_e('Rango de fechas de registro', 'openwebui-sync'); ?></option>
                                        <option value="course"><?php esc_html_e('Usuarios inscritos en un curso', 'openwebui-sync'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="date-filter-container" style="display: none;">
                                <th scope="row">
                                    <label><?php esc_html_e('Rango de fechas:', 'openwebui-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="date" id="sync-filter-date-from" style="margin-right: 10px;">
                                    <span><?php esc_html_e('hasta', 'openwebui-sync'); ?></span>
                                    <input type="date" id="sync-filter-date-to" style="margin-left: 10px;">
                                    <p class="description"><?php esc_html_e('Sincroniza usuarios registrados entre estas fechas', 'openwebui-sync'); ?></p>
                                    <button type="button" class="button button-primary" id="preview-by-date-btn" style="margin-top: 10px;">
                                        <?php esc_html_e('Previsualizar Usuarios', 'openwebui-sync'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr id="course-filter-container" style="display: none;">
                                <th scope="row" style="vertical-align: top; padding-top: 20px;">
                                    <label><?php esc_html_e('Curso:', 'openwebui-sync'); ?></label>
                                </th>
                                <td>
                                    <div class="course-selector-wrapper" style="border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px; background: #fff;">
                                        <!-- Filtros integrados -->
                                        <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                                            <div style="flex: 1; min-width: 250px;">
                                                <input type="text" 
                                                       id="course-search-input" 
                                                       class="openwebui-search"
                                                       placeholder="🔍 <?php esc_attr_e('Buscar curso por nombre...', 'openwebui-sync'); ?>"
                                                       style="width: 100%; padding: 8px 12px;"
                                                       disabled>
                                            </div>
                                            <div style="flex: 1; min-width: 250px;">
                                                <select id="sync-filter-course-tag" style="width: 100%; padding: 8px;">
                                                    <option value=""><?php esc_html_e('🏷️ Todas las etiquetas', 'openwebui-sync'); ?></option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Lista de cursos -->
                                        <div style="position: relative;">
                                            <select id="sync-filter-course" 
                                                    style="width: 100%; height: 300px; padding: 8px; font-size: 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;" 
                                                    size="15">
                                                <option value=""><?php esc_html_e('Cargando cursos...', 'openwebui-sync'); ?></option>
                                            </select>
                                            <div id="course-count-info" style="margin-top: 8px; color: #646970; font-size: 13px;">
                                                <span id="visible-courses-count">0</span> <?php esc_html_e('cursos visibles de', 'openwebui-sync'); ?> <span id="total-courses-count">0</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <p class="description" style="margin-top: 10px;">
                                        <?php esc_html_e('💡 Usa los filtros para encontrar cursos rápidamente. Sincroniza solo usuarios inscritos en este curso (pendientes de sincronizar)', 'openwebui-sync'); ?>
                                    </p>
                                    
                                    <button type="button" class="button button-primary" id="preview-by-course-btn" style="margin-top: 15px;">
                                        <?php esc_html_e('Previsualizar Usuarios', 'openwebui-sync'); ?>
                                    </button>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div id="preview-container" style="display: none; margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;"></div>
                <?php else: ?>
                    <p><em><?php esc_html_e('No hay usuarios pendientes de sincronización.', 'openwebui-sync'); ?></em></p>
                <?php endif; ?>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Usuarios sincronizados -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Usuarios Sincronizados', 'openwebui-sync'); ?></h2>
                <?php $this->show_synced_users(); ?>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Usuarios pendientes -->
            <div class="openwebui-section">
                <h2><?php esc_html_e('Usuarios Pendientes', 'openwebui-sync'); ?></h2>
                <?php $this->show_unsynced_users(); ?>
            </div>
        </div>
        
        
        <?php
    }
}
