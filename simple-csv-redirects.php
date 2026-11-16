<?php
/*
Plugin Name: Simple CSV Redirects
Description: 301 redirects imported from a CSV file (old URL, new URL) or added by hand.
Version: 1.0.0
Author: Sofiya Kazak
Text Domain: simple-csv-redirects
*/

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'scr_install');
add_action('template_redirect', 'scr_redirect');
add_action('admin_menu', 'scr_menu');
add_action('admin_enqueue_scripts', 'scr_styles');
add_action('admin_post_scr_import', 'scr_import');
add_action('admin_post_scr_add', 'scr_add');
add_action('admin_post_scr_delete', 'scr_delete');

function scr_table()
{
    global $wpdb;
    return $wpdb->prefix . 'scr_redirects';
}

function scr_install()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE " . scr_table() . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        old_path varchar(255) NOT NULL,
        new_url varchar(255) NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY old_path (old_path)
    ) " . $wpdb->get_charset_collate() . ";";

    dbDelta($sql);
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

function scr_save($old, $new)
{
    global $wpdb;

    $path = scr_path($old);
    $new = trim($new);

    if ($path === '' || !preg_match('#^(https?://|/)#', $new)) {
        return false;
    }

    // a redirect to the same page would loop forever
    $host = wp_parse_url($new, PHP_URL_HOST);
    $site = wp_parse_url(home_url(), PHP_URL_HOST);
    if (scr_path($new) === $path && (!$host || $host === $site)) {
        return false;
    }

    // replace() overwrites the row if this old path is already saved
    return $wpdb->replace(scr_table(), [
        'old_path' => $path,
        'new_url' => esc_url_raw($new),
    ]) !== false;
}

function scr_redirect()
{
    global $wpdb;

    $path = scr_path($_SERVER['REQUEST_URI']);
    if ($path === '') {
        return;
    }

    $new = $wpdb->get_var($wpdb->prepare(
        "SELECT new_url FROM " . scr_table() . " WHERE old_path = %s",
        $path
    ));

    if ($new) {
        wp_redirect($new, 301);
        exit;
    }
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

function scr_styles($hook)
{
    if ($hook !== 'tools_page_scr-redirects') {
        return;
    }
    wp_enqueue_style('scr-admin', plugin_dir_url(__FILE__) . 'assets/css/admin.css', [], '1.0.0');
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

    scr_back(['msg' => 'deleted']);
}

function scr_notice()
{
    $msg = isset($_GET['msg']) ? sanitize_key($_GET['msg']) : '';

    if ($msg === 'imported') {
        $text = sprintf(
            __('Import finished. Added or updated: %1$d, skipped: %2$d.', 'simple-csv-redirects'),
            absint($_GET['added'] ?? 0),
            absint($_GET['skipped'] ?? 0)
        );
        $type = 'success';
    } elseif ($msg === 'added') {
        $text = __('Redirect saved.', 'simple-csv-redirects');
        $type = 'success';
    } elseif ($msg === 'deleted') {
        $text = __('Redirect deleted.', 'simple-csv-redirects');
        $type = 'success';
    } elseif ($msg === 'invalid') {
        $text = __('Not saved. Check both addresses: the new one must start with http://, https:// or /.', 'simple-csv-redirects');
        $type = 'error';
    } elseif ($msg === 'nofile') {
        $text = __('Choose a CSV file first.', 'simple-csv-redirects');
        $type = 'error';
    } else {
        return;
    }

    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
}

function scr_page()
{
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM " . scr_table() . " ORDER BY old_path");
    $action = esc_url(admin_url('admin-post.php'));
    ?>
    <div class="wrap scr">
        <h1>Simple CSV Redirects</h1>
        <?php scr_notice(); ?>

        <div class="scr-boxes">
            <form class="scr-box" method="post" action="<?php echo $action; ?>" enctype="multipart/form-data">
                <h2><?php esc_html_e('Import from CSV', 'simple-csv-redirects'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Two columns: old URL, new URL. Comma or semicolon. Rows that are not valid are skipped.', 'simple-csv-redirects'); ?>
                </p>
                <input type="file" name="csv" accept=".csv">
                <input type="hidden" name="action" value="scr_import">
                <?php wp_nonce_field('scr_import'); ?>
                <?php submit_button(__('Import', 'simple-csv-redirects')); ?>
            </form>

            <form class="scr-box" method="post" action="<?php echo $action; ?>">
                <h2><?php esc_html_e('Add one redirect', 'simple-csv-redirects'); ?></h2>
                <label>
                    <?php esc_html_e('Old URL or path', 'simple-csv-redirects'); ?>
                    <input type="text" name="old_url" class="regular-text" placeholder="/stara-strona">
                </label>
                <label>
                    <?php esc_html_e('New URL', 'simple-csv-redirects'); ?>
                    <input type="text" name="new_url" class="regular-text" placeholder="https://example.com/nowa-strona">
                </label>
                <input type="hidden" name="action" value="scr_add">
                <?php wp_nonce_field('scr_add'); ?>
                <?php submit_button(__('Save', 'simple-csv-redirects')); ?>
            </form>
        </div>

        <h2><?php printf(esc_html__('Saved redirects (%d)', 'simple-csv-redirects'), count($rows)); ?></h2>
        <table class="widefat striped scr-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Old path', 'simple-csv-redirects'); ?></th>
                    <th><?php esc_html_e('New URL', 'simple-csv-redirects'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="3"><?php esc_html_e('No redirects yet.', 'simple-csv-redirects'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r) : ?>
                <?php $del = wp_nonce_url(admin_url('admin-post.php?action=scr_delete&id=' . $r->id), 'scr_delete_' . $r->id); ?>
                <tr>
                    <td><code><?php echo esc_html($r->old_path); ?></code></td>
                    <td><a href="<?php echo esc_url($r->new_url); ?>" target="_blank"><?php echo esc_html($r->new_url); ?></a></td>
                    <td class="scr-actions">
                        <a class="scr-delete" href="<?php echo esc_url($del); ?>"
                           onclick="return confirm('<?php echo esc_js(__('Delete this redirect?', 'simple-csv-redirects')); ?>');">
                            <?php esc_html_e('Delete', 'simple-csv-redirects'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
