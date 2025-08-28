<?php
/*
Plugin Name: Tokko -> Estatik Sync (mapeo completo)
Description: Sincroniza propiedades desde Tokko Broker y mapea los metacampos solicitados (price_per_sqft, date_added, es_category, es_rent_period, es_type, es_status, bedrooms, bathrooms, total_rooms, floors, area, lot_size, year_built, year_remodeled).
Version: 1.0.0
Author: Generado para you
*/

if (!defined('ABSPATH')) exit;

// ---------------- CONSTANTES ----------------
if (!defined('TB_OPTION_API_KEY')) define('TB_OPTION_API_KEY', 'tb_tokko_api_key');
if (!defined('TB_API_BASE')) define('TB_API_BASE', 'https://www.tokkobroker.com/api/v1/property/');

// Añadir opción vacía por defecto
if (get_option(TB_OPTION_API_KEY) === false) add_option(TB_OPTION_API_KEY, '');

// ---------------- UTILIDADES ----------------
if (!function_exists('tb_get_api_key')) {
    function tb_get_api_key() {
        return trim(get_option(TB_OPTION_API_KEY, ''));
    }
}

if (!function_exists('tb_detect_estatik_post_type')) {
    function tb_detect_estatik_post_type() {
        $candidates = array('es_property','properties','property','es_properties','estatik_property');
        foreach ($candidates as $pt) {
            if (post_type_exists($pt)) return $pt;
        }
        global $wp_post_types;
        foreach ($wp_post_types as $pt => $obj) {
            $label = isset($obj->labels->name) ? $obj->labels->name : '';
            $sing  = isset($obj->labels->singular_name) ? $obj->labels->singular_name : '';
            if (stripos($label,'estatik')!==false || stripos($label,'Property')!==false || stripos($sing,'Property')!==false) return $pt;
        }
        return false;
    }
}

// Crea/obtiene término por nombre en la taxonomía dada (devuelve term_id)
if (!function_exists('tb_get_or_create_term')) {
    function tb_get_or_create_term($term_name, $taxonomy) {
        if (empty($term_name) || empty($taxonomy)) return 0;
        $term_name = trim($term_name);
        $term = term_exists($term_name, $taxonomy);
        if ($term !== 0 && $term !== null) {
            if (is_array($term)) return (int)$term['term_id'];
            return (int)$term;
        }
        // crear
        $new = wp_insert_term($term_name, $taxonomy);
        if (!is_wp_error($new) && isset($new['term_id'])) return (int)$new['term_id'];
        return 0;
    }
}

// ---------------- API: fetch con paginación ----------------
if (!function_exists('tb_fetch_all_tokko_properties')) {
    function tb_fetch_all_tokko_properties($api_key, $limit = 50) {
        $all = array();
        $offset = 0;
        if (empty($api_key)) return $all;

        while (true) {
            $url = add_query_arg(array(
                'key' => $api_key,
                'format' => 'json',
                'lang' => 'es_ar',
                'limit' => $limit,
                'offset' => $offset
            ), TB_API_BASE);

            $resp = wp_remote_get($url, array('timeout'=>40));
            if (is_wp_error($resp)) {
                set_transient('tb_last_error', 'wp_remote_get error: ' . $resp->get_error_message(), 60);
                break;
            }
            $code = wp_remote_retrieve_response_code($resp);
            if ($code != 200) {
                set_transient('tb_last_error', "Tokko API HTTP {$code}", 60);
                break;
            }
            $body = wp_remote_retrieve_body($resp);
            $data = json_decode($body, true);
            if (empty($data) || !isset($data['objects']) || empty($data['objects'])) break;

            $count = count($data['objects']);
            $all = array_merge($all, $data['objects']);
            $offset += $count;
            if ($count < $limit) break;
        }
        return $all;
    }
}

// ---------------- IMAGEN: sideload ----------------
if (!function_exists('tb_sideload_image')) {
    function tb_sideload_image($image_url, $post_id) {
        if (empty($image_url) || empty($post_id)) return false;
        require_once(ABSPATH.'wp-admin/includes/file.php');
        require_once(ABSPATH.'wp-admin/includes/media.php');
        require_once(ABSPATH.'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) return false;

        $file_array = array();
        preg_match('/[^\?]+\.(jpg|jpeg|png|gif|webp)/i', $image_url, $matches);
        $file_array['name'] = !empty($matches[0]) ? basename($matches[0]) : basename($image_url);
        $file_array['tmp_name'] = $tmp;

        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }
        return $id;
    }
}

// ---------------- FUNCION PRINCIPAL: sync y mapeo ----------------
if (!function_exists('tb_sync_and_map')) {
    function tb_sync_and_map() {
        $api_key = tb_get_api_key();
        $post_type = tb_detect_estatik_post_type();
        $result = array('imported'=>0,'updated'=>0,'errors'=>array());

        if (empty($api_key)) { $result['errors'][] = 'API Key no configurada.'; return $result; }
        if (!$post_type) { $result['errors'][] = 'No se detectó post_type de Estatik (es_property).'; return $result; }

        $properties = tb_fetch_all_tokko_properties($api_key, 50);
        if (empty($properties)) { $result['errors'][] = 'No se obtuvieron propiedades (revisa tb_last_error).'; return $result; }

        foreach ($properties as $prop) {
            if (empty($prop['id'])) { $result['errors'][] = 'Propiedad sin id (skipped).'; continue; }
            $external_id = sanitize_text_field($prop['id']);

            // Buscar existente por meta _external_id o _tokko_id
            $existing = get_posts(array(
                'post_type' => $post_type,
                'meta_query' => array(
                    array('key'=>'_external_id','value'=>$external_id,'compare'=>'='),
                ),
                'posts_per_page' => 1,
                'suppress_filters' => true
            ));
            if (empty($existing)) {
                // probar por _tokko_id antiguo
                $existing = get_posts(array(
                    'post_type' => $post_type,
                    'meta_query' => array(
                        array('key'=>'_tokko_id','value'=>$external_id,'compare'=>'='),
                    ),
                    'posts_per_page'=>1,
                    'suppress_filters'=>true
                ));
            }

            // Titulo y contenido
            $title = !empty($prop['publication_title']) ? $prop['publication_title'] : (!empty($prop['address'])? $prop['address'] : 'Propiedad sin título');
            $content = !empty($prop['description']) ? $prop['description'] : (!empty($prop['rich_description']) ? $prop['rich_description'] : '');

            if (!empty($existing)) {
                $post_id = $existing[0]->ID;
                wp_update_post(array('ID'=>$post_id,'post_title'=>wp_strip_all_tags($title),'post_content'=>wp_kses_post($content)));
                $result['updated']++;
            } else {
                $post_id = wp_insert_post(array('post_title'=>wp_strip_all_tags($title),'post_content'=>wp_kses_post($content),'post_status'=>'publish','post_type'=>$post_type));
                if (is_wp_error($post_id) || !$post_id) {
                    $result['errors'][] = 'Error creando post para external_id '.$external_id;
                    continue;
                }
                update_post_meta($post_id,'_external_id',$external_id);
                update_post_meta($post_id,'_tokko_id',$external_id);
                $result['imported']++;
            }

            // ---------- MAPEOS de metacampos solicitados ----------
            // 1) price: elegir venta si existe, sino primer precio
            $price = '';
            $rent_period = '';
            if (!empty($prop['operations']) && is_array($prop['operations'])) {
                // Buscar Sale
                foreach ($prop['operations'] as $op) {
                    if (!empty($op['operation_type']) && strtolower($op['operation_type']) == 'sale') {
                        if (!empty($op['prices'][0]['price'])) { $price = $op['prices'][0]['price']; $currency = $op['prices'][0]['currency'] ?? ''; break; }
                    }
                }
                if ($price === '') {
                    // fallback al primer precio disponible
                    if (!empty($prop['operations'][0]['prices'][0]['price'])) {
                        $price = $prop['operations'][0]['prices'][0]['price'];
                        $currency = $prop['operations'][0]['prices'][0]['currency'] ?? '';
                    }
                }
                // rent period si aplica (si viene en period o similar)
                if (!empty($prop['operations'][0]['prices'][0]['period'])) {
                    $rent_period = $prop['operations'][0]['prices'][0]['period'];
                }
            } elseif (!empty($prop['price'])) {
                $price = $prop['price'];
                $currency = $prop['currency']['name'] ?? '';
            } else {
                $currency = $prop['currency']['name'] ?? '';
            }

            if ($price !== '') update_post_meta($post_id, 'price', sanitize_text_field($price));
            if (!empty($currency)) update_post_meta($post_id, 'currency', sanitize_text_field($currency));

            // date_added
            if (!empty($prop['created_at'])) update_post_meta($post_id, 'date_added', sanitize_text_field($prop['created_at']));

            // bedrooms: uso room_amount o suite_amount fallback
            $bedrooms = $prop['room_amount'] ?? $prop['room'] ?? $prop['rooms'] ?? $prop['suite_amount'] ?? '';
            if ($bedrooms !== '') update_post_meta($post_id, 'bedrooms', intval($bedrooms));

            // bathrooms: bathroom_amount + toilet_amount fallback
            $bath = 0;
            if (!empty($prop['bathroom_amount'])) $bath += intval($prop['bathroom_amount']);
            if (!empty($prop['toilet_amount'])) $bath += intval($prop['toilet_amount']);
            if ($bath == 0 && !empty($prop['bathrooms'])) $bath = intval($prop['bathrooms']);
            if ($bath > 0) update_post_meta($post_id, 'bathrooms', $bath);

            // total_rooms
            $total_rooms = 0;
            if (!empty($prop['room_amount'])) $total_rooms += intval($prop['room_amount']);
            if (!empty($prop['suite_amount'])) $total_rooms += intval($prop['suite_amount']);
            if ($total_rooms > 0) update_post_meta($post_id, 'total_rooms', $total_rooms);

            // floors
            if (!empty($prop['floors_amount'])) update_post_meta($post_id, 'floors', intval($prop['floors_amount']));

            // area / área
            $area_val = '';
            if (!empty($prop['surface'])) $area_val = $prop['surface'];
            elseif (!empty($prop['total_surface'])) $area_val = $prop['total_surface'];
            elseif (!empty($prop['roofed_surface'])) $area_val = $prop['roofed_surface'];
            if ($area_val !== '') {
                $area_val_num = str_replace(',', '.', $area_val);
                update_post_meta($post_id, 'area', $area_val_num);
                update_post_meta($post_id, 'área', $area_val_num);
            }

            // lot_size
            $lot = '';
            if (!empty($prop['unroofed_surface'])) $lot = $prop['unroofed_surface'];
            elseif (!empty($prop['front_measure'])) $lot = $prop['front_measure'];
            if ($lot !== '') update_post_meta($post_id, 'lot_size', sanitize_text_field($lot));

            // year_built / year_remodeled
            if (!empty($prop['year_built'])) update_post_meta($post_id, 'year_built', sanitize_text_field($prop['year_built']));
            if (!empty($prop['year_remodeled'])) update_post_meta($post_id, 'year_remodeled', sanitize_text_field($prop['year_remodeled']));

            // price_per_sqft
            if (!empty($price) && !empty($area_val) && floatval($area_val) > 0) {
                $ppsq = floatval($price) / floatval($area_val);
                update_post_meta($post_id, 'price_per_sqft', round($ppsq,2));
            } elseif (!empty($prop['price_per_sqft'])) {
                update_post_meta($post_id, 'price_per_sqft', sanitize_text_field($prop['price_per_sqft']));
            }

            // ---------------- TAXONOMIAS ----------------
            if (!empty($prop['type']['name'])) {
                $term_id = tb_get_or_create_term($prop['type']['name'], 'es_category');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_category', false);
            } elseif (!empty($prop['category'])) {
                $term_id = tb_get_or_create_term($prop['category'], 'es_category');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_category', false);
            }

            if (!empty($prop['type']['name'])) {
                $term_id = tb_get_or_create_term($prop['type']['name'], 'es_type');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_type', false);
            }

            if (isset($prop['status'])) {
                $status_name = is_numeric($prop['status']) ? 'status_'.$prop['status'] : $prop['status'];
                $term_id = tb_get_or_create_term($status_name, 'es_status');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_status', false);
            } elseif (!empty($prop['operations'][0]['operation_type'])) {
                $term_id = tb_get_or_create_term($prop['operations'][0]['operation_type'], 'es_status');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_status', false);
            }

            if (!empty($rent_period)) {
                $term_id = tb_get_or_create_term((string)$rent_period, 'es_rent_period');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_rent_period', false);
            } elseif (!empty($prop['has_temporary_rent']) && $prop['has_temporary_rent']===true) {
                $term_id = tb_get_or_create_term('temporary', 'es_rent_period');
                if ($term_id) wp_set_object_terms($post_id, array((int)$term_id), 'es_rent_period', false);
            }

            // ---------------- GALLERY & THUMBNAIL ----------------
            if (!empty($prop['photos']) && is_array($prop['photos'])) {
                $gallery_ids = array();
                foreach ($prop['photos'] as $i => $photo) {
                    if (!empty($photo['image'])) {
                        $attach_id = false;
                        $attach_id = attachment_url_to_postid($photo['original'] ?? $photo['image']);
                        if (!$attach_id) $attach_id = tb_sideload_image($photo['image'], $post_id);
                        if ($attach_id) {
                            if ($i === 0) set_post_thumbnail($post_id, $attach_id);
                            $gallery_ids[] = $attach_id;
                        }
                    }
                }
                if (!empty($gallery_ids)) update_post_meta($post_id, '_es_gallery', $gallery_ids);
            }

        } // foreach properties

        return $result;
    }
}

// ---------------- ADMIN UI ----------------
if (!function_exists('tb_admin_menu')) {
    function tb_admin_menu() {
        add_menu_page('Tokko Broker','Tokko Broker','manage_options','tb-tokko','tb_settings_page','dashicons-building',56);
        add_submenu_page('tb-tokko','Importar','Importar','manage_options','tb-tokko-import','tb_import_page');
    }
    add_action('admin_menu','tb_admin_menu');
}

if (!function_exists('tb_settings_page')) {
    function tb_settings_page() {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['tb_save_api_key'])) {
            check_admin_referer('tb_save_api_key');
            $k = sanitize_text_field($_POST['tb_api_key']);
            update_option(TB_OPTION_API_KEY, $k);
            echo '<div class="notice notice-success"><p>API Key guardada.</p></div>';
        }
        $current = esc_attr(tb_get_api_key());
        ?>
        <div class="wrap"><h1>Tokko Broker - Ajustes</h1>
        <form method="post"><?php wp_nonce_field('tb_save_api_key'); ?>
            <table class="form-table">
                <tr><th><label for="tb_api_key">API Key</label></th>
                    <td><input id="tb_api_key" name="tb_api_key" type="text" value="<?php echo $current; ?>" class="regular-text"></td></tr>
            </table>
            <?php submit_button('Guardar API Key','primary','tb_save_api_key'); ?>
        </form>
        <p>Después de guardar la API Key, ve a Importar → Sincronizar ahora.</p>
        </div>
        <?php
    }
}

if (!function_exists('tb_import_page')) {
    function tb_import_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap"><h1>Importar propiedades desde Tokko</h1>
        <?php
        if (isset($_POST['tb_do_import'])) {
            check_admin_referer('tb_do_import');
            $res = tb_sync_and_map();
            if (!empty($res['errors'])) echo '<div class="notice notice-error"><p>Errores: '. esc_html(implode(' | ',$res['errors'])) .'</p></div>';
            echo '<div class="notice notice-success"><p>Importadas: '.intval($res['imported']).' — Actualizadas: '.intval($res['updated']).'</p></div>';
        }
        ?>
        <form method="post"><?php wp_nonce_field('tb_do_import'); ?>
            <p>Este proceso puede tardar según la cantidad de propiedades.</p>
            <p><button class="button button-primary" type="submit" name="tb_do_import">Sincronizar ahora</button></p>
        </form>
        <h2>Debug</h2>
        <p>Último error (transient): <?php echo esc_html(get_transient('tb_last_error') ?: 'No hay errores recientes.'); ?></p>
        </div>
        <?php
    }
}

// limpiar transient al desactivar
if (!function_exists('tb_deactivate')) {
    function tb_deactivate() { delete_transient('tb_last_error'); }
    register_deactivation_hook(__FILE__,'tb_deactivate');
}
