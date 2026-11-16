<?php
/*
Plugin Name: Simple CSV Redirects
Description: 301 redirects imported from a CSV file (old URL, new URL) or added by hand.
Version: 1.1.0
Author: Sofiya Kazak
Author URI: https://github.com/dexuez
Text Domain: simple-csv-redirects
Domain Path: /languages
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

define('SCR_VERSION', '1.1.0');
define('SCR_DB_VERSION', '2');
define('SCR_PER_PAGE', 20);

register_activation_hook(__FILE__, 'scr_install');
add_action('plugins_loaded', 'scr_upgrade');
add_action('init', 'scr_textdomain');
add_action('template_redirect', 'scr_redirect');
add_action('admin_menu', 'scr_menu');
add_action('admin_enqueue_scripts', 'scr_assets');
add_action('admin_post_scr_import', 'scr_import');
add_action('admin_post_scr_export', 'scr_export');
add_action('admin_post_scr_add', 'scr_add');
add_action('admin_post_scr_delete', 'scr_delete');
add_action('admin_post_scr_bulk_delete', 'scr_bulk_delete');

function scr_table()
{
    global $wpdb;
    return $wpdb->prefix . 'scr_redirects';
}

function scr_install()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // dbDelta also adds new columns to a table from an older version
    $sql = "CREATE TABLE " . scr_table() . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        old_path varchar(255) NOT NULL,
        new_url text NOT NULL,
        hits int(10) unsigned NOT NULL DEFAULT 0,
        last_hit datetime DEFAULT NULL,
        created datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY old_path (old_path)
    ) " . $wpdb->get_charset_collate() . ";";

    dbDelta($sql);
    update_option('scr_db_version', SCR_DB_VERSION);
}

// activation hook doesn't run when the plugin is updated by uploading a new zip
function scr_upgrade()
{
    if (get_option('scr_db_version') !== SCR_DB_VERSION) {
        scr_install();
    }
}

function scr_textdomain()
{
    load_plugin_textdomain('simple-csv-redirects', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// we only compare the path: "https://site.pl/O-nas/?x=1" -> "/o-nas"
function scr_path($url)
{
    $path = wp_parse_url(trim($url), PHP_URL_PATH);
    if (!$path) {
        return '';
    }
    $path = '/' . trim(rawurldecode($path), '/');
    if ($path === '/') {
        return '';
    }
    return strtolower($path);
}

function scr_is_external($url)
{
    $host = wp_parse_url($url, PHP_URL_HOST);
    return $host && $host !== wp_parse_url(home_url(), PHP_URL_HOST);
}

function scr_save($old, $new)
{
    global $wpdb;

    $path = scr_path($old);
    $new = trim($new);

    if ($path === '' || !preg_match('#^(https?://|/)#', $new)) {
        return false;
    }

    // a redirect to the same page would loop forever
    if (scr_path($new) === $path && !scr_is_external($new)) {
        return false;
    }

    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . scr_table() . " WHERE old_path = %s", $path));

    if ($id) {
        return $wpdb->update(scr_table(), ['new_url' => esc_url_raw($new)], ['id' => $id]) !== false;
    }

    return $wpdb->insert(scr_table(), [
        'old_path' => $path,
        'new_url' => esc_url_raw($new),
        'created' => current_time('mysql'),
    ]) !== false;
}

function scr_redirect()
{
    global $wpdb;

    $path = scr_path($_SERVER['REQUEST_URI']);
    if ($path === '') {
        return;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, new_url FROM " . scr_table() . " WHERE old_path = %s",
        $path
    ));

    if (!$row) {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE " . scr_table() . " SET hits = hits + 1, last_hit = %s WHERE id = %d",
        current_time('mysql'),
        $row->id
    ));

    wp_redirect($row->new_url, 301, 'Simple CSV Redirects');
    exit;
}

function scr_menu()
{
    add_management_page(
        'Simple CSV Redirects',
        'CSV Redirects',
        'manage_options',
        'scr-redirects',
        'scr_page'
    );
}

function scr_assets($hook)
{
    if ($hook !== 'tools_page_scr-redirects') {
        return;
    }
    $url = plugin_dir_url(__FILE__) . 'assets/dist/';
    wp_enqueue_style('scr-admin', $url . 'admin.css', [], SCR_VERSION);
    wp_enqueue_script('scr-admin', $url . 'admin.js', [], SCR_VERSION, true);
}

function scr_check($action)
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to do this.', 'simple-csv-redirects'));
    }
    check_admin_referer($action);
}

function scr_back($args)
{
    wp_safe_redirect(add_query_arg($args, admin_url('tools.php?page=scr-redirects')));
    exit;
}

function scr_import()
{
    scr_check('scr_import');

    if (empty($_FILES['csv']['tmp_name'])) {
        scr_back(['msg' => 'nofile']);
    }

    $file = fopen($_FILES['csv']['tmp_name'], 'r');

    // Polish Excel saves CSV with ";" instead of ","
    $first = fgets($file);
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    rewind($file);

    $added = 0;
    $skipped = 0;

    while (($row = fgetcsv($file, 0, $sep, '"', '\\')) !== false) {
        if (count($row) < 2) {
            $skipped++;
            continue;
        }
        // Excel can put a BOM at the start of the file
        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);

        if (scr_save($row[0], $row[1])) {
            $added++;
        } else {
            $skipped++;
        }
    }
    fclose($file);

    scr_back(['msg' => 'imported', 'added' => $added, 'skipped' => $skipped]);
}

function scr_export()
{
    scr_check('scr_export');
    global $wpdb;

    $rows = $wpdb->get_results("SELECT old_path, new_url FROM " . scr_table() . " ORDER BY old_path");

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=redirects-' . gmdate('Y-m-d') . '.csv');

    // BOM so that Excel opens Polish letters correctly
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['old_url', 'new_url'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [$r->old_path, $r->new_url], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

function scr_add()
{
    scr_check('scr_add');

    $old = isset($_POST['old_url']) ? sanitize_text_field(wp_unslash($_POST['old_url'])) : '';
    $new = isset($_POST['new_url']) ? sanitize_text_field(wp_unslash($_POST['new_url'])) : '';

    scr_back(['msg' => scr_save($old, $new) ? 'added' : 'invalid']);
}

function scr_delete()
{
    $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    scr_check('scr_delete_' . $id);

    global $wpdb;
    $wpdb->delete(scr_table(), ['id' => $id]);

    scr_back(['msg' => 'deleted', 'count' => 1]);
}

function scr_bulk_delete()
{
    scr_check('scr_bulk_delete');
    global $wpdb;

    $ids = isset($_POST['ids']) ? array_filter(array_map('absint', (array) $_POST['ids'])) : [];
    if (!$ids) {
        scr_back(['msg' => 'none']);
    }

    $in = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM " . scr_table() . " WHERE id IN ($in)", $ids));

    scr_back(['msg' => 'deleted', 'count' => count($ids)]);
}

function scr_notice()
{
    $msg = isset($_GET['msg']) ? sanitize_key($_GET['msg']) : '';
    $type = 'success';

    if ($msg === 'imported') {
        $text = sprintf(
            __('Import finished. Added or updated: %1$d, skipped: %2$d.', 'simple-csv-redirects'),
            absint($_GET['added'] ?? 0),
            absint($_GET['skipped'] ?? 0)
        );
    } elseif ($msg === 'added') {
        $text = __('Redirect saved.', 'simple-csv-redirects');
    } elseif ($msg === 'deleted') {
        $text = sprintf(__('Deleted redirects: %d.', 'simple-csv-redirects'), absint($_GET['count'] ?? 0));
    } elseif ($msg === 'invalid') {
        $text = __('Not saved. Check both addresses: the new one must start with http://, https:// or /.', 'simple-csv-redirects');
        $type = 'error';
    } elseif ($msg === 'nofile') {
        $text = __('Choose a CSV file first.', 'simple-csv-redirects');
        $type = 'error';
    } elseif ($msg === 'none') {
        $text = __('No redirects were selected.', 'simple-csv-redirects');
        $type = 'warning';
    } else {
        return;
    }

    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
}

function scr_page()
{
    global $wpdb;
    $table = scr_table();

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);

    $where = '';
    $args = [];
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where = ' WHERE old_path LIKE %s OR new_url LIKE %s';
        $args = [$like, $like];
    }

    $count_sql = "SELECT COUNT(*) FROM $table" . $where;
    $found = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, $args)) : $wpdb->get_var($count_sql));
    $pages = max(1, (int) ceil($found / SCR_PER_PAGE));
    $page = min($page, $pages);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table" . $where . " ORDER BY old_path LIMIT %d OFFSET %d",
        array_merge($args, [SCR_PER_PAGE, ($page - 1) * SCR_PER_PAGE])
    ));

    $stats = $wpdb->get_row("SELECT COUNT(*) AS total, COALESCE(SUM(hits), 0) AS hits FROM $table");
    $unused = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE hits = 0");

    $post = esc_url(admin_url('admin-post.php'));
    $export = wp_nonce_url(admin_url('admin-post.php?action=scr_export'), 'scr_export');
    $date_format = get_option('date_format') . ' ' . get_option('time_format');
    ?>
    <div class="wrap scr">
        <div class="scr-header">
            <h1>Simple CSV Redirects</h1>
            <?php if ($stats->total) : ?>
                <a class="button" href="<?php echo esc_url($export); ?>"><?php esc_html_e('Export CSV', 'simple-csv-redirects'); ?></a>
            <?php endif; ?>
        </div>
        <?php scr_notice(); ?>

        <div class="scr-stats">
            <div class="scr-stat">
                <span class="scr-stat-num"><?php echo esc_html(number_format_i18n($stats->total)); ?></span>
                <span class="scr-stat-label"><?php esc_html_e('Redirects', 'simple-csv-redirects'); ?></span>
            </div>
            <div class="scr-stat">
                <span class="scr-stat-num"><?php echo esc_html(number_format_i18n($stats->hits)); ?></span>
                <span class="scr-stat-label"><?php esc_html_e('Visits redirected', 'simple-csv-redirects'); ?></span>
            </div>
            <div class="scr-stat">
                <span class="scr-stat-num"><?php echo esc_html(number_format_i18n($unused)); ?></span>
                <span class="scr-stat-label"><?php esc_html_e('Never used', 'simple-csv-redirects'); ?></span>
            </div>
        </div>

        <div class="scr-boxes">
            <form class="scr-box" method="post" action="<?php echo $post; ?>" enctype="multipart/form-data">
                <h2><?php esc_html_e('Import from CSV', 'simple-csv-redirects'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Two columns: old URL, new URL. Comma or semicolon. Rows that are not valid are skipped, existing paths get the new address.', 'simple-csv-redirects'); ?>
                </p>
                <label class="scr-drop">
                    <input type="file" name="csv" accept=".csv,text/csv">
                    <span class="dashicons dashicons-upload"></span>
                    <span class="scr-drop-name" data-empty="<?php esc_attr_e('Drop a CSV file here or click to choose', 'simple-csv-redirects'); ?>">
                        <?php esc_html_e('Drop a CSV file here or click to choose', 'simple-csv-redirects'); ?>
                    </span>
                </label>
                <input type="hidden" name="action" value="scr_import">
                <?php wp_nonce_field('scr_import'); ?>
                <?php submit_button(__('Import', 'simple-csv-redirects'), 'primary', 'submit', false); ?>
            </form>

            <form class="scr-box" method="post" action="<?php echo $post; ?>">
                <h2><?php esc_html_e('Add one redirect', 'simple-csv-redirects'); ?></h2>
                <label class="scr-field">
                    <?php esc_html_e('Old URL or path', 'simple-csv-redirects'); ?>
                    <input type="text" name="old_url" placeholder="/stara-strona" required>
                </label>
                <label class="scr-field">
                    <?php esc_html_e('New URL', 'simple-csv-redirects'); ?>
                    <input type="text" name="new_url" placeholder="https://example.com/nowa-strona" required>
                </label>
                <input type="hidden" name="action" value="scr_add">
                <?php wp_nonce_field('scr_add'); ?>
                <?php submit_button(__('Save', 'simple-csv-redirects'), 'primary', 'submit', false); ?>
            </form>
        </div>

        <div class="scr-list-head">
            <h2><?php esc_html_e('Saved redirects', 'simple-csv-redirects'); ?></h2>
            <form method="get" class="scr-search">
                <input type="hidden" name="page" value="scr-redirects">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search addresses', 'simple-csv-redirects'); ?>">
                <?php submit_button(__('Search', 'simple-csv-redirects'), '', '', false); ?>
            </form>
        </div>

        <form method="post" action="<?php echo $post; ?>">
            <input type="hidden" name="action" value="scr_bulk_delete">
            <?php wp_nonce_field('scr_bulk_delete'); ?>

            <table class="widefat scr-table">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" id="scr-all"></td>
                        <th><?php esc_html_e('Old path', 'simple-csv-redirects'); ?></th>
                        <th><?php esc_html_e('New URL', 'simple-csv-redirects'); ?></th>
                        <th class="scr-num"><?php esc_html_e('Visits', 'simple-csv-redirects'); ?></th>
                        <th><?php esc_html_e('Last visit', 'simple-csv-redirects'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr>
                        <td colspan="6" class="scr-empty">
                            <span class="dashicons dashicons-randomize"></span>
                            <?php if ($search !== '') : ?>
                                <?php esc_html_e('Nothing found for this search.', 'simple-csv-redirects'); ?>
                            <?php else : ?>
                                <?php esc_html_e('No redirects yet. Import a CSV file or add one above.', 'simple-csv-redirects'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r) : ?>
                    <?php $del = wp_nonce_url(admin_url('admin-post.php?action=scr_delete&id=' . $r->id), 'scr_delete_' . $r->id); ?>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="scr-check" name="ids[]" value="<?php echo esc_attr($r->id); ?>"></th>
                        <td>
                            <code><?php echo esc_html($r->old_path); ?></code>
                            <a class="scr-test" href="<?php echo esc_url(home_url($r->old_path)); ?>" target="_blank" title="<?php esc_attr_e('Open the old address to test', 'simple-csv-redirects'); ?>">
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        </td>
                        <td>
                            <span class="scr-arrow">&rarr;</span>
                            <a href="<?php echo esc_url($r->new_url); ?>" target="_blank"><?php echo esc_html($r->new_url); ?></a>
                            <?php if (scr_is_external($r->new_url)) : ?>
                                <span class="scr-badge"><?php esc_html_e('external', 'simple-csv-redirects'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="scr-num"><?php echo esc_html(number_format_i18n($r->hits)); ?></td>
                        <td class="scr-date">
                            <?php echo $r->last_hit ? esc_html(mysql2date($date_format, $r->last_hit)) : '<span class="scr-muted">' . esc_html__('never', 'simple-csv-redirects') . '</span>'; ?>
                        </td>
                        <td class="scr-actions">
                            <a class="scr-delete" href="<?php echo esc_url($del); ?>" data-confirm="<?php esc_attr_e('Delete this redirect?', 'simple-csv-redirects'); ?>">
                                <?php esc_html_e('Delete', 'simple-csv-redirects'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="scr-footer">
                <?php if ($rows) : ?>
                    <button type="submit" class="button" data-confirm="<?php esc_attr_e('Delete all selected redirects?', 'simple-csv-redirects'); ?>">
                        <?php esc_html_e('Delete selected', 'simple-csv-redirects'); ?>
                    </button>
                <?php endif; ?>
                <span class="scr-muted">
                    <?php printf(esc_html__('Shown: %1$d of %2$d', 'simple-csv-redirects'), count($rows), $found); ?>
                </span>
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%', remove_query_arg(['msg', 'added', 'skipped', 'count'])),
                    'format' => '',
                    'current' => $page,
                    'total' => $pages,
                ]);
                ?>
            </div>
        </form>
    </div>
    <?php
}
