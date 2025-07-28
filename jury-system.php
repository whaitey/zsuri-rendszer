<?php
/**
 * Plugin Name: Zsűri Rendszer
 * Description: Zsűri felhasználók és kategóriák adminisztrációja, értékelés funkcióval.
 * Version: 1.0
 * Author: ZeusWeb
 */

if (!defined('ABSPATH')) exit;

// Aktiváláskor szükséges adatbázis táblák létrehozása
register_activation_hook(__FILE__, 'crp_install');
function crp_install() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'crp_ratings';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        criteria longtext NOT NULL,
        comment text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $history_table = $wpdb->prefix . 'crp_ratings_history';
    $sql .= "CREATE TABLE $history_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        rating_id bigint(20) NOT NULL,
        criteria longtext NOT NULL,
        comment text,
        edited_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Admin menük regisztrálása
add_action('admin_menu', 'zsuri_admin_menu');
function zsuri_admin_menu() {
    add_menu_page(
        'Zsűri rendszer',        // Oldal címe (title)
        'Zsűri rendszer',        // Menü neve (menu title)
        'manage_options',
        'zsuri-system',          // Menü slug
        'crp_criteria_page'      // Callback
    );
    add_submenu_page(
        'zsuri-system',          // Szülő slug (főmenü)
        'Értékelési szempontok', // Oldal címe
        'Értékelési szempontok', // Almenü neve
        'manage_options',
        'zsuri-system',          // Ugyanaz, mint a főmenü slugja!
        'crp_criteria_page'      // Callback
    );
    add_submenu_page(
        'zsuri-system',
        'Értékelések',
        'Értékelések',
        'manage_options',
        'crp_ratings',
        'crp_ratings_page'
    );
    add_submenu_page(
        'zsuri-system', // vagy a főmenü slugja, nálad ez valószínűleg 'zsuri-system'
        'People zsűri értékelések',
        'People zsűri értékelések',
        'manage_options',
        'people-jury-results',
        'zsuri_people_jury_results_page'
    );
    add_submenu_page(
        'zsuri-system',
        'Kategóriák',
        'Kategóriák',
        'manage_options',
        'zsuri-categories',
        'zsuri_categories_page'
    );
    add_submenu_page(
        'zsuri-system',
        'Zsűri tagok',
        'Zsűri tagok',
        'manage_options',
        'zsuri-users',
        'zsuri_users_page'
    );
}

add_action('admin_init', 'zsuri_export_people_jury_csv_init');
function zsuri_export_people_jury_csv_init() {
    if (
        isset($_GET['export_people_jury_csv']) &&
        isset($_GET['jury_category']) &&
        current_user_can('manage_options')
    ) {
        zsuri_export_people_jury_results_csv(intval($_GET['jury_category']));
        exit;
    }
}


// Szempontok oldal
function crp_criteria_page() {
    if (isset($_POST['criteria'])) {
        $criteria = array_map('sanitize_text_field', $_POST['criteria']);
        $criteria = array_filter($criteria);
        update_option('crp_criteria', $criteria);
        echo '<div class="updated"><p>Szempontok frissítve!</p></div>';
    }
    $criteria = get_option('crp_criteria', []);
    ?>
    <div class="wrap">
        <h1>Értékelési szempontok</h1>
        <form method="post">
            <table>
                <tbody id="criteria-list">
                    <?php foreach ($criteria as $c): ?>
                        <tr>
                            <td><input type="text" name="criteria[]" value="<?php echo esc_attr($c); ?>" /></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><input type="text" name="criteria[]" value="" placeholder="Új szempont..." /></td>
                    </tr>
                </tbody>
            </table>
            <button type="submit" class="button button-primary">Mentés</button>
        </form>
    </div>
    <?php
}

// Értékelések exportálása (szűrővel)
add_action('admin_init', 'crp_export_ratings');
function crp_export_ratings() {
    if (isset($_POST['crp_export']) && current_user_can('manage_options')) {
        global $wpdb;
        $table = $wpdb->prefix . 'crp_ratings';
        $history_table = $wpdb->prefix . 'crp_ratings_history';
        $criteria = get_option('crp_criteria', []);
        $where = '';
        if (isset($_POST['filter_post']) && intval($_POST['filter_post']) > 0) {
            $where = $wpdb->prepare("WHERE r.post_id = %d", intval($_POST['filter_post']));
        }
        $query = "SELECT r.*, p.post_title, u.user_login 
                  FROM $table r
                  LEFT JOIN {$wpdb->posts} p ON r.post_id = p.ID
                  LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                  $where
                  ORDER BY r.created_at DESC";
        $results = $wpdb->get_results($query);

        $filename = 'ertekelesek_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        // Header row: Bejegyzés, Felhasználó, each criterion, Átlag, Megjegyzés, Utolsó módosítás, Előzmények
        $header = ['Bejegyzés', 'Felhasználó'];
        foreach ($criteria as $crit) {
            $header[] = $crit;
        }
        $header[] = 'Átlag';
        $header[] = 'Megjegyzés';
        $header[] = 'Utolsó módosítás';
        $header[] = 'Előzmények';
        fputcsv($out, $header);

        // Data rows
        foreach ($results as $r) {
            $c = json_decode($r->criteria, true);
            $row = [
                $r->post_title,
                $r->user_login
            ];
            foreach ($criteria as $i => $crit) {
                $row[] = intval($c[$i] ?? 0);
            }
            // Calculate average
            if (!empty($c) && is_array($c)) {
                $sum = array_sum($c);
                $count = count($c);
                $average = $count > 0 ? round($sum / $count, 2) : 0;
            } else {
                $average = 0;
            }
            $row[] = $average;
            $row[] = $r->comment;
            $row[] = $r->created_at;

            // Előzmények string (history)
            $history_str = '';
            $history = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $history_table WHERE rating_id = %d ORDER BY edited_at DESC",
                $r->id
            ));
            if ($history) {
                foreach ($history as $h) {
                    $hc = json_decode($h->criteria, true);
                    $history_str .= '[Módosítás: ' . $h->edited_at . '] ';
                    foreach ($criteria as $i => $crit) {
                        $history_str .= $crit . ': ' . intval($hc[$i] ?? 0) . '; ';
                    }
                    $history_str .= 'Megjegyzés: ' . $h->comment . ' | ';
                }
            } else {
                $history_str = 'Nincs előzmény';
            }
            $row[] = $history_str;

            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}



// Értékelés TÖRLÉSE (egyenként vagy tömegesen)
add_action('admin_init', 'crp_delete_ratings');
function crp_delete_ratings() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'crp_ratings';
    $history_table = $wpdb->prefix . 'crp_ratings_history';

    // Egyedi törlés link
    if (isset($_GET['crp_delete_rating']) && check_admin_referer('crp_delete_rating_' . intval($_GET['crp_delete_rating']))) {
        $id = intval($_GET['crp_delete_rating']);
        $wpdb->delete($history_table, ['rating_id' => $id]);
        $wpdb->delete($table, ['id' => $id]);
        wp_redirect(remove_query_arg(['crp_delete_rating', '_wpnonce']));
        exit;
    }

    // Tömeges törlés
    if (isset($_POST['crp_bulk_delete']) && isset($_POST['crp_rating_ids']) && is_array($_POST['crp_rating_ids'])) {
        foreach ($_POST['crp_rating_ids'] as $id) {
            $id = intval($id);
            $wpdb->delete($history_table, ['rating_id' => $id]);
            $wpdb->delete($table, ['id' => $id]);
        }
        wp_redirect(remove_query_arg(['crp_bulk_delete', 'crp_rating_ids']));
        exit;
    }
}

// Egyszerű admin értékeléslista bejegyzés szűrővel, átlag oszloppal, törlés opcióval
function crp_ratings_page() {
    global $wpdb;
    $ratings_table = $wpdb->prefix . 'crp_ratings';
    $criteria = get_option('crp_criteria', []);

    // Bejegyzések lekérdezése, amelyekhez van értékelés
    $rated_posts = $wpdb->get_results(
        "SELECT DISTINCT p.ID, p.post_title
         FROM {$wpdb->prefix}crp_ratings r
         JOIN {$wpdb->posts} p ON r.post_id = p.ID
         ORDER BY p.post_title"
    );
    // Felhasználók lekérdezése, akik értékeltek
    $users = $wpdb->get_results(
        "SELECT DISTINCT u.ID, u.user_login
         FROM {$wpdb->prefix}crp_ratings r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         ORDER BY u.user_login"
    );

    $current_post = isset($_GET['filter_post']) ? intval($_GET['filter_post']) : 0;
    $current_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;

    // Pagination
    $per_page = 25;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    echo '<div class="wrap"><h1>Értékelések</h1>';

    // Szűrő űrlap
    echo '<form method="get" style="margin-bottom:20px;">';
    echo '<input type="hidden" name="page" value="crp_ratings">';
    // Post filter
    echo '<label for="filter-by-post">Szűrés bejegyzés szerint: </label>';
    echo '<select name="filter_post" id="filter-by-post">';
    echo '<option value="0">Minden bejegyzés</option>';
    foreach ($rated_posts as $post) {
        echo '<option value="' . esc_attr($post->ID) . '" ' . selected($current_post, $post->ID, false) . '>' . esc_html($post->post_title) . '</option>';
    }
    echo '</select> ';
    // User filter
    echo '<label for="filter-by-user" style="margin-left:20px;">Szűrés felhasználó szerint: </label>';
    echo '<select name="filter_user" id="filter-by-user">';
    echo '<option value="0">Minden felhasználó</option>';
    foreach ($users as $user) {
        echo '<option value="' . esc_attr($user->ID) . '" ' . selected($current_user, $user->ID, false) . '>' . esc_html($user->user_login) . '</option>';
    }
    echo '</select> ';
    submit_button('Szűrés', '', '', false);
    echo '</form>';

    // Export gomb
    echo '<form method="post" style="margin-bottom:20px;">';
    echo '<input type="hidden" name="crp_export" value="1">';
    if ($current_post) {
        echo '<input type="hidden" name="filter_post" value="' . esc_attr($current_post) . '">';
    }
    if ($current_user) {
        echo '<input type="hidden" name="filter_user" value="' . esc_attr($current_user) . '">';
    }
    submit_button('Exportálás CSV-be', 'secondary', '', false);
    echo '</form>';

    // Build WHERE conditions for filters
    $where = [];
    if ($current_post) {
        $where[] = $wpdb->prepare("post_id = %d", $current_post);
    }
    if ($current_user) {
        $where[] = $wpdb->prepare("user_id = %d", $current_user);
    }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get total count for pagination
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $ratings_table $where_sql");

    // Lekérjük az értékeléseket a szűrő és lapozás szerint
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $ratings_table $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );

    if (empty($results)) {
        echo '<div class="notice notice-info"><p>Nincsenek értékelések</p></div>';
    } else {
        // Tömeges törlés űrlap
        echo '<form method="post" onsubmit="return confirm(\'Biztosan törlöd a kijelölt értékeléseket?\');">';
        echo '<input type="hidden" name="crp_bulk_delete" value="1">';
        echo '<table class="widefat"><thead><tr>';
        echo '<th><input type="checkbox" id="crp-check-all"></th>';
        echo '<th>ID</th>';
        echo '<th>Bejegyzés</th>';
        echo '<th>Felhasználó</th>';
        foreach ($criteria as $crit) {
            echo '<th>' . esc_html($crit) . '</th>';
        }
        echo '<th>Átlag</th>';
        echo '<th>Megjegyzés</th>';
        echo '<th>Létrehozva</th>';
        echo '<th>Művelet</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($results as $r) {
            $user = get_userdata($r->user_id);
            $post = get_post($r->post_id);
            $c = json_decode($r->criteria, true);
            
            echo '<tr>';
            echo '<td><input type="checkbox" name="crp_rating_ids[]" value="' . esc_attr($r->id) . '"></td>';
            echo '<td>' . esc_html($r->id) . '</td>';
            echo '<td>' . ($post ? '<a href="' . get_permalink($post) . '" target="_blank">' . esc_html($post->post_title) . '</a>' : '–') . '</td>';
            echo '<td>' . ($user ? esc_html($user->user_login) : '–') . '</td>';
            
            // Szempontok megjelenítése (csak szám)
            foreach ($criteria as $i => $crit) {
                echo '<td>' . intval($c[$i] ?? 0) . '</td>';
            }
            
            // Átlag számolása
            $average = 0;
            if (!empty($c) && is_array($c)) {
                $sum = array_sum($c);
                $count = count($c);
                $average = $count > 0 ? round($sum / $count, 2) : 0;
            }
            echo '<td>' . esc_html($average) . '</td>';
            
            echo '<td>' . esc_html($r->comment) . '</td>';
            echo '<td>' . esc_html($r->created_at) . '</td>';
            // Egyedi törlés link
            $delete_url = wp_nonce_url(
                add_query_arg(['crp_delete_rating' => $r->id]),
                'crp_delete_rating_' . $r->id
            );
            echo '<td><a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Biztosan törlöd ezt az értékelést?\');">Törlés</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        submit_button('Kijelöltek törlése', 'delete');
        echo '</form>';

        // Pagination links
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1) {
            $base_url = remove_query_arg('paged');
            echo '<div class="tablenav"><div class="tablenav-pages">';
            if ($current_page > 1) {
                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">&laquo; Előző</a> ';
            }
            echo ' Oldal ' . $current_page . ' / ' . $total_pages . ' ';
            if ($current_page < $total_pages) {
                echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">Következő &raquo;</a>';
            }
            echo '</div></div>';
        }

        // Check all JS
        echo "<script>
        document.getElementById('crp-check-all').onclick = function() {
            var checkboxes = document.querySelectorAll('input[name=\"crp_rating_ids[]\"]');
            for(var i=0;i<checkboxes.length;i++){checkboxes[i].checked=this.checked;}
        };
        </script>";
    }
    echo '</div>';
}


// Értékelő űrlap hozzáadása (18px-es label)
add_filter('the_content', 'crp_add_rating_form', 20);
function crp_add_rating_form($content) {
    if (!is_single() || !in_the_loop() || !is_main_query()) return $content;
    if (!is_user_logged_in()) return $content;

    // Only show form if post is in an active zsuri category
    $active_categories = get_option('zsuri_available_categories', []);
    if (empty($active_categories) || !is_array($active_categories)) return $content;

    $post_cats = wp_get_post_categories(get_the_ID());
    if (!array_intersect($active_categories, $post_cats)) return $content;

    $user = wp_get_current_user();
    $allowed_roles = ['zsuri', 'administrator'];
    $has_role = false;

    foreach ($allowed_roles as $role) {
        if (in_array($role, $user->roles)) {
            $has_role = true;
            break;
        }
    }

    if (!$has_role) return $content;

    $criteria = get_option('crp_criteria', []);
    if (empty($criteria)) return $content;

    global $wpdb;
    $table = $wpdb->prefix . 'crp_ratings';
    $post_id = get_the_ID();
    $existing_rating = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE post_id = %d AND user_id = %d",
        $post_id,
        $user->ID
    ));

    ob_start();

    // --- SCROLL ANCHOR (for JS offset scroll) ---
    ?>
    <div id="rating-form-anchor" style="height: 0; padding: 0; margin: 0;"></div>
    <?php

    // --- ERROR MESSAGE (if backend validation fails) ---
    if ($msg = get_transient('crp_rating_error_' . get_current_user_id())) {
        ?>
        <div class="crp-error" style="color:red;font-weight:bold;">
            <?php echo esc_html($msg); ?>
        </div>
        <?php
        delete_transient('crp_rating_error_' . get_current_user_id());
    }

    // --- RATING FORM ---
    ?>
    <div class="crp-rating-box">
        <h3>Értékelés</h3>
        <form method="post" class="crp-rating-form">
            <?php wp_nonce_field('crp_rating', 'crp_rating_nonce'); ?>
            <input type="hidden" name="crp_post_id" value="<?php echo $post_id; ?>">
            <?php foreach ($criteria as $i => $c):
                $current_value = $existing_rating ? json_decode($existing_rating->criteria, true)[$i] : 0;
            ?>
                <div class="crp-criterion">
                    <label style="font-size:18px !important;"><?php echo esc_html($c); ?></label>
                    <span class="crp-stars" data-index="<?php echo $i; ?>">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="crp-star <?php echo ($current_value >= $s) ? 'selected' : ''; ?>" data-star="<?php echo $s; ?>">&#9734;</span>
                        <?php endfor; ?>
                        <input type="hidden" name="crp_criteria[<?php echo $i; ?>]" value="<?php echo $current_value; ?>">
                    </span>
                </div>
            <?php endforeach; ?>
            <div class="crp-comment-section">
                <label>Saját jegyzeteim:</label><br>
                <textarea name="crp_comment" rows="3" cols="50"><?php echo $existing_rating ? esc_textarea($existing_rating->comment) : ''; ?></textarea>
            </div>
            <button type="submit" class="button"><?php echo $existing_rating ? 'Frissítés' : 'Értékelés mentése'; ?></button>
        </form>
    </div>
    <?php
// Vissza a pályázatokhoz gomb
?>
<div style="margin-top: 20px;">
    <button class="button" onclick="window.location.href='https://hrbest.hu/zsurioldal';">
    Vissza a pályázatokhoz
</button>
</div>
<?php

    return $content . ob_get_clean();
}




// Értékelés mentése
add_action('init', 'crp_save_rating');
function crp_save_rating() {
    if (!isset($_POST['crp_rating_nonce']) || !wp_verify_nonce($_POST['crp_rating_nonce'], 'crp_rating')) {
        return;
    }

    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    $allowed_roles = ['zsuri', 'administrator'];
    $has_role = false;

    foreach ($allowed_roles as $role) {
        if (in_array($role, $user->roles)) {
            $has_role = true;
            break;
        }
    }

    if (!$has_role) return;

    global $wpdb;
    $table = $wpdb->prefix . 'crp_ratings';
    $history_table = $wpdb->prefix . 'crp_ratings_history';
    $post_id = intval($_POST['crp_post_id']);

    $criteria = isset($_POST['crp_criteria']) ? array_map('intval', $_POST['crp_criteria']) : [];
    $comment = isset($_POST['crp_comment']) ? sanitize_text_field($_POST['crp_comment']) : '';

    // Backend validation: all criteria must be > 0
    $invalid = false;
    foreach ($criteria as $val) {
        if ($val <= 0) {
            $invalid = true;
            break;
        }
    }
    if ($invalid) {
        set_transient('crp_rating_error_' . get_current_user_id(), 'Minden szempontot kötelező értékelni!', 30);
        wp_redirect(get_permalink($post_id) . '#rating-form');
        exit;
    }

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE post_id = %d AND user_id = %d",
        $post_id,
        $user->ID
    ));

    if ($exists) {
        $old_rating = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $exists));
        $wpdb->insert($history_table, [
            'rating_id' => $exists,
            'criteria' => $old_rating->criteria,
            'comment' => $old_rating->comment,
            'edited_at' => current_time('mysql')
        ]);

        $wpdb->update($table, [
            'criteria' => json_encode($criteria),
            'comment' => $comment,
            'created_at' => current_time('mysql')
        ], ['id' => $exists]);
    } else {
        $wpdb->insert($table, [
            'post_id' => $post_id,
            'user_id' => $user->ID,
            'criteria' => json_encode($criteria),
            'comment' => $comment,
            'created_at' => current_time('mysql')
        ]);
    }

    wp_redirect(get_permalink($post_id) . '#rating-form');
    exit;
}


// Kategóriák admin oldal
function zsuri_categories_page() {
    if (!current_user_can('manage_options')) return;

    // Mentés feldolgozása
    if (isset($_POST['submit_project_categories']) || isset($_POST['submit_people_categories'])) {
        if (isset($_POST['project_categories'])) {
            $selected_project_cats = array_map('intval', $_POST['project_categories']);
            update_option('zsuri_project_categories', $selected_project_cats);
        }
        if (isset($_POST['people_categories'])) {
            $selected_people_cats = array_map('intval', $_POST['people_categories']);
            update_option('zsuri_people_categories', $selected_people_cats);
        }
        echo '<div class="notice notice-success"><p>Kategóriák frissítve!</p></div>';
    }

    $project_cats = get_option('zsuri_project_categories', []);
    $people_cats  = get_option('zsuri_people_categories', []);

    // Összes kategória (nem csak nem üresek!)
    $all_cats = get_categories([
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'orderby'    => 'name'
    ]);
    ?>
    <div class="wrap">
        <h1>Kategóriák beállítása</h1>
        <form method="post" style="margin-bottom: 32px;">
            <h2 style="margin-bottom: 8px;">Projekt kategóriák</h2>
            <div style="max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px;">
                <?php foreach ($all_cats as $cat): ?>
                    <label style="display:block; padding:5px;">
                        <input type="checkbox" name="project_categories[]" value="<?php echo esc_attr($cat->term_id); ?>"
                            <?php checked(in_array($cat->term_id, $project_cats)); ?>>
                        <?php echo esc_html($cat->name); ?> (ID: <?php echo $cat->term_id; ?>)
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="submit">
                <input type="submit" name="submit_project_categories" class="button button-primary" value="Projekt kategóriák mentése">
            </p>
        </form>

        <form method="post">
            <h2 style="margin-bottom: 8px;">People kategóriák</h2>
            <div style="max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px;">
                <?php foreach ($all_cats as $cat): ?>
                    <label style="display:block; padding:5px;">
                        <input type="checkbox" name="people_categories[]" value="<?php echo esc_attr($cat->term_id); ?>"
                            <?php checked(in_array($cat->term_id, $people_cats)); ?>>
                        <?php echo esc_html($cat->name); ?> (ID: <?php echo $cat->term_id; ?>)
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="submit">
                <input type="submit" name="submit_people_categories" class="button button-primary" value="People kategóriák mentése">
            </p>
        </form>
    </div>
    <?php
}


// Zsűri tagok admin oldal
function zsuri_users_page() {
    if (!current_user_can('manage_options')) return;

    // Mentés
    if (isset($_POST['assign_categories'])) {
        $user_ids = array_map('intval', $_POST['users'] ?? []);
        $categories = array_map('intval', $_POST['user_categories'] ?? []);
        $zsuri_types = isset($_POST['zsuri_types']) ? array_map('sanitize_text_field', (array)$_POST['zsuri_types']) : [];
        foreach ($user_ids as $user_id) {
            update_user_meta($user_id, 'zsuri_user_categories', $categories);
            update_user_meta($user_id, 'zsuri_type', $zsuri_types);
        }
        echo '<div class="notice notice-success"><p>Kategóriák és zsűri típus(ok) hozzárendelve!</p></div>';
    }

    $project_cats = get_option('zsuri_project_categories', []);
    $people_cats  = get_option('zsuri_people_categories', []);

    $all_project_cats = get_categories([
        'taxonomy'   => 'category',
        'include'    => $project_cats,
        'hide_empty' => false,
        'orderby'    => 'name'
    ]);
    $all_people_cats = get_categories([
        'taxonomy'   => 'category',
        'include'    => $people_cats,
        'hide_empty' => false,
        'orderby'    => 'name'
    ]);
    
    // ZSURI szerepkörű felhasználók lekérdezése
    $zsuri_users = get_users(['role' => 'zsuri']);
    ?>
    <div class="wrap">
        <h1>Zsűri Tagok Kezelése</h1>
        <form method="post">
            <h2>Felhasználók kiválasztása</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:20px;"><input type="checkbox" id="select-all-users"></th>
                        <th>Név</th>
                        <th>Email</th>
                        <th>Jelenlegi kategóriák</th>
                        <th>Zsűri típus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zsuri_users as $user): 
                        $user_cats = get_user_meta($user->ID, 'zsuri_user_categories', true);
                        if (!is_array($user_cats)) $user_cats = [];
                        $cat_names = [];
                        foreach ($user_cats as $cat_id) {
                            $cat = get_category($cat_id);
                            if ($cat) $cat_names[] = $cat->name;
                        }
                        $zsuri_types = get_user_meta($user->ID, 'zsuri_type', true);
                        if (!is_array($zsuri_types)) $zsuri_types = [];
                        $type_labels = [];
                        if (in_array('projekt', $zsuri_types)) $type_labels[] = 'Projekt zsűri';
                        if (in_array('people', $zsuri_types)) $type_labels[] = 'People zsűri';
                        if (empty($type_labels)) $type_labels[] = 'Nincs';
                    ?>
                        <tr>
                            <td><input type="checkbox" name="users[]" value="<?php echo $user->ID; ?>"></td>
                            <td><?php echo esc_html($user->display_name); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><?php echo $cat_names ? esc_html(implode(', ', $cat_names)) : 'Nincs'; ?></td>
                            <td><?php echo esc_html(implode(', ', $type_labels)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <h2>Zsűri típus hozzárendelése</h2>
            <div style="margin-bottom: 20px;">
                <label style="margin-right: 20px;">
                    <input type="checkbox" name="zsuri_types[]" value="projekt"> Projekt zsűri
                </label>
                <label>
                    <input type="checkbox" name="zsuri_types[]" value="people"> People zsűri
                </label>
                <div style="font-size:12px; color:#777; margin-top:5px;">
                    (Amit bejelölsz, az összes kijelölt zsűri taghoz hozzáadódik! <b>Nem kötelező mindkettőt pipálni.</b>)
                </div>
            </div>
            <h2>Projekt kategóriák hozzárendelése</h2>
            <div style="max-height:160px; overflow-y:auto; border:1px solid #ddd; padding:10px; margin-bottom:16px;">
                <?php foreach ($all_project_cats as $cat): ?>
                    <label style="display:block; padding:5px;">
                        <input type="checkbox" name="user_categories[]" value="<?php echo esc_attr($cat->term_id); ?>">
                        <?php echo esc_html($cat->name); ?> (Projekt)
                    </label>
                <?php endforeach; ?>
            </div>
            <h2>People kategóriák hozzárendelése</h2>
            <div style="max-height:160px; overflow-y:auto; border:1px solid #ddd; padding:10px; margin-bottom:20px;">
                <?php foreach ($all_people_cats as $cat): ?>
                    <label style="display:block; padding:5px;">
                        <input type="checkbox" name="user_categories[]" value="<?php echo esc_attr($cat->term_id); ?>">
                        <?php echo esc_html($cat->name); ?> (People)
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="submit">
                <input type="submit" name="assign_categories" class="button button-primary" value="Kategóriák hozzárendelése">
            </p>
        </form>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#select-all-users').click(function() {
            $('input[name="users[]"]').prop('checked', this.checked);
        });
    });
    </script>
    <?php
}



// Shortcode-ok regisztrálása
add_shortcode('jury_to_rate', 'display_jury_to_rate');
add_shortcode('jury_rated', 'display_jury_rated');
add_shortcode('people_jury_sort', 'display_people_jury_sort');

// Értékelés állapotának ellenőrzése (külső plugin integráció)
function has_user_rated_post($user_id, $post_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'crp_ratings';
    
    $rating = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) 
         FROM $table_name 
         WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    
    return ($rating > 0);
}

// People értékelés shortcode
function display_people_jury_sort() {
    $user = wp_get_current_user();

    // Jogosultság ellenőrzés
    if (!is_user_logged_in()) {
        return '';
    }

    $is_admin = in_array('administrator', (array)$user->roles);
    $user_id = get_current_user_id();

    // Csak people zsűri típus
    if (!$is_admin) {
        if (!in_array('zsuri', (array)$user->roles)) {
            return '';
        }
        $types = get_user_meta($user_id, 'zsuri_type', true);
        if (empty($types) || !is_array($types) || !in_array('people', $types)) {
            return '';
        }
    }

    // Csak a people kategóriákban
    $all_people_cats = get_option('zsuri_people_categories', []);
    if (empty($all_people_cats)) {
        return '<div class="jury-notice">Nincs elérhető people kategória a rendszerben.</div>';
    }

    if ($is_admin) {
        $user_categories = $all_people_cats;
    } else {
        $user_categories = get_user_meta($user_id, 'zsuri_user_categories', true);
        if (!is_array($user_categories)) $user_categories = [];
        $user_categories = array_values(array_intersect($user_categories, $all_people_cats));
    }
    if (empty($user_categories)) {
        return '<div class="jury-notice">Nincs hozzárendelt people kategória</div>';
    }

    $current_category = isset($_GET['jury_people_category']) ? (int)$_GET['jury_people_category'] : $user_categories[0];
    if (!in_array($current_category, $user_categories)) {
        $current_category = $user_categories[0];
    }
    $category = get_category($current_category);

    ob_start();

    // Kategóriaválasztó
    if (count($user_categories) > 1) {
        echo '<div class="category-selector">';
        echo '<form method="GET">';
        echo '<label for="jury_people_category">Kategória:</label>';
        echo '<select name="jury_people_category" onchange="this.form.submit()">';
        foreach ($user_categories as $cat_id) {
            $category_obj = get_category($cat_id);
            if (!$category_obj) continue;
            $selected = ($current_category == $cat_id) ? 'selected' : '';
            echo '<option value="' . esc_attr($cat_id) . '" ' . $selected . '>' . esc_html($category_obj->name) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        echo '</div>';
    }
    // Ha csak egy kategória van, jelenítsük meg a nevét
if (count($user_categories) === 1 && $category) {
    echo '<div class="jury-current-category" style="margin-bottom: 15px;"><strong>Kategória:</strong> ' . esc_html($category->name) . '</div>';
}

    if (!$category) {
        echo '<div class="jury-notice">Nincs ilyen kategória.</div>';
        return ob_get_clean();
    }

    // Bejegyzések lekérdezése a kategóriában
    $args = [
        'cat'            => $current_category,
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ];
    $query = new WP_Query($args);
    $posts = [];
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Kizárt zsűri tagok
            $excluded_users = get_post_meta($post_id, '_excluded_zsuri_users', true);
            if (is_array($excluded_users) && in_array($user_id, $excluded_users)) {
                continue;
            }
            $posts[] = [
                'id' => $post_id,
                'title' => get_the_title(),
                'link' => get_permalink(),
                'thumbnail' => get_the_post_thumbnail_url($post_id, 'thumbnail')
            ];
        }
        wp_reset_postdata();
    }

    // Mentett sorrend betöltése user meta-ból
    $meta_key = 'people_jury_order_' . $current_category;
    $saved_order = get_user_meta($user_id, $meta_key, true);
    if (!is_array($saved_order)) $saved_order = [];

    // Rendezett lista (ha van mentett sorrend)
    $ordered_posts = [];
    $unordered_posts = $posts;
    if (!empty($saved_order)) {
        foreach ($saved_order as $pid) {
            foreach ($posts as $idx => $post) {
                if ($post['id'] == $pid) {
                    $ordered_posts[] = $post;
                    unset($unordered_posts[$idx]);
                    break;
                }
            }
        }
        foreach ($unordered_posts as $post) {
            $ordered_posts[] = $post;
        }
    } else {
        $ordered_posts = $posts;
    }

    if (empty($ordered_posts)) {
        echo '<p>Nincs pályázat ebben a kategóriában.</p>';
        return ob_get_clean();
    }

    echo '<form id="people-jury-sort-form" method="post">';
    echo '<input type="hidden" name="jury_people_category" value="' . esc_attr($current_category) . '">';
    echo '<h3>Pályázók sorrendje (rendezd a pályázókat prioritási sorrendbe, legfelülre a legjobbat):</h3>';
    echo '<ul id="people-jury-sortable" style="list-style:none; padding:0; margin:0 0 20px 0; max-width:500px;">';
    foreach ($ordered_posts as $post) {
        echo '<li class="people-jury-sort-item" data-post-id="' . esc_attr($post['id']) . '" style="padding:10px 16px; margin-bottom:6px; border:1px solid #ccc; background:#fafaff; cursor:move;">';
        if ($post['thumbnail']) {
            echo '<img src="' . esc_url($post['thumbnail']) . '" alt="" style="width:40px;height:40px;object-fit:cover;vertical-align:middle;margin-right:10px;border-radius:3px;">';
        }
        echo '<span style="vertical-align:middle;"><a href="' . esc_url($post['link']) . '" target="_blank" style="font-weight:bold;">' . esc_html($post['title']) . '</a></span>';
        echo '</li>';
    }
    echo '</ul>';
    echo '<input type="hidden" name="jury_sort_order" id="jury_sort_order" value="">';
    echo '<button type="submit" class="button button-primary">Sorrend mentése</button>';
    echo '</form>';

    if (isset($_GET['jury_sort_saved']) && $_GET['jury_sort_saved'] == '1') {
        echo '<div class="notice notice-success" style="margin-top:15px;padding:10px;">Sorrend sikeresen mentve!</div>';
    }
    ?>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('people-jury-sortable');
        var sortable = Sortable.create(el, {
            animation: 150
        });
        document.getElementById('people-jury-sort-form').addEventListener('submit', function(e) {
            var items = document.querySelectorAll('.people-jury-sort-item');
            var order = [];
            items.forEach(function(item) {
                order.push(item.getAttribute('data-post-id'));
            });
            document.getElementById('jury_sort_order').value = JSON.stringify(order);
        });
    });
    </script>
    <style>
        #people-jury-sortable li.ghost { opacity: 0.4; }
    </style>
    <?php

    return ob_get_clean();
}



// Értékelendő tételek shortcode
function display_jury_to_rate() {
    $user = wp_get_current_user();

    if (!is_user_logged_in()) {
        return '';
    }

    // Admin mindent lát
    $is_admin = in_array('administrator', (array)$user->roles);
    $user_id = get_current_user_id();

    // Csak projekt zsűri típus
    if (!$is_admin) {
        if (!in_array('zsuri', (array)$user->roles)) {
            return '';
        }
        $types = get_user_meta($user_id, 'zsuri_type', true);
        if (empty($types) || !is_array($types) || !in_array('projekt', $types)) {
            return '';
        }
    }

    // Csak a projekt kategóriákban
    $all_project_cats = get_option('zsuri_project_categories', []);
    if (empty($all_project_cats)) {
        return '<div class="jury-notice">Nincs elérhető projekt kategória a rendszerben.</div>';
    }

    if ($is_admin) {
        $user_categories = $all_project_cats;
    } else {
        $user_categories = get_user_meta($user_id, 'zsuri_user_categories', true);
        if (!is_array($user_categories)) $user_categories = [];
        $user_categories = array_values(array_intersect($user_categories, $all_project_cats));
    }
    if (empty($user_categories)) {
        return '<div class="jury-notice">Nincs hozzárendelt projekt kategória</div>';
    }

    $current_category = isset($_GET['jury_project_category']) ? (int)$_GET['jury_project_category'] : $user_categories[0];
    if (!in_array($current_category, $user_categories)) {
        $current_category = $user_categories[0];
    }

    ob_start();

    // Kategóriaválasztó
    if (count($user_categories) > 1) {
        echo '<div class="category-selector">';
        echo '<form method="GET">';
        echo '<label for="jury_project_category">Kategória:</label>';
        echo '<select name="jury_project_category" onchange="this.form.submit()">';
        foreach ($user_categories as $cat_id) {
            $category = get_category($cat_id);
            if (!$category) continue;
            $selected = ($current_category == $cat_id) ? 'selected' : '';
            echo '<option value="' . esc_attr($cat_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        echo '</div>';
    }

    // Lekérdezzük az adott kategóriába tartozó összes bejegyzést
    $args = [
        'cat'            => $current_category,
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ];

    $query = new WP_Query($args);
    $unrated_posts = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Kizárt zsűri tagok szűrése
            $excluded_users = get_post_meta($post_id, '_excluded_zsuri_users', true);
            if (is_array($excluded_users) && in_array($user_id, $excluded_users)) continue;

            // Már értékelte?
            if (!has_user_rated_post($user_id, $post_id)) {
                $unrated_posts[] = [
                    'id'    => $post_id,
                    'title' => get_the_title(),
                    'link'  => get_permalink()
                ];
            }
        }
        wp_reset_postdata();
    }

    echo '<section class="jury-section to-rate">';
    echo '<h2>Pályázatok</h2>';

    if (!empty($unrated_posts)) {
        echo '<div class="jury-grid">';
        foreach ($unrated_posts as $post) {
            echo '<div class="jury-item">';
            echo '<a href="' . esc_url($post['link']) . '" class="jury-title">' . esc_html($post['title']) . '</a>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<p>Nincs pályázat ebben a kategóriában.</p>';
    }

    echo '</section>';

    return ob_get_clean();
}



// Már értékelt tételek shortcode
function display_jury_rated() {
    $user = wp_get_current_user();

    if (!is_user_logged_in()) {
        return '';
    }

    // Admin mindent lát
    $is_admin = in_array('administrator', (array)$user->roles);
    $user_id = get_current_user_id();

    // Csak projekt zsűri típus
    if (!$is_admin) {
        if (!in_array('zsuri', (array)$user->roles)) {
            return '';
        }
        $types = get_user_meta($user_id, 'zsuri_type', true);
        if (empty($types) || !is_array($types) || !in_array('projekt', $types)) {
            return '';
        }
    }

    // Csak a projekt kategóriákban
    $all_project_cats = get_option('zsuri_project_categories', []);
    if (empty($all_project_cats)) {
        return '<div class="jury-notice">Nincs elérhető projekt kategória a rendszerben.</div>';
    }

    if ($is_admin) {
        $user_categories = $all_project_cats;
    } else {
        $user_categories = get_user_meta($user_id, 'zsuri_user_categories', true);
        if (!is_array($user_categories)) $user_categories = [];
        $user_categories = array_values(array_intersect($user_categories, $all_project_cats));
    }
    if (empty($user_categories)) {
        return '<div class="jury-notice">Nincs hozzárendelt projekt kategória</div>';
    }

    $current_category = isset($_GET['jury_project_category']) ? (int)$_GET['jury_project_category'] : $user_categories[0];
    if (!in_array($current_category, $user_categories)) {
        $current_category = $user_categories[0];
    }

    ob_start();

    $category = get_category($current_category);
    if ($category) {
        echo '<div class="jury-current-category">';
        echo '<strong>Kategória:</strong> ' . esc_html($category->name);
        echo '</div>';
    }

    $args = [
        'cat'             => $current_category,
        'posts_per_page'  => -1,
        'post_status'     => 'publish'
    ];

    $query = new WP_Query($args);
    $rated_posts = [];

    global $wpdb;
    $table = $wpdb->prefix . 'crp_ratings';
    $criteria = get_option('crp_criteria', []);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Kizárt zsűri tagok
            $excluded_users = get_post_meta($post_id, '_excluded_zsuri_users', true);
            if (is_array($excluded_users) && in_array($user_id, $excluded_users)) continue;

            $rating = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE post_id = %d AND user_id = %d",
                $post_id, $user_id
            ));

            if ($rating) {
                $c = json_decode($rating->criteria, true);
                $average = ($c && count($c) > 0) ? round(array_sum($c) / count($c), 2) : 0;

                $rated_posts[] = [
                    'id'       => $post_id,
                    'title'    => get_the_title(),
                    'link'     => get_permalink(),
                    'criteria' => $c,
                    'average'  => $average,
                    'comment'  => $rating->comment
                ];
            }
        }
        wp_reset_postdata();
    }

    echo '<section class="jury-section rated">';
    echo '<h2>Már értékelt pályázatok</h2>';

    if (!empty($rated_posts)) {
        echo '<div class="jury-grid">';
        foreach ($rated_posts as $idx => $post) {
            $comment_id = 'jury-rating-comment-' . $idx;
            echo '<div class="jury-item" style="margin-bottom:20px; border:1px solid #eee; padding:10px;">';
            echo '<a href="' . esc_url($post['link']) . '" class="jury-title" style="font-weight:bold;">' . esc_html($post['title']) . '</a>';

            // Szempontok és átlag
            echo '<div class="jury-rating-details" style="margin-top:8px; font-size:13px; line-height:1.5;">';
            foreach ($criteria as $i => $crit) {
                echo '<div><strong>' . esc_html($crit) . ':</strong> ' . intval($post['criteria'][$i] ?? 0) . '</div>';
            }
            echo '<div style="margin-top:3px;"><strong>Átlag:</strong> ' . esc_html($post['average']) . '</div>';
            echo '</div>';

            if (!empty($post['comment'])) {
                echo '<button type="button" class="show-jury-comment-btn" data-target="#' . esc_attr($comment_id) . '" 
                    style="margin-top:10px; font-size:13px; line-height:1.5; padding:7px 16px; min-width:120px; white-space:normal;">Jegyzet mutatása</button>';
                echo '<div id="' . esc_attr($comment_id) . '" class="jury-rating-comment" style="display:none; margin-top:6px; font-style:italic; font-size:11px; color:#666;">';
                echo 'Jegyzet: ' . esc_html($post['comment']);
                echo '</div>';
            }

            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<p>Még nem értékeltél tételeket ebben a kategóriában.</p>';
    }

    echo '</section>';

    // JS csak egyszer szúródjon be
    static $jury_comment_js_loaded = false;
    if (!$jury_comment_js_loaded) {
        $jury_comment_js_loaded = true;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.show-jury-comment-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = document.querySelector(this.getAttribute('data-target'));
                    if (target) {
                        if (target.style.display === 'none' || target.style.display === '') {
                            target.style.display = 'block';
                            this.textContent = 'Jegyzet elrejtése';
                        } else {
                            target.style.display = 'none';
                            this.textContent = 'Jegyzet mutatása';
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    return ob_get_clean();
}




// CSS betöltése
add_action('wp_enqueue_scripts', 'zsuri_enqueue_scripts');
function zsuri_enqueue_scripts() {
    wp_enqueue_style('jury-style', plugin_dir_url(__FILE__) . 'jury-style.css');
    wp_enqueue_script('jury-ajax', plugin_dir_url(__FILE__) . 'jury-ajax.js', ['jquery'], false, true);
}

// Kategória hozzáférés korlátozása zsűri felhasználóknak
add_action('template_redirect', 'zsuri_restrict_category_access');
function zsuri_restrict_category_access() {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('zsuri', (array) $user->roles)) return;

    $user_categories = get_user_meta($user->ID, 'zsuri_user_categories', true);
    if (empty($user_categories) || !is_array($user_categories)) return;

    if (is_single()) {
        $post_cats = wp_get_post_categories(get_the_ID());
        if (!array_intersect($user_categories, $post_cats)) {
            wp_redirect('https://hrbest.hu/zsurioldal');
            exit;
        }
    }

    if (is_category()) {
        $cat_id = get_queried_object_id();
        if (!in_array($cat_id, $user_categories)) {
            wp_redirect('https://hrbest.hu/zsurioldal');
            exit;
        }
    }
}

// People értékelés shortcode mentés
add_action('init', 'save_people_jury_sort_order');
function save_people_jury_sort_order() {
    if (
        isset($_POST['jury_sort_order']) &&
        is_user_logged_in() &&
        isset($_POST['jury_people_category'])
    ) {
        $user = wp_get_current_user();

        $ok = false;
        if (in_array('administrator', (array)$user->roles)) $ok = true;
        else {
            if (in_array('zsuri', (array)$user->roles)) {
                $types = get_user_meta($user->ID, 'zsuri_type', true);
                if (is_array($types) && in_array('people', $types)) $ok = true;
            }
        }
        if (!$ok) return;

        // Kategória azonosító közvetlenül POST-ból
        $cat_id = isset($_POST['jury_people_category']) ? intval($_POST['jury_people_category']) : 0;
        if (!$cat_id) return;

        $order = json_decode(stripslashes($_POST['jury_sort_order']), true);
        if (!is_array($order)) return;
        update_user_meta($user->ID, 'people_jury_order_' . $cat_id, $order);

        // Sikeres mentés után visszairányítás
        $referer = $_POST['_wp_http_referer'] ?? $_SERVER['HTTP_REFERER'];
        $redirect = remove_query_arg('jury_sort_saved', $referer);
        $redirect = add_query_arg('jury_sort_saved', '1', $redirect);
        wp_redirect($redirect);
        exit;
    }
}



function zsuri_people_jury_results_page() {
    if (isset($_GET['export_people_jury_csv']) && isset($_GET['jury_category'])) {
    zsuri_export_people_jury_results_csv(intval($_GET['jury_category']));
    exit;
}
    if (!current_user_can('manage_options')) return;

    $all_people_cats = get_option('zsuri_people_categories', []);
    if (empty($all_people_cats)) {
        echo '<div class="notice notice-info"><p>Nincs people kategória beállítva.</p></div>';
        return;
    }

    $current_category = isset($_GET['jury_category']) ? (int)$_GET['jury_category'] : $all_people_cats[0];
    echo '<div class="wrap"><h1>People zsűri értékelések</h1>';
    echo '<form method="get" style="margin-bottom:20px;">';
    echo '<input type="hidden" name="page" value="people-jury-results">';
    echo '<label>Kategória: </label>';
    echo '<select name="jury_category" onchange="this.form.submit()">';
    foreach ($all_people_cats as $cat_id) {
        $cat = get_category($cat_id);
        if (!$cat) continue;
        $selected = ($current_category == $cat_id) ? 'selected' : '';
        echo '<option value="' . esc_attr($cat_id) . '" ' . $selected . '>' . esc_html($cat->name) . '</option>';
    }
    echo '</select>';
    echo '</form>';
?>
<form method="get" style="display:inline;">
    <input type="hidden" name="page" value="people-jury-results">
    <input type="hidden" name="jury_category" value="<?php echo esc_attr($current_category); ?>">
    <input type="hidden" name="export_people_jury_csv" value="1">
    <button type="submit" class="button button-secondary">Exportálás CSV-be</button>
</form>
<?php
    $people_jury_users = get_users([
        'role' => 'zsuri',
        'meta_query' => [[
            'key' => 'zsuri_type',
            'value' => 'people',
            'compare' => 'LIKE'
        ]]
    ]);

    // SZŰRÉS: csak azok a zsűri tagok jelenjenek meg, akikhez hozzá van rendelve az aktuális kategória
    $filtered_people_jury_users = [];
    foreach ($people_jury_users as $user) {
        $user_cats = get_user_meta($user->ID, 'zsuri_user_categories', true);
        if (!is_array($user_cats)) $user_cats = [];
        if (in_array($current_category, $user_cats)) {
            $filtered_people_jury_users[] = $user;
        }
    }
    if (empty($filtered_people_jury_users)) {
        echo '<div class="notice notice-info"><p>Nincs people zsűri tag ebben a kategóriában.</p></div>';
        echo '</div>';
        return;
    }

    foreach ($filtered_people_jury_users as $user) {
        $order = get_user_meta($user->ID, 'people_jury_order_' . $current_category, true);
        if (!is_array($order)) $order = [];
        echo '<div style="margin-bottom:22px; border-bottom:1px solid #e5e5e5; padding-bottom:10px;">';
        echo '<h2 style="margin-bottom:7px;">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</h2>';
        if (current_user_can('manage_options')) {
            echo '<form method="post" style="display:inline; margin-bottom:0;">';
            echo '<input type="hidden" name="delete_people_jury_order" value="1">';
            echo '<input type="hidden" name="jury_user_id" value="' . esc_attr($user->ID) . '">';
            echo '<input type="hidden" name="jury_category_id" value="' . esc_attr($current_category) . '">';
            echo '<button type="submit" class="button button-danger" onclick="return confirm(\'Biztosan törlöd a teljes sorrendet ennél a zsűritagnál?\');">Sorrend törlése</button>';
            echo '</form>';
        }
        if (empty($order)) {
            echo '<p><i>Nincs még értékelés ebben a kategóriában.</i></p>';
        } else {
            echo '<ol style="margin-left:20px;">';
            foreach ($order as $pos => $post_id) {
                $post = get_post($post_id);
                if (!$post) continue;
                echo '<li><a href="' . esc_url(get_permalink($post_id)) . '" target="_blank">' . esc_html($post->post_title) . '</a></li>';
            }
            echo '</ol>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function zsuri_export_people_jury_results_csv($cat_id) {
    if (!current_user_can('manage_options')) exit;

    // Kategória neve
    $category = get_category($cat_id);
    $cat_name = $category ? $category->name : 'Ismeretlen';

    // Összes people zsűri tag
    $people_jury_users = get_users([
        'role' => 'zsuri',
        'meta_query' => [[
            'key' => 'zsuri_type',
            'value' => 'people',
            'compare' => 'LIKE'
        ]]
    ]);

    // Összes post ebben a kategóriában
    $args = [
        'cat' => $cat_id,
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ];
    $query = new WP_Query($args);
    $posts = [];
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $posts[get_the_ID()] = get_the_title();
        }
        wp_reset_postdata();
    }

    // CSV fejlécek
    $header = ['Felhasználó', 'Email'];
    $max_rank = count($posts);
    for ($i = 1; $i <= $max_rank; $i++) {
        $header[] = $i.'. hely';
    }

    // HEADER az első output!
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="people_jury_'.$cat_id.'_'.date('Ymd').'.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, $header);

    foreach ($people_jury_users as $user) {
        $order = get_user_meta($user->ID, 'people_jury_order_' . $cat_id, true);
        if (!is_array($order)) $order = [];
        $row = [$user->display_name, $user->user_email];
        foreach ($order as $post_id) {
            $row[] = isset($posts[$post_id]) ? $posts[$post_id] : '';
        }
        // Ha nem volt minden hely kitöltve, töltsük ki üresen
        for ($i = count($order); $i < $max_rank; $i++) {
            $row[] = '';
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

add_filter('the_content', 'people_jury_note_field', 30);
function people_jury_note_field($content) {
    if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;
    if (!is_user_logged_in()) return $content;

    $user = wp_get_current_user();
    $user_id = $user->ID;
    $allowed = in_array('zsuri', (array)$user->roles) || in_array('administrator', (array)$user->roles);
    if (!$allowed) return $content;

    // Csak People kategóriás bejegyzésnél jelenjen meg
    $people_cats = get_option('zsuri_people_categories', []);
    if (empty($people_cats) || !is_array($people_cats)) return $content;
    $post_id = get_the_ID();
    $post_cats = wp_get_post_categories($post_id);
    if (!array_intersect($people_cats, $post_cats)) return $content;

    // Mentés feldolgozása
    if (isset($_POST['people_jury_note_nonce']) && wp_verify_nonce($_POST['people_jury_note_nonce'], 'people_jury_note_save')) {
        $note = sanitize_textarea_field($_POST['people_jury_note'] ?? '');
        update_user_meta($user_id, 'people_jury_note_' . $post_id, $note);
        // Frissítés után redirect, hogy ne legyen újrapostolás
        wp_redirect(get_permalink($post_id) . '#people-jury-note');
        exit;
    }

    // Aktuális megjegyzés betöltése
    $note = get_user_meta($user_id, 'people_jury_note_' . $post_id, true);

    ob_start();
    ?>
    <div id="people-jury-note" style="margin-top:32px; padding:18px; background:#f8f8f8; border:1px solid #e0e0e0;">
        <form method="post">
            <?php wp_nonce_field('people_jury_note_save', 'people_jury_note_nonce'); ?>
            <label for="people-jury-note-field" style="font-weight:bold;">Saját megjegyzésem ehhez a pályázathoz:</label><br>
            <textarea id="people-jury-note-field" name="people_jury_note" rows="4" style="width:100%; max-width:500px;"><?php echo esc_textarea($note); ?></textarea>
            <br>
            <button type="submit" class="button" style="margin-top:8px;">Mentés</button>
        </form>
        <div style="font-size:12px; color:#888; margin-top:7px;">
            Ez a megjegyzés csak számodra látható, más zsűri tag és az admin sem látja.
        </div>
    </div>
    <?php
    return $content . ob_get_clean();
}


// 1. Jury Project Info shortcode
function jury_info_project_shortcode() {
    if (!is_user_logged_in()) return '';

    $user = wp_get_current_user();
    $user_id = $user->ID;

    // Admin kérésére mindkettőt megjelenítjük
    if (!in_array('administrator', (array)$user->roles)) {
        // Nem admin, ellenőrizzük, hogy projekt zsűri-e
        if (!in_array('zsuri', (array)$user->roles)) {
            return '';
        }
        $types = get_user_meta($user_id, 'zsuri_type', true);
        if (empty($types) || !is_array($types) || !in_array('projekt', $types)) {
            return '';
        }
    }

    // A tartalom eredeti visszaadása az 5 színnel
    return do_shortcode('[vc_row header_feature="yes" margin_bottom="50" el_class="jury-info-project"][vc_column][grve_divider][grve_slogan title="Kedves Zsűritag!" heading="h5" line_type="line"]Köszöntünk a HRBEST értékelő felületén!<br />
Az alábbiakban találod azokat a pályázatokat, amelyek pontozásában számítunk a segítségedre.[/grve_slogan][vc_column_text]
<p style="text-align: left;"><span style="color: #ff0066;"><strong>Kérjük, hogy minden pályázatot értékelj a kategóriában.</strong></span></p>
<p style="text-align: left;"><span style="color: #000000;">Amennyiben másik kategóriát is szívesen értékelnél, jelezd nekünk a hrbest@hrfest.com-on és megjelenítjük számodra😊</span></p>
[/vc_column_text][/vc_column][/vc_row][vc_row flex_height="yes" margin_bottom="50"][vc_column][vc_column_text el_class="jury-info-project"]
<h4 style="text-align: left;"><strong>Az értékelés menete</strong></h4>
[/vc_column_text][vc_row_inner el_class="jury-info-project"]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1601307822486{background-color: #ff0066 !important;}"][vc_column_text css=".vc_custom_1674747548741{margin-top: 0px !important;margin-bottom: 60px !important;border-top-width: 0px !important;border-bottom-width: 40px !important;padding-top: 10px !important;padding-bottom: 31px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>1.</strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;"><b>Válassz </b><b style="text-align: center;">egy pályázatot, és kattints a címére a tartalom megjelenítéséhez.</b></span></p>
[/vc_column_text][/vc_column_inner]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1601307955584{background-color: #3399cc !important;}"][vc_column_text css=".vc_custom_1752652751127{padding-top: 10px !important;padding-bottom: 5px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>2. </strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;"><b>A pályázati oldal alján található értékelési szempontok szerint értékeld a pályázatot 1-5-ös skálán (1: leggyengébb, 5: legerősebb pontszám) és kattints az &quot;Értékelés mentése&quot; gombra!</b></span></p>
[/vc_column_text][/vc_column_inner]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1601307997974{background-color: #662c92 !important;}"][vc_column_text css=".vc_custom_1752652788148{padding-top: 10px !important;padding-bottom: 34px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>3. </strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;">Az oldal alján található "Saját jegyzeteim" funkciót használd nyugodtan saját feljegyzések készítésére. Ezt nem kötelező használnod, kitöltened.</span></p>
[/vc_column_text][/vc_column_inner]' .
    '[/vc_row_inner][vc_row_inner el_class="jury-info-project"]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1674747821783{background-color: #000000 !important;}"][vc_column_text css=".vc_custom_1752653741740{padding-top: 10px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>4. </strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;">Ha végeztél egy adott pályázat átnézésével, pontozd az összes szempont mentén. A rendszer nem enged menteni, amíg nem értékelték minden szempontot. Ha kész vagy, kattints az &quot;Értékelés mentése&quot; gombra!</span></p>
[/vc_column_text][/vc_column_inner]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1674748896249{background-color: #ffd800 !important;}"][vc_column_text css=".vc_custom_1752653765120{padding-top: 10px !important;padding-bottom: 29px !important;}"]
<p style="text-align: center;"><span style="color: #000000;"><strong>5.</strong></span></p>
<p style="text-align: center;"><span style="color: #000000;">Az általad sikeresen pontozott pályázatok átkerülnek a &quot;Már értékelt pályázatok&quot;-hoz. Ha egy pályázat pontszámait módosítani szeretnéd, nyisd meg újra, pontozz és kattints a &quot;Frissítés&quot; gombra!</span></p>
[/vc_column_text][/vc_column_inner]' .
    '[/vc_row_inner][grve_divider][vc_column_text]
<p style="text-align: left;"><strong>Kérdés esetén írj nekünk a hrbest@hrfest.com e-mail címre.</strong></p>
<p style="text-align: left;"><strong>Eredményes munkát kíván a HRBEST csapata!</strong></p>
[/vc_column_text][grve_divider]');
}
add_shortcode('jury_info_project', 'jury_info_project_shortcode');


// 2. Jury People Info shortcode
function jury_info_people_shortcode() {
    if (!is_user_logged_in()) return '';

    $user = wp_get_current_user();
    $user_id = $user->ID;

    // Admin mindkettőt látja
    if (!in_array('administrator', (array)$user->roles)) {
        // Nem admin, ellenőrizzük, hogy people zsűri-e
        if (!in_array('zsuri', (array)$user->roles)) {
            return '';
        }
        $types = get_user_meta($user_id, 'zsuri_type', true);
        if (empty($types) || !is_array($types) || !in_array('people', $types)) {
            return '';
        }
    }

    return do_shortcode('[vc_row][vc_column][grve_slogan title="Kedves Zsűritag!" heading="h5" line_type="line" el_class="jury-info-people"]Köszöntünk a HRBEST értékelő felületén!<br />
Az alábbiakban találod azokat a People by euJobs HR Group pályázatokat, amelyek értékelésében számítunk a segítségedre.[/grve_slogan][vc_column_text el_class="jury-info-people"]
<p style="text-align: left;"><span style="color: #ff0066;"><strong>Kérjük, hogy minden jelölt pályázatát olvasd át a kategóriában. A jelöltekhez tudsz saját megjegyzést fűzni, ezt csak te látod. </strong></span></p>
<p style="text-align: left;"><span style="color: #000000;">Amennyiben másik kategóriát is szívesen értékelnél, jelezd nekünk a hrbest@hrfest.com-on és megjelenítjük számodra😊</span></p>
[/vc_column_text][vc_column_text el_class="jury-info-people"]
<h4 style="text-align: left;"><strong>Az értékelés menete</strong></h4>
[/vc_column_text][vc_row_inner el_class="jury-info-people"]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1601307822486{background-color: #ff0066 !important;}"][vc_column_text css=".vc_custom_1752651280354{margin-top: 0px !important;margin-bottom: 60px !important;border-top-width: 0px !important;border-bottom-width: 40px !important;padding-top: 10px !important;padding-bottom: 31px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>1.</strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;"><b>Válassz </b><b style="text-align: center;">egy pályázatot, és kattints a jelölt nevére a tartalom megjelenítéséhez.</b></span></p>
[/vc_column_text][/vc_column_inner]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1601307955584{background-color: #3399cc !important;}"][vc_column_text css=".vc_custom_1752652513987{padding-top: 10px !important;padding-bottom: 5px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>2. </strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;"><b>A jelöltek pályázati oldalának alján találsz egy &quot;Saját megjegyzésem&quot; mezőt, ide tudsz magadnak jegyzetelni. Fontos, hogy ez a megjegyzés csak számodra látható, más zsűritag és az adminok sem látják.</b></span></p>
[/vc_column_text][/vc_column_inner]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1601307997974{background-color: #662c92 !important;}"][vc_column_text css=".vc_custom_1752652165683{padding-top: 10px !important;padding-bottom: 34px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>3. </strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;">Ha átolvastad az összes pályázatot, a Zsűrioldalon tudod őket rangsorolni. Jobb egér gombbal kattints a jelölt nevére és húzd a megfelelő helyre.</span></p>
[/vc_column_text][/vc_column_inner]' .
    '[/vc_row_inner][vc_row_inner el_class="jury-info-people"]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1674747821783{background-color: #000000 !important;}"][vc_column_text css=".vc_custom_1752652264446{padding-top: 10px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>4. </strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;">Ha kész a sorrend, nincs már dolgod, mint a "Sorrend mentése" gombra kattintani! Ha sikeres volt a mentés, a rendszer kiír egy visszaigazolós üzenetet, ezt érdemes figyelni. </span></p>
[/vc_column_text][/vc_column_inner]' .
    '[vc_column_inner width="1/3" css=".vc_custom_1674748896249{background-color: #ffd800 !important;}"][vc_column_text css=".vc_custom_1752652086559{padding-top: 10px !important;padding-bottom: 29px !important;}"]
<p style="text-align: center;"><span style="color: #ffffff;"><strong>5.</strong></span></p>
<p style="text-align: center;"><span style="color: #ffffff;">Ha később módosítani szeretnéd az általad felállított sorrendet, bármikor módosíthatsz, csak ne felejts el a "Sorrend mentése" gombra kattintani!</span></p>
[/vc_column_text][/vc_column_inner]' .
    '[/vc_row_inner][/vc_column][/vc_row]');
}
add_shortcode('jury_info_people', 'jury_info_people_shortcode');

// POST feldolgozás a sorrend törléséhez
add_action('admin_init', function() {
    if (
        isset($_POST['delete_people_jury_order'], $_POST['jury_user_id'], $_POST['jury_category_id']) &&
        current_user_can('manage_options')
    ) {
        $user_id = intval($_POST['jury_user_id']);
        $cat_id = intval($_POST['jury_category_id']);
        delete_user_meta($user_id, 'people_jury_order_' . $cat_id);
        // Redirect, hogy ne legyen újrapostolás
        wp_redirect(add_query_arg('jury_order_deleted', '1'));
        exit;
    }
});
// Sikeres törlés visszajelzés
if (isset($_GET['jury_order_deleted']) && $_GET['jury_order_deleted'] == '1') {
    echo '<div class="notice notice-success"><p>Sorrend sikeresen törölve.</p></div>';
}
