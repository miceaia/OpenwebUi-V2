<?php
/**
 * Course to OpenWebUI group mappings management.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve API base URL configured for OpenWebUI.
 *
 * @return string
 */
function micea_owui_get_api_base_url() {
    $base_url = get_option( 'openwebui_api_url', '' );

    if ( empty( $base_url ) ) {
        $base_url = get_option( 'owui_base_url', '' );
    }

    $base_url = is_string( $base_url ) ? trim( $base_url ) : '';

    if ( empty( $base_url ) ) {
        return '';
    }

    $base_url = untrailingslashit( esc_url_raw( $base_url ) );

    if ( ! empty( $base_url ) ) {
        $base_url = set_url_scheme( $base_url, 'https' );
    }

    return $base_url;
}

/**
 * Retrieve API key configured for OpenWebUI.
 *
 * @return string
 */
function micea_owui_get_api_key() {
    $api_key = get_option( 'openwebui_api_key', '' );

    if ( empty( $api_key ) ) {
        $api_key = get_option( 'owui_api_key', '' );
    }

    return sanitize_text_field( (string) $api_key );
}

/**
 * Perform an API request against OpenWebUI.
 *
 * @param string     $method HTTP method.
 * @param string     $path   API path.
 * @param array|null $body   Optional request body.
 *
 * @return array|WP_Error
 */
function micea_owui_api_request( $method, $path, $body = null ) {
    $base_url = micea_owui_get_api_base_url();
    $api_key  = micea_owui_get_api_key();

    if ( empty( $base_url ) ) {
        return new WP_Error( 'owui_missing_base_url', __( 'No se ha configurado la URL base de OpenWebUI.', 'openwebui-sync' ) );
    }

    if ( empty( $api_key ) ) {
        return new WP_Error( 'owui_missing_api_key', __( 'No se ha configurado la clave de API de OpenWebUI.', 'openwebui-sync' ) );
    }

    $url  = trailingslashit( $base_url ) . ltrim( $path, '/' );
    $args = array(
        'method'  => strtoupper( $method ),
        'timeout' => defined( 'OPENWEBUI_API_TIMEOUT' ) ? OPENWEBUI_API_TIMEOUT : 15,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
    );

    if ( null !== $body ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code >= 400 ) {
        return new WP_Error(
            'owui_http_error',
            sprintf( __( 'Error %1$d al llamar a OpenWebUI: %2$s', 'openwebui-sync' ), $code, wp_remote_retrieve_body( $response ) ),
            array(
                'status' => $code,
                'data'   => $data,
            )
        );
    }

    return array(
        'status' => $code,
        'data'   => is_array( $data ) ? $data : array(),
        'raw'    => $response,
    );
}

/**
 * Get or create an OpenWebUI user for a WordPress user ID.
 *
 * @param int $wp_user_id WordPress user ID.
 *
 * @return int|WP_Error OpenWebUI user ID or error.
 */
function micea_owui_get_or_create_owui_user( $wp_user_id ) {
    $user = get_user_by( 'id', $wp_user_id );

    if ( ! $user ) {
        return new WP_Error( 'owui_missing_user', __( 'No se pudo localizar el usuario de WordPress.', 'openwebui-sync' ) );
    }

    $response = micea_owui_api_request( 'GET', '/api/users?email=' . rawurlencode( $user->user_email ) );

    if ( is_wp_error( $response ) && 'owui_http_error' === $response->get_error_code() ) {
        $data   = $response->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
        if ( 404 === $status ) {
            $response = array( 'data' => array() );
        }
    }

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    if ( ! empty( $response['data'] ) && isset( $response['data']['id'] ) ) {
        return absint( $response['data']['id'] );
    }

    if ( isset( $response['data'][0]['id'] ) ) {
        return absint( $response['data'][0]['id'] );
    }

    $create_body = array(
        'email' => $user->user_email,
        'name'  => trim( $user->display_name ) ? $user->display_name : $user->user_login,
    );

    $created = micea_owui_api_request( 'POST', '/api/users', $create_body );

    if ( is_wp_error( $created ) ) {
        return $created;
    }

    if ( isset( $created['data']['id'] ) ) {
        return absint( $created['data']['id'] );
    }

    return new WP_Error( 'owui_create_failed', __( 'No se pudo crear el usuario en OpenWebUI.', 'openwebui-sync' ) );
}

/**
 * Add a user to an OpenWebUI group.
 *
 * @param int $owui_user_id  OpenWebUI user ID.
 * @param int $owui_group_id OpenWebUI group ID.
 *
 * @return true|WP_Error
 */
function micea_owui_add_user_to_group( $owui_user_id, $owui_group_id ) {
    $response = micea_owui_api_request(
        'POST',
        '/api/groups/' . rawurlencode( $owui_group_id ) . '/members',
        array( 'userId' => (int) $owui_user_id )
    );

    if ( is_wp_error( $response ) ) {
        $data   = $response->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

        if ( 409 === $status ) {
            return true;
        }

        return $response;
    }

    return true;
}

/**
 * Remove a user from an OpenWebUI group.
 *
 * @param int $owui_user_id  OpenWebUI user ID.
 * @param int $owui_group_id OpenWebUI group ID.
 *
 * @return true|WP_Error
 */
function micea_owui_remove_user_from_group( $owui_user_id, $owui_group_id ) {
    $response = micea_owui_api_request(
        'DELETE',
        '/api/groups/' . rawurlencode( $owui_group_id ) . '/members/' . rawurlencode( $owui_user_id )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return true;
}

/**
 * Helper to retrieve the current admin URL.
 *
 * @return string
 */
function micea_owui_get_current_admin_url() {
    $query_args = array();

    foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $query_args[ sanitize_key( $key ) ] = is_array( $value )
            ? array_map( 'sanitize_text_field', wp_unslash( $value ) )
            : sanitize_text_field( wp_unslash( $value ) );
    }

    if ( empty( $query_args['page'] ) ) {
        $query_args['page'] = 'micea-owui-mappings';
    }

    return add_query_arg( $query_args, admin_url( 'admin.php' ) );
}

/**
 * Retrieve OpenWebUI groups from cache or remote API.
 *
 * @param bool $force_refresh Whether to bypass the transient cache.
 *
 * @return array|WP_Error Associative array of id => name or error.
 */
function micea_owui_get_groups( $force_refresh = false ) {
    $cache_key = 'owui_groups_cache';

    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }
    }

    $response       = micea_owui_api_request( 'GET', '/api/groups' );
    $fallback_paths = array( '/api/v1/groups' );

    if ( is_wp_error( $response ) ) {
        $data   = $response->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

        if ( in_array( $status, array( 400, 404 ), true ) ) {
            foreach ( $fallback_paths as $fallback_path ) {
                $response = micea_owui_api_request( 'GET', $fallback_path );

                if ( ! is_wp_error( $response ) ) {
                    break;
                }
            }
        }
    }

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $payload = isset( $response['data'] ) ? $response['data'] : array();
    $groups  = array();

    $group_lists = micea_owui_normalize_groups_payload( $payload );

    foreach ( $group_lists as $group ) {
        if ( ! is_array( $group ) ) {
            continue;
        }

        $raw_id   = isset( $group['id'] ) ? $group['id'] : ( $group['_id'] ?? '' );
        $raw_name = isset( $group['name'] ) ? $group['name'] : ( $group['title'] ?? '' );

        if ( '' === $raw_id || '' === $raw_name ) {
            continue;
        }

        $groups[ sanitize_text_field( (string) $raw_id ) ] = sanitize_text_field( (string) $raw_name );
    }

    set_transient( $cache_key, $groups, MINUTE_IN_SECONDS * 15 );

    return $groups;
}

/**
 * Normalise different OpenWebUI group payload structures into a flat array.
 *
 * @param array $payload Raw payload.
 *
 * @return array
 */
function micea_owui_normalize_groups_payload( $payload ) {
    if ( ! is_array( $payload ) ) {
        return array();
    }

    // Numeric array already represents a list of groups.
    if ( $payload === array_values( $payload ) ) {
        return $payload;
    }

    $candidate_keys = array( 'data', 'groups', 'items' );

    foreach ( $candidate_keys as $key ) {
        if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
            $normalized = micea_owui_normalize_groups_payload( $payload[ $key ] );
            if ( ! empty( $normalized ) ) {
                return $normalized;
            }
        }
    }

    $collected = array();

    foreach ( $payload as $value ) {
        if ( is_array( $value ) ) {
            $nested = micea_owui_normalize_groups_payload( $value );
            if ( ! empty( $nested ) ) {
                $collected = array_merge( $collected, $nested );
            }
        }
    }

    if ( ! empty( $collected ) ) {
        return $collected;
    }

    if ( isset( $payload['id'] ) || isset( $payload['_id'] ) ) {
        return array( $payload );
    }

    return array();
}

/**
 * Handle admin action to refresh cached groups.
 */
function micea_owui_refresh_groups() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos suficientes.', 'openwebui-sync' ) );
    }

    check_admin_referer( 'micea_owui_refresh_groups' );

    delete_transient( 'owui_groups_cache' );

    $groups = micea_owui_get_groups( true );

    if ( is_wp_error( $groups ) ) {
        add_settings_error(
            'micea_owui_groups',
            'micea_owui_groups_error',
            $groups->get_error_message(),
            'error'
        );
    } elseif ( empty( $groups ) ) {
        add_settings_error(
            'micea_owui_groups',
            'micea_owui_groups_empty',
            esc_html__( 'La llamada fue correcta pero no se recibieron grupos desde OpenWebUI. Verifica que existan grupos disponibles.', 'openwebui-sync' ),
            'warning'
        );
    } else {
        $count = count( $groups );
        add_settings_error(
            'micea_owui_groups',
            'micea_owui_groups_success',
            sprintf( /* translators: %d: number of groups */ esc_html__( 'Lista de grupos actualizada correctamente. Se recuperaron %d grupos.', 'openwebui-sync' ), $count ),
            'updated'
        );
    }

    $redirect = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url( 'admin.php?page=micea-owui-mappings' );

    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_micea_owui_refresh_groups', 'micea_owui_refresh_groups' );

/**
 * Register course metabox for selecting OpenWebUI group.
 */
function micea_owui_register_course_metabox() {
    add_meta_box(
        'micea-owui-course-group',
        esc_html__( 'Grupo OpenWebUI', 'openwebui-sync' ),
        'micea_owui_render_course_metabox',
        'sfwd-courses',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes_sfwd-courses', 'micea_owui_register_course_metabox' );

/**
 * Render the OpenWebUI group metabox.
 *
 * @param WP_Post $post Current post object.
 */
function micea_owui_render_course_metabox( $post ) {
    wp_nonce_field( 'micea_owui_save_course', 'micea_owui_course_nonce' );

    $assigned_group = get_post_meta( $post->ID, 'owui_group_id', true );
    $groups         = micea_owui_get_groups();

    if ( is_wp_error( $groups ) ) {
        echo '<p>' . esc_html( $groups->get_error_message() ) . '</p>';
        $redirect = rawurlencode( get_edit_post_link( $post->ID, 'url' ) );
        $refresh  = wp_nonce_url( admin_url( 'admin-post.php?action=micea_owui_refresh_groups&redirect_to=' . $redirect ), 'micea_owui_refresh_groups' );

        echo '<p><a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'Actualizar lista de grupos', 'openwebui-sync' ) . '</a></p>';
        return;
    }

    echo '<p>';
    echo '<label for="micea-owui-group-select" class="screen-reader-text">' . esc_html__( 'Selecciona el grupo de OpenWebUI', 'openwebui-sync' ) . '</label>';
    echo '<select id="micea-owui-group-select" name="owui_group_id" class="widefat">';
    echo '<option value="">' . esc_html__( '— Sin asignar —', 'openwebui-sync' ) . '</option>';

    foreach ( $groups as $group_id => $group_name ) {
        printf(
            '<option value="%1$s" %2$s>%3$s</option>',
            esc_attr( $group_id ),
            selected( (string) $assigned_group, (string) $group_id, false ),
            esc_html( $group_name )
        );
    }

    echo '</select>';
    echo '</p>';

    echo '<p>';
    echo '<label><input type="checkbox" name="micea_owui_sync_now" value="1" /> ' . esc_html__( 'Sincronizar ahora', 'openwebui-sync' ) . '</label>';
    echo '</p>';

    $redirect = rawurlencode( get_edit_post_link( $post->ID, 'url' ) );
    $refresh  = wp_nonce_url( admin_url( 'admin-post.php?action=micea_owui_refresh_groups&redirect_to=' . $redirect ), 'micea_owui_refresh_groups' );

    echo '<p><a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'Actualizar lista de grupos', 'openwebui-sync' ) . '</a></p>';
}

/**
 * Save course meta for OpenWebUI group assignment.
 *
 * @param int $post_id Post ID.
 */
function micea_owui_save_course_metabox( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! isset( $_POST['micea_owui_course_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['micea_owui_course_nonce'] ) ), 'micea_owui_save_course' ) ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $group_id = isset( $_POST['owui_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['owui_group_id'] ) ) : '';

    if ( '' === $group_id ) {
        delete_post_meta( $post_id, 'owui_group_id' );
    } else {
        update_post_meta( $post_id, 'owui_group_id', $group_id );
    }

    if ( ! empty( $_POST['micea_owui_sync_now'] ) && '' !== $group_id ) {
        $result = micea_owui_resync_course( $post_id );

        if ( is_array( $result ) ) {
            $message = sprintf(
                /* translators: 1: success count, 2: error count */
                esc_html__( 'Sincronización completada. %1$d usuarios añadidos, %2$d errores.', 'openwebui-sync' ),
                intval( $result['success'] ),
                intval( $result['errors'] )
            );

            if ( $result['queued'] > 0 ) {
                $message .= ' ' . sprintf(
                    /* translators: %d: queued count */
                    esc_html__( '%d usuarios pendientes se han encolado para reintento.', 'openwebui-sync' ),
                    intval( $result['queued'] )
                );
            }

            add_settings_error( 'micea_owui_mappings', 'micea_owui_metabox_sync', $message, 'updated' );
        } elseif ( is_wp_error( $result ) ) {
            add_settings_error( 'micea_owui_mappings', 'micea_owui_metabox_sync_error', $result->get_error_message(), 'error' );
        }
    } elseif ( ! empty( $_POST['micea_owui_sync_now'] ) ) {
        add_settings_error( 'micea_owui_mappings', 'micea_owui_metabox_sync_missing', esc_html__( 'Asigna un grupo antes de sincronizar.', 'openwebui-sync' ), 'error' );
    }
}
add_action( 'save_post_sfwd-courses', 'micea_owui_save_course_metabox', 20 );

/**
 * Add custom column to courses list table.
 */
function micea_owui_add_courses_column( $columns ) {
    $columns['micea_owui_group'] = esc_html__( 'Grupo OWUI', 'openwebui-sync' );
    return $columns;
}
add_filter( 'manage_sfwd-courses_posts_columns', 'micea_owui_add_courses_column' );

/**
 * Render custom column value.
 */
function micea_owui_render_courses_column( $column, $post_id ) {
    if ( 'micea_owui_group' !== $column ) {
        return;
    }

    $group_id = get_post_meta( $post_id, 'owui_group_id', true );

    if ( empty( $group_id ) ) {
        echo '&#8212;';
        return;
    }

    $groups = micea_owui_get_groups();

    if ( is_wp_error( $groups ) ) {
        echo esc_html__( 'Error al cargar', 'openwebui-sync' );
        return;
    }

    if ( isset( $groups[ $group_id ] ) ) {
        echo esc_html( $groups[ $group_id ] );
    } else {
        echo esc_html( $group_id );
    }
}
add_action( 'manage_sfwd-courses_posts_custom_column', 'micea_owui_render_courses_column', 10, 2 );

/**
 * Admin notice renderer for mapping actions.
 */
function micea_owui_render_admin_notices() {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if ( 'micea-owui-mappings' === $page ) {
        return;
    }

    settings_errors( 'micea_owui_mappings' );
    settings_errors( 'micea_owui_groups' );
}
add_action( 'admin_notices', 'micea_owui_render_admin_notices' );

/**
 * Register submenu page for mappings.
 */
function micea_owui_register_mappings_submenu() {
    add_submenu_page(
        'learndash-lms',
        esc_html__( 'Mapeos de Cursos OWUI', 'openwebui-sync' ),
        esc_html__( 'Mapeos OWUI', 'openwebui-sync' ),
        'manage_options',
        'micea-owui-mappings',
        'micea_owui_render_mappings_page'
    );
}
add_action( 'admin_menu', 'micea_owui_register_mappings_submenu' );

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Course mappings table.
 */
class Micea_OWUI_Course_Mapping_Table extends WP_List_Table {

    /**
     * Cached groups.
     *
     * @var array
     */
    protected $groups = array();

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            array(
                'plural' => 'micea-owui-courses',
                'singular' => 'micea-owui-course',
                'ajax' => false,
            )
        );

        $groups = micea_owui_get_groups();

        if ( is_wp_error( $groups ) ) {
            add_settings_error( 'micea_owui_groups', 'micea_owui_groups_table', $groups->get_error_message(), 'error' );
            $groups = array();
        }

        $this->groups = $groups;
    }

    /**
     * Get table columns.
     */
    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'id' => esc_html__( 'ID', 'openwebui-sync' ),
            'title' => esc_html__( 'Curso', 'openwebui-sync' ),
            'students' => esc_html__( 'Alumnos', 'openwebui-sync' ),
            'group' => esc_html__( 'Grupo OWUI', 'openwebui-sync' ),
            'actions' => esc_html__( 'Acciones', 'openwebui-sync' ),
        );
    }

    /**
     * Default column rendering.
     */
    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'id':
                return esc_html( $item['ID'] );
            case 'title':
                $edit_link = get_edit_post_link( $item['ID'] );
                return sprintf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( $edit_link ), esc_html( $item['post_title'] ) );
            case 'students':
                return esc_html( number_format_i18n( $item['students'] ) );
            default:
                return '';
        }
    }

    /**
     * Checkbox column.
     */
    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="course_ids[]" value="%d" />', absint( $item['ID'] ) );
    }

    /**
     * Render group column.
     */
    protected function column_group( $item ) {
        $current = isset( $item['group_id'] ) ? (string) $item['group_id'] : '';

        $output  = '<select name="group_assignments[' . esc_attr( $item['ID'] ) . ']" class="micea-owui-group-select">';
        $output .= '<option value="">' . esc_html__( '— Sin asignar —', 'openwebui-sync' ) . '</option>';

        foreach ( $this->groups as $group_id => $group_name ) {
            $output .= sprintf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $group_id ),
                selected( $current, (string) $group_id, false ),
                esc_html( $group_name )
            );
        }

        $output .= '</select>';

        return $output;
    }

    /**
     * Render actions column.
     */
    protected function column_actions( $item ) {
        $save_button   = sprintf(
            '<button type="submit" class="button button-primary" name="micea_owui_single_action[%1$d]" value="save">%2$s</button>',
            absint( $item['ID'] ),
            esc_html__( 'Guardar mapeo', 'openwebui-sync' )
        );
        $resync_button = sprintf(
            '<button type="submit" class="button" name="micea_owui_single_action[%1$d]" value="resync">%2$s</button>',
            absint( $item['ID'] ),
            esc_html__( 'Resincronizar', 'openwebui-sync' )
        );

        return $save_button . ' ' . $resync_button;
    }

    /**
     * Bulk actions.
     */
    public function get_bulk_actions() {
        return array(
            'save' => esc_html__( 'Guardar mapeo (seleccionados)', 'openwebui-sync' ),
            'resync' => esc_html__( 'Resincronizar (seleccionados)', 'openwebui-sync' ),
        );
    }

    /**
     * Prepare table items.
     */
    public function prepare_items() {
        $per_page     = $this->get_items_per_page( 'micea_owui_courses_per_page', 20 );
        $current_page = $this->get_pagenum();
        $search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $tag_filter   = isset( $_REQUEST['ld_tag'] ) ? absint( $_REQUEST['ld_tag'] ) : 0;

        $columns               = $this->get_columns();
        $hidden                = array();
        $sortable              = array();
        $this->_column_headers = array( $columns, $hidden, $sortable );

        $query_args = array(
            'post_type'      => 'sfwd-courses',
            'post_status'    => array( 'publish', 'draft', 'future' ),
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            's'              => $search,
        );

        if ( $tag_filter ) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'ld_course_tag',
                    'field'    => 'term_id',
                    'terms'    => $tag_filter,
                ),
            );
        }

        $query = new WP_Query( $query_args );

        $items = array();

        foreach ( $query->posts as $post ) {
            $students = array();

            if ( function_exists( 'ld_get_enrolled_users' ) ) {
                $students = ld_get_enrolled_users( $post->ID );
            }

            $items[] = array(
                'ID'         => $post->ID,
                'post_title' => get_the_title( $post ),
                'students'   => is_array( $students ) ? count( $students ) : 0,
                'group_id'   => get_post_meta( $post->ID, 'owui_group_id', true ),
            );
        }

        $this->items = $items;

        $this->set_pagination_args(
            array(
                'total_items' => (int) $query->found_posts,
                'per_page'    => $per_page,
                'total_pages' => (int) $query->max_num_pages,
            )
        );

        wp_reset_postdata();
    }
}

/**
 * Handle mappings form submissions.
 */
function micea_owui_handle_mappings_form() {
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
        return;
    }

    if ( ! isset( $_POST['micea_owui_mappings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['micea_owui_mappings_nonce'] ) ), 'micea_owui_mappings_action' ) ) {
        add_settings_error( 'micea_owui_mappings', 'micea_owui_invalid_nonce', esc_html__( 'La acción no es válida.', 'openwebui-sync' ), 'error' );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        add_settings_error( 'micea_owui_mappings', 'micea_owui_cap', esc_html__( 'No tienes permisos para realizar esta acción.', 'openwebui-sync' ), 'error' );
        return;
    }

    $single_action = isset( $_POST['micea_owui_single_action'] ) ? (array) wp_unslash( $_POST['micea_owui_single_action'] ) : array();
    $bulk_action   = '';

    if ( isset( $_POST['action'] ) && '-1' !== $_POST['action'] ) {
        $bulk_action = sanitize_key( wp_unslash( $_POST['action'] ) );
    } elseif ( isset( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) {
        $bulk_action = sanitize_key( wp_unslash( $_POST['action2'] ) );
    }

    $group_assignments = array();

    if ( isset( $_POST['group_assignments'] ) && is_array( $_POST['group_assignments'] ) ) {
        foreach ( wp_unslash( $_POST['group_assignments'] ) as $course_key => $group_value ) {
            $group_assignments[ absint( $course_key ) ] = sanitize_text_field( $group_value );
        }
    }

    $course_ids = array();
    $action     = '';

    if ( ! empty( $single_action ) ) {
        foreach ( $single_action as $course_id => $value ) {
            $course_ids[] = absint( $course_id );
            $action       = sanitize_key( $value );
            break;
        }
    } elseif ( ! empty( $bulk_action ) ) {
        $action     = $bulk_action;
        $course_ids = isset( $_POST['course_ids'] ) ? array_map( 'absint', (array) $_POST['course_ids'] ) : array();
    }

    if ( empty( $action ) || empty( $course_ids ) ) {
        return;
    }

    $action = in_array( $action, array( 'save', 'resync' ), true ) ? $action : 'save';

    $updated = 0;
    $synced  = 0;
    $errors  = array();
    $queued  = 0;

    foreach ( $course_ids as $course_id ) {
        $group_id = isset( $group_assignments[ $course_id ] ) ? $group_assignments[ $course_id ] : '';

        if ( '' === $group_id ) {
            delete_post_meta( $course_id, 'owui_group_id' );
        } else {
            update_post_meta( $course_id, 'owui_group_id', sanitize_text_field( $group_id ) );
        }

        $updated++;

        if ( 'resync' === $action ) {
            if ( '' === $group_id ) {
                $errors[] = sprintf( '%s: %s', wp_strip_all_tags( get_the_title( $course_id ) ), esc_html__( 'Sin grupo asignado para sincronizar.', 'openwebui-sync' ) );
                continue;
            }
            $result = micea_owui_resync_course( $course_id );

            if ( is_wp_error( $result ) ) {
                $errors[] = sprintf( '%1$s: %2$s', wp_strip_all_tags( get_the_title( $course_id ) ), esc_html( $result->get_error_message() ) );
                continue;
            }

            if ( is_array( $result ) ) {
                $synced += (int) $result['success'];
                $queued += (int) $result['queued'];

                if ( $result['errors'] > 0 ) {
                    $errors[] = sprintf(
                        '%1$s: %2$d errores.',
                        wp_strip_all_tags( get_the_title( $course_id ) ),
                        intval( $result['errors'] )
                    );
                }
            }
        }
    }

    if ( 'resync' === $action ) {
        $message = sprintf(
            /* translators: 1: updated courses, 2: synced users, 3: queued users */
            esc_html__( '%1$d cursos actualizados. %2$d usuarios sincronizados.', 'openwebui-sync' ),
            $updated,
            $synced
        );

        if ( $queued > 0 ) {
            $message .= ' ' . sprintf(
                esc_html__( '%d usuarios pendientes se han encolado para reintento.', 'openwebui-sync' ),
                $queued
            );
        }

        add_settings_error( 'micea_owui_mappings', 'micea_owui_resync', $message, 'updated' );
    } else {
        add_settings_error(
            'micea_owui_mappings',
            'micea_owui_saved',
            sprintf( esc_html__( 'Se guardaron %d cursos.', 'openwebui-sync' ), $updated ),
            'updated'
        );
    }

    if ( ! empty( $errors ) ) {
        add_settings_error( 'micea_owui_mappings', 'micea_owui_resync_errors', implode( ' ', array_map( 'esc_html', $errors ) ), 'error' );
    }

    $redirect_args = array(
        'page' => 'micea-owui-mappings',
    );

    if ( isset( $_POST['s'] ) && '' !== $_POST['s'] ) {
        $redirect_args['s'] = sanitize_text_field( wp_unslash( $_POST['s'] ) );
    }

    if ( isset( $_POST['ld_tag'] ) && '' !== $_POST['ld_tag'] ) {
        $redirect_args['ld_tag'] = absint( wp_unslash( $_POST['ld_tag'] ) );
    }

    if ( isset( $_POST['paged'] ) && '' !== $_POST['paged'] ) {
        $redirect_args['paged'] = absint( wp_unslash( $_POST['paged'] ) );
    }

    wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Render the mappings admin page.
 */
function micea_owui_render_mappings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'openwebui-sync' ) );
    }

    micea_owui_handle_mappings_form();

    $table = new Micea_OWUI_Course_Mapping_Table();
    $table->prepare_items();

    $current_url = micea_owui_get_current_admin_url();
    $refresh_url = wp_nonce_url( add_query_arg( array(
        'action'      => 'micea_owui_refresh_groups',
        'redirect_to' => rawurlencode( $current_url ),
    ), admin_url( 'admin-post.php' ) ), 'micea_owui_refresh_groups' );

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Mapeos de Cursos OWUI', 'openwebui-sync' ) . '</h1>';

    settings_errors( 'micea_owui_mappings' );
    settings_errors( 'micea_owui_groups' );

    echo '<p><a class="button" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Actualizar lista de grupos', 'openwebui-sync' ) . '</a></p>';

    echo '<form method="get" class="micea-owui-filters">';
    echo '<input type="hidden" name="page" value="micea-owui-mappings" />';
    echo '<p class="search-box">';
    $table->search_box( esc_html__( 'Buscar cursos', 'openwebui-sync' ), 'micea-owui-search' );
    echo '</p>';

    $tag_filter = isset( $_REQUEST['ld_tag'] ) ? absint( $_REQUEST['ld_tag'] ) : 0;
    $taxonomy   = get_taxonomy( 'ld_course_tag' );

    if ( $taxonomy ) {
        wp_dropdown_categories(
            array(
                'show_option_all' => __( 'Todas las etiquetas', 'openwebui-sync' ),
                'taxonomy'        => 'ld_course_tag',
                'name'            => 'ld_tag',
                'orderby'         => 'name',
                'selected'        => $tag_filter,
                'hierarchical'    => false,
                'hide_empty'      => false,
            )
        );
        submit_button( esc_html__( 'Filtrar', 'openwebui-sync' ), '', 'filter_action', false );
    }

    echo '</form>';

    $search_value = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
    $tag_value    = isset( $_REQUEST['ld_tag'] ) ? absint( $_REQUEST['ld_tag'] ) : 0;
    $paged_value  = isset( $_REQUEST['paged'] ) ? absint( $_REQUEST['paged'] ) : 0;

    echo '<form method="post">';
    wp_nonce_field( 'micea_owui_mappings_action', 'micea_owui_mappings_nonce' );
    echo '<input type="hidden" name="page" value="micea-owui-mappings" />';
    echo '<input type="hidden" name="s" value="' . esc_attr( $search_value ) . '" />';
    echo '<input type="hidden" name="ld_tag" value="' . esc_attr( $tag_value ) . '" />';
    if ( $paged_value > 0 ) {
        echo '<input type="hidden" name="paged" value="' . esc_attr( $paged_value ) . '" />';
    }
    $table->display();
    echo '</form>';
    echo '</div>';
}

/**
 * Queue a course resync.
 *
 * @param int $course_id Course ID.
 */
function micea_owui_queue_course_resync( $course_id ) {
    $course_id = absint( $course_id );

    if ( ! $course_id ) {
        return;
    }

    if ( ! wp_next_scheduled( 'micea_owui_process_resync', array( $course_id ) ) ) {
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'micea_owui_process_resync', array( $course_id ) );
    }
}

/**
 * Process a queued resync event.
 *
 * @param int $course_id Course ID.
 */
function micea_owui_process_resync_event( $course_id ) {
    micea_owui_resync_course( $course_id, true );
}

add_action( 'micea_owui_process_resync', 'micea_owui_process_resync_event', 10, 1 );

/**
 * Resync all enrolled users for a course with its mapped OpenWebUI group.
 *
 * @param int  $course_id Course ID.
 * @param bool $is_retry  Whether this execution comes from the scheduled queue.
 *
 * @return array|WP_Error
 */
function micea_owui_resync_course( $course_id, $is_retry = false ) {
    $course_id = absint( $course_id );

    if ( ! $course_id ) {
        return new WP_Error( 'owui_invalid_course', __( 'Curso no válido para sincronización.', 'openwebui-sync' ) );
    }

    $group_id = get_post_meta( $course_id, 'owui_group_id', true );

    if ( empty( $group_id ) ) {
        return new WP_Error( 'owui_missing_group', __( 'El curso no tiene un grupo de OpenWebUI asignado.', 'openwebui-sync' ) );
    }

    if ( ! function_exists( 'ld_get_enrolled_users' ) ) {
        return new WP_Error( 'owui_missing_learndash', __( 'La función de LearnDash para obtener alumnos no está disponible.', 'openwebui-sync' ) );
    }

    $users = ld_get_enrolled_users( $course_id );

    if ( is_wp_error( $users ) ) {
        return $users;
    }

    $users   = is_array( $users ) ? $users : array();
    $success = 0;
    $errors  = 0;
    $queued  = 0;

    foreach ( $users as $wp_user_id ) {
        $owui_user = micea_owui_get_or_create_owui_user( $wp_user_id );

        if ( is_wp_error( $owui_user ) ) {
            $errors++;
            continue;
        }

        $result = micea_owui_add_user_to_group( $owui_user, $group_id );

        if ( is_wp_error( $result ) ) {
            $data   = $result->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

            if ( in_array( $status, array( 429, 500, 502, 503, 504 ), true ) && ! $is_retry ) {
                micea_owui_queue_course_resync( $course_id );
                $queued++;
                continue;
            }

            $errors++;
            continue;
        }

        $success++;
    }

    return array(
        'success' => $success,
        'errors'  => $errors,
        'queued'  => $queued,
    );
}
