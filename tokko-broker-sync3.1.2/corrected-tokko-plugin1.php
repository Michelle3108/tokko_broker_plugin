<?php
/*
Plugin Name: Tokko → Estatik Sync (CORREGIDO - Mapeo Optimizado)
Description: Sincroniza propiedades desde Tokko Broker con mapeo corregido para Estatik
Version: 2.0.0 - FIXED
Author: Claude AI - Corrección de Mapeo
*/

if (!defined('ABSPATH')) exit;

// ---------------- CONSTANTES ----------------
define('TB_OPTION_API_KEY', 'tb_tokko_api_key');
define('TB_API_BASE', 'https://www.tokkobroker.com/api/v1/property/');
define('TB_CACHE_KEY', 'tb_tokko_properties_cache');
define('TB_BATCH_SIZE', 10); // Reducido para evitar timeouts
define('TB_MAX_EXECUTION_TIME', 25); // segundos antes de parar

// Mapeos fijos de taxonomías (CRÍTICO: usar IDs reales de tu BD)
define('TB_TAXONOMY_MAPPING', [
    // Categorías (es_category)
    'Sale' => 2,      // En venta
    'Rent' => 3,      // En renta
    
    // Tipos (es_type) - Mapeo Tokko → Estatik
    'House' => 4,     // Casas
    'Apartment' => 5, // Apartamentos
    'Condo' => 7,     // Condominios
    'Office' => 120,  // Office
    'Terreno' => 146, // Terreno
    'Casa' => 137,    // Casa (español)
    'Departamento' => 144, // Departamento
    
    // Estados (es_status)
    'active' => 10,   // Activo
    'pending' => 12,  // Pendiente
    'draft' => 13,    // Borrador
    'open' => 82,     // Open
    
    // Períodos de renta (es_rent_period)  
    'monthly' => 18,  // por mes
    'yearly' => 19,   // por año
    'daily' => 16,    // por día
    'weekly' => 17,   // por semana
]);

// ---------------- UTILIDADES ----------------
function tb_get_api_key() {
    return trim(get_option(TB_OPTION_API_KEY, ''));
}

function tb_detect_estatik_post_type() {
    $candidates = ['es_property', 'properties', 'property', 'es_properties'];
    foreach ($candidates as $pt) {
        if (post_type_exists($pt)) return $pt;
    }
    return 'es_property'; // fallback
}

// Mapear taxonomía usando IDs fijos
function tb_map_taxonomy_term($tokko_value, $taxonomy, $fallback_name = '') {
    $mapping = TB_TAXONOMY_MAPPING;
    
    // Intentar mapeo directo
    if (isset($mapping[$tokko_value])) {
        return (int)$mapping[$tokko_value];
    }
    
    // Fallback: crear término si no existe
    if (!empty($fallback_name)) {
        $term = term_exists($fallback_name, $taxonomy);
        if ($term) {
            return is_array($term) ? (int)$term['term_id'] : (int)$term;
        }
        
        $new_term = wp_insert_term($fallback_name, $taxonomy);
        if (!is_wp_error($new_term)) {
            return (int)$new_term['term_id'];
        }
    }
    
    return 0;
}

// ---------------- API FETCH CON CACHE ----------------
function tb_fetch_all_tokko_properties($api_key, $use_cache = true) {
    if ($use_cache) {
        $cached = get_transient(TB_CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    $all = [];
    $offset = 0;
    $limit = 20; //Antes estaba en 50
    
    if (empty($api_key)) return $all;

    while (true) {
        $url = add_query_arg([
            'key' => $api_key,
            'format' => 'json',
            'lang' => 'es_ar',
            'limit' => $limit,
            'offset' => $offset
        ], TB_API_BASE);

        $resp = wp_remote_get($url, ['timeout' => 60]);
        
        if (is_wp_error($resp)) {
            tb_log_error('API Error: ' . $resp->get_error_message());
            break;
        }
        
        $code = wp_remote_retrieve_response_code($resp);
        if ($code != 200) {
            tb_log_error("HTTP {$code} - API request failed");
            break;
        }
        
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        
        if (empty($data['objects'])) break;
        
        $count = count($data['objects']);
        $all = array_merge($all, $data['objects']);
        $offset += $count;
        
        tb_log_debug("Fetched batch: {$count} properties (total: " . count($all) . ")");
        
        if ($count < $limit) break;
        
        // Pausa para evitar rate limiting
        usleep(500000); // 0.5 segundos
    }
    
    // Cache por 1 hora
    if ($use_cache && !empty($all)) {
        set_transient(TB_CACHE_KEY, $all, HOUR_IN_SECONDS);
    }
    
    return $all;
}

// ---------------- LOGGING ----------------
function tb_log_error($message) {
    error_log('[Tokko Plugin ERROR] ' . $message);
    set_transient('tb_last_error', $message, 300); // 5 min
}

function tb_log_debug($message) {
    if (WP_DEBUG) {
        error_log('[Tokko Plugin DEBUG] ' . $message);
    }
}

// ---------------- IMAGEN OPTIMIZADA ----------------
function tb_handle_property_images($photos, $post_id) {
    if (empty($photos) || !is_array($photos)) return [];
    
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    $gallery_ids = [];
    $featured_set = false;
    
    // Ordenar por order y procesar máximo 20 imágenes
    usort($photos, fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
    $photos = array_slice($photos, 0, 20);
    
    foreach ($photos as $photo) {
        if (empty($photo['image'])) continue;
        
        $image_url = $photo['original'] ?? $photo['image'];
        
        // Verificar si ya existe
        $existing_id = attachment_url_to_postid($image_url);
        if ($existing_id) {
            $gallery_ids[] = $existing_id;
            if (!$featured_set && ($photo['is_front_cover'] ?? false)) {
                set_post_thumbnail($post_id, $existing_id);
                $featured_set = true;
            }
            continue;
        }
        
        // Descargar imagen
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            tb_log_error("Image download failed: " . $image_url);
            continue;
        }
        
        // Preparar archivo
        preg_match('/[^\?]+\.(jpg|jpeg|png|gif|webp)/i', $image_url, $matches);
        $filename = !empty($matches[0]) ? basename($matches[0]) : 'tokko_image_' . time() . '.jpg';
        
        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp
        ];
        
        // Subir imagen
        $attach_id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            tb_log_error("Image upload failed: " . $attach_id->get_error_message());
            continue;
        }
        
        $gallery_ids[] = $attach_id;
        
        // Establecer imagen destacada
        if (!$featured_set && ($photo['is_front_cover'] ?? false)) {
            set_post_thumbnail($post_id, $attach_id);
            $featured_set = true;
        }
    }
    
    // Si no hay imagen destacada, usar la primera
    if (!$featured_set && !empty($gallery_ids)) {
        set_post_thumbnail($post_id, $gallery_ids[0]);
    }
    
    return $gallery_ids;
}

// ---------------- FUNCIÓN PRINCIPAL: SYNC CORREGIDA ----------------
function tb_sync_and_map_corrected() {
    $start_time = microtime(true);
    $api_key = tb_get_api_key();
    $post_type = tb_detect_estatik_post_type();
    
    $result = [
        'imported' => 0,
        'updated' => 0,
        'errors' => [],
        'time' => 0
    ];

    if (empty($api_key)) {
        $result['errors'][] = 'API Key no configurada';
        return $result;
    }

    if (!$post_type) {
        $result['errors'][] = 'Post type de Estatik no encontrado';
        return $result;
    }

    // Fetch con cache
    $properties = tb_fetch_all_tokko_properties($api_key, true);
    
    if (empty($properties)) {
        $result['errors'][] = 'No se obtuvieron propiedades de Tokko API';
        return $result;
    }
    
    tb_log_debug("Processing " . count($properties) . " properties");
    
    // Procesar en lotes para evitar timeouts
    $batches = array_chunk($properties, TB_BATCH_SIZE);
    
    foreach ($batches as $batch_num => $batch) {
        tb_log_debug("Processing batch " . ($batch_num + 1) . "/" . count($batches));
        
        foreach ($batch as $prop) {
            try {
                $sync_result = tb_sync_single_property($prop, $post_type);
                if ($sync_result === 'imported') {
                    $result['imported']++;
                } elseif ($sync_result === 'updated') {
                    $result['updated']++;
                }
            } catch (Exception $e) {
                $result['errors'][] = "Property {$prop['id']}: " . $e->getMessage();
                tb_log_error("Sync error for property {$prop['id']}: " . $e->getMessage());
            }
        }
        
        // Pausa entre lotes
        if ($batch_num < count($batches) - 1) {
            usleep(250000); // 0.25 segundos
        }
    }
    
    $result['time'] = round(microtime(true) - $start_time, 2);
    tb_log_debug("Sync completed in {$result['time']} seconds");
    
    return $result;
}

function tb_sync_single_property($prop, $post_type) {
    if (empty($prop['id'])) {
        throw new Exception('Property without ID');
    }
    
    $external_id = sanitize_text_field($prop['id']);
    
    // Buscar existente
    $existing = get_posts([
        'post_type' => $post_type,
        'meta_query' => [
            ['key' => '_external_id', 'value' => $external_id, 'compare' => '=']
        ],
        'posts_per_page' => 1,
        'suppress_filters' => true
    ]);
    
    // Preparar datos del post
    $title = $prop['publication_title'] ?? $prop['address'] ?? 'Propiedad sin título';
    $content = $prop['rich_description'] ?? $prop['description'] ?? '';
    
    // Crear o actualizar post
    if (!empty($existing)) {
        $post_id = $existing[0]->ID;
        wp_update_post([
            'ID' => $post_id,
            'post_title' => wp_strip_all_tags($title),
            'post_content' => wp_kses_post($content)
        ]);
        $action = 'updated';
    } else {
        $post_id = wp_insert_post([
            'post_title' => wp_strip_all_tags($title),
            'post_content' => wp_kses_post($content),
            'post_status' => 'publish',
            'post_type' => $post_type
        ]);
        
        if (is_wp_error($post_id) || !$post_id) {
            throw new Exception('Failed to create post');
        }
        
        update_post_meta($post_id, '_external_id', $external_id);
        update_post_meta($post_id, '_tokko_id', $external_id);
        $action = 'imported';
    }
    
    // ========== MAPEO CORREGIDO DE CAMPOS ==========
    
    // 1. PRECIOS Y OPERACIONES (CORREGIDO)
    $price = '';
    $currency = '';
    $operation_type = '';
    
    if (!empty($prop['operations']) && is_array($prop['operations'])) {
        // Priorizar Sale, luego Rent
        $sale_op = array_filter($prop['operations'], fn($op) => strtolower($op['operation_type']) === 'sale');
        $rent_op = array_filter($prop['operations'], fn($op) => strtolower($op['operation_type']) === 'rent');
        
        $primary_op = !empty($sale_op) ? reset($sale_op) : (!empty($rent_op) ? reset($rent_op) : $prop['operations'][0]);
        
        if (!empty($primary_op['prices'][0]['price'])) {
            $price = $primary_op['prices'][0]['price'];
            $currency = $primary_op['prices'][0]['currency'] ?? 'USD';
            $operation_type = $primary_op['operation_type'];
        }
    }
    
    if ($price) update_post_meta($post_id, 'es_property_price', sanitize_text_field($price));
    if ($currency) update_post_meta($post_id, 'currency', sanitize_text_field($currency));
    
    // 2. CAMPOS BÁSICOS (CORREGIDOS)    
    if (!empty($prop['suite_amount'])) update_post_meta($post_id, 'es_property_bedrooms', intval($prop['suite_amount']));
    if (!empty($prop['room_amount'])) update_post_meta($post_id, 'es_property_total_rooms', intval($prop['room_amount']));
    if (!empty($prop['toilet_amount'])) update_post_meta($post_id, 'es_property_half_baths', intval($prop['toilet_amount']));

    if (!empty($prop['bathroom_amount'])) update_post_meta($post_id, 'es_property_bathrooms', intval($prop['bathroom_amount']));
    if (!empty($prop['floors_amount'])) update_post_meta($post_id, 'es_property_floors', intval($prop['floors_amount']));
    if (!empty($prop['parking_lot_amount'])) update_post_meta($post_id, 'es_property_parking', intval($prop['parking_lot_amount']));
    
    // 3. SUPERFICIES (CORREGIDAS)
    $total_area = $prop['total_surface'] ?? $prop['total_surface'] ?? '';
    if ($total_area) update_post_meta($post_id, 'es_property_area', sanitize_text_field($total_area));
    
    $built_area = $prop['roofed_surface'] ?? '';
    if ($built_area) update_post_meta($post_id, 'es_property_built_area', sanitize_text_field($built_area));
    
    $lot_size = $prop['surface'] ?? $prop['surface'] ?? '';
    if ($lot_size) update_post_meta($post_id, 'es_property_lot_size', sanitize_text_field($lot_size));
    
    // 4. COORDENADAS (NUEVO)
    if (!empty($prop['geo_lat'])) update_post_meta($post_id, 'es_property_latitude', sanitize_text_field($prop['geo_lat']));
    if (!empty($prop['geo_long'])) update_post_meta($post_id, 'es_property_longitude', sanitize_text_field($prop['geo_long']));
    
    // 5. OTROS CAMPOS
    if (!empty($prop['created_at'])) update_post_meta($post_id, 'date_added', sanitize_text_field($prop['created_at']));
    if (!empty($prop['expenses'])) update_post_meta($post_id, 'es_property_expenses', intval($prop['expenses']));
    if (!empty($prop['public_url'])) update_post_meta($post_id, 'tokko_public_url', esc_url_raw($prop['public_url']));
    if (!empty($prop['reference_code'])) update_post_meta($post_id, 'tokko_reference', sanitize_text_field($prop['reference_code']));
    
    // Dirección completa
    if (!empty($prop['address'])) {
        update_post_meta($post_id, 'es_property_address', sanitize_text_field($prop['address']));
    }
    
    // ========== TAXONOMÍAS (CORREGIDAS) ==========
    
    // Categoría (Sale/Rent)
    if ($operation_type) {
        $cat_term_id = tb_map_taxonomy_term($operation_type, 'es_category', $operation_type);
        if ($cat_term_id) wp_set_object_terms($post_id, [$cat_term_id], 'es_category', false);
    }
    
    // Tipo de propiedad
    if (!empty($prop['type']['name'])) {
        $type_term_id = tb_map_taxonomy_term($prop['type']['name'], 'es_type', $prop['type']['name']);
        if ($type_term_id) wp_set_object_terms($post_id, [$type_term_id], 'es_type', false);
    }
    
    // Estado
    $status_name = 'active'; // default
    if (isset($prop['status'])) {
        $status_name = is_numeric($prop['status']) ? 'status_' . $prop['status'] : $prop['status'];
    }
    $status_term_id = tb_map_taxonomy_term($status_name, 'es_status', 'Activo');
    if ($status_term_id) wp_set_object_terms($post_id, [$status_term_id], 'es_status', false);
    
    // ========== TAGS COMO AMENIDADES/CARACTERÍSTICAS ==========
    if (!empty($prop['tags']) && is_array($prop['tags'])) {
        $amenities = [];
        $features = [];
        
        foreach ($prop['tags'] as $tag) {
            $tag_name = $tag['name'] ?? '';
            $tag_type = $tag['type'] ?? 0;
            
            if (empty($tag_name)) continue;
            
            // Mapear por tipo de tag
            switch ($tag_type) {
                case 1: // Servicios básicos
                case 2: // Características internas
                    $features[] = $tag_name;
                    break;
                case 3: // Amenidades del edificio
                    $amenities[] = $tag_name;
                    break;
            }
        }
        
        // Asignar a taxonomías
        if (!empty($amenities)) {
            $amenity_ids = array_map(fn($name) => tb_map_taxonomy_term('', 'es_amenity', $name), $amenities);
            $amenity_ids = array_filter($amenity_ids);
            if (!empty($amenity_ids)) wp_set_object_terms($post_id, $amenity_ids, 'es_amenity', false);
        }
        
        if (!empty($features)) {
            $feature_ids = array_map(fn($name) => tb_map_taxonomy_term('', 'es_feature', $name), $features);
            $feature_ids = array_filter($feature_ids);
            if (!empty($feature_ids)) wp_set_object_terms($post_id, $feature_ids, 'es_feature', false);
        }
    }
    
    // ========== IMÁGENES ==========
    if (!empty($prop['photos'])) {
        $gallery_ids = tb_handle_property_images($prop['photos'], $post_id);
        if (!empty($gallery_ids)) {
            update_post_meta($post_id, '_es_gallery', $gallery_ids);
            update_post_meta($post_id, 'es_property_gallery', $gallery_ids);
        }
    }
    
    return $action;
}

// ---------------- ADMIN UI MEJORADA ----------------
add_action('admin_menu', function() {
    add_menu_page(
        'Tokko Sync',
        'Tokko Sync', 
        'manage_options',
        'tb-tokko',
        'tb_settings_page_improved',
        'dashicons-building',
        56
    );
    add_submenu_page(
        'tb-tokko',
        'Importar',
        'Importar',
        'manage_options',
        'tb-tokko-import',
        'tb_import_page_improved'
    );
});

function tb_settings_page_improved() {
    if (!current_user_can('manage_options')) return;
    
    if (isset($_POST['tb_save_api_key'])) {
        check_admin_referer('tb_save_api_key');
        $api_key = sanitize_text_field($_POST['tb_api_key']);
        update_option(TB_OPTION_API_KEY, $api_key);
        echo '<div class="notice notice-success"><p>✅ API Key guardada correctamente.</p></div>';
        
        // Limpiar cache
        delete_transient(TB_CACHE_KEY);
    }
    
    if (isset($_POST['tb_clear_cache'])) {
        check_admin_referer('tb_clear_cache');
        delete_transient(TB_CACHE_KEY);
        echo '<div class="notice notice-success"><p>🗑️ Cache limpiado.</p></div>';
    }
    
    $current_key = esc_attr(tb_get_api_key());
    $cache_info = get_transient(TB_CACHE_KEY);
    ?>
    <div class="wrap">
        <h1>🏠 Tokko Broker - Configuración</h1>
        
        <div class="card" style="max-width: 800px;">
            <h2>API Configuration</h2>
            <form method="post">
                <?php wp_nonce_field('tb_save_api_key'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tb_api_key">Tokko API Key</label></th>
                        <td>
                            <input id="tb_api_key" name="tb_api_key" type="password" 
                                   value="<?php echo $current_key; ?>" class="regular-text" />
                            <p class="description">
                                Tu API key de Tokko Broker. 
                                <a href="https://www.tokkobroker.com" target="_blank">Obtener API Key</a>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar API Key', 'primary', 'tb_save_api_key'); ?>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Cache Status</h2>
            <?php if ($cache_info): ?>
                <p>✅ Cache activo con <strong><?php echo count($cache_info); ?></strong> propiedades</p>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('tb_clear_cache'); ?>
                    <button type="submit" name="tb_clear_cache" class="button">🗑️ Limpiar Cache</button>
                </form>
            <?php else: ?>
                <p>❌ No hay cache activo</p>
            <?php endif; ?>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Mapeo de Taxonomías</h2>
            <p>El plugin mapea automáticamente:</p>
            <ul>
                <li><strong>Tokko Types</strong> → es_type (House→Casas, Apartment→Apartamentos)</li>
                <li><strong>Operations</strong> → es_category (Sale→En venta, Rent→En renta)</li>
                <li><strong>Tags tipo 3</strong> → es_amenity (amenidades del edificio)</li>
                <li><strong>Tags tipo 1-2</strong> → es_feature (características)</li>
            </ul>
        </div>
    </div>
    <?php
}

function tb_import_page_improved() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>📥 Importar Propiedades de Tokko</h1>
        
        <?php
        if (isset($_POST['tb_do_import'])) {
            check_admin_referer('tb_do_import');
            
            echo '<div style="background: #f0f0f1; padding: 15px; border-radius: 5px; margin: 20px 0;">';
            echo '<h3>🚀 Iniciando sincronización...</h3>';
            echo '<div id="import-progress">Procesando...</div>';
            echo '</div>';
            
            // Flush para mostrar progreso
            if (ob_get_level()) ob_flush();
            flush();
            
            $result = tb_sync_and_map_corrected();
            
            if (!empty($result['errors'])) {
                echo '<div class="notice notice-error"><ul>';
                foreach (array_slice($result['errors'], 0, 10) as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                if (count($result['errors']) > 10) {
                    echo '<li>... y ' . (count($result['errors']) - 10) . ' errores más</li>';
                }
                echo '</ul></div>';
            }
            
            echo '<div class="notice notice-success">';
            echo '<h3>✅ Sincronización Completada</h3>';
            echo '<p><strong>Importadas:</strong> ' . intval($result['imported']) . ' propiedades</p>';
            echo '<p><strong>Actualizadas:</strong> ' . intval($result['updated']) . ' propiedades</p>';
            echo '<p><strong>Tiempo:</strong> ' . $result['time'] . ' segundos</p>';
            echo '</div>';
        }
        ?>
        
        <div class="card" style="max-width: 800px;">
            <h2>Sincronizar Propiedades</h2>
            <p>Este proceso puede tardar varios minutos con muchas propiedades.</p>
            
            <?php
            $api_key = tb_get_api_key();
            if (empty($api_key)):
            ?>
                <div class="notice notice-warning">
                    <p>⚠️ Necesitas configurar tu API Key primero. 
                    <a href="admin.php?page=tb-tokko">Ir a Configuración</a></p>
                </div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('tb_do_import'); ?>
                    <p>
                        <button class="button button-primary button-large" type="submit" name="tb_do_import">
                            🔄 Sincronizar Ahora
                        </button>
                    </p>
                </form>
                
                <h3>Características de esta versión corregida:</h3>
                <ul>
                    <li>✅ Mapeo correcto de taxonomías por ID</li>
                    <li>✅ Procesamiento optimizado en lotes</li>
                    <li>✅ Cache de 1 hora para evitar API calls</li>
                    <li>✅ Coordenadas geográficas incluidas</li>
                    <li>✅ Tags mapeados a amenidades/características</li>
                    <li>✅ Manejo mejorado de imágenes</li>
                    <li>✅ Logs detallados para debug</li>
                </ul>
            <?php endif; ?>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>🔧 Debug Info</h2>
            <?php
            $last_error = get_transient('tb_last_error');
            $post_type = tb_detect_estatik_post_type();
            ?>
            <p><strong>Post Type detectado:</strong> <?php echo $post_type; ?></p>
            <p><strong>Último error:</strong> <?php echo $last_error ? esc_html($last_error) : 'Sin errores recientes'; ?></p>
        </div>
    </div>
    
    <script>
    // Auto-refresh para mostrar progreso
    if (document.getElementById('import-progress')) {
        let dots = 0;
        setInterval(() => {
            dots = (dots + 1) % 4;
            document.getElementById('import-progress').textContent = 'Procesando' + '.'.repeat(dots);
        }, 500);
    }
    </script>
    <?php
}

// Cleanup al desactivar
register_deactivation_hook(__FILE__, function() {
    delete_transient(TB_CACHE_KEY);
    delete_transient('tb_last_error');
});
?>