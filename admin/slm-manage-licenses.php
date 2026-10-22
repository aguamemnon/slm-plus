<?php

if (!defined('WPINC')) {
    die;
}

//add_action('plugins_loaded', 'get_user_info');
// TODO

function get_user_info()
{
    if (!current_user_can('manage_licenses')) {
        $response = array(
            'success' => false,
            'message' => esc_html__('You do not have permission to manage this license.', 'slm-plus'),
        );
        echo json_encode($response);
        die();
    }
}

function slm_manage_licenses_menu()
{
    //include_once('slm-list-licenses-class.php');
    $license_list = new SLM_List_Licenses();
    $multi = function_exists('slmms_licenses_overview');
    $view = $multi && (!isset($_GET['slmms_view']) ||
        sanitize_key(wp_unslash($_GET['slmms_view'])) !== 'classic') ? 'software' : 'classic';

    if ($view === 'classic' && isset($_REQUEST['action'])) { //Do list table form row action tasks
        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'delete_license') { //Delete link was clicked for a row in list table
            $license_list->delete_license_key(sanitize_text_field($_REQUEST['id']));
        }
    }
?>
    <div class="stats">
    </div>
    <div class="wrap">
        <h1>SLM Plus — Gestion des licences</h1>
        <?php if ($multi) : ?>
        <nav class="nav-tab-wrapper" aria-label="Type de licence" style="margin:16px 0 20px">
            <a class="nav-tab <?php echo $view === 'software' ? 'nav-tab-active' : ''; ?>"
               href="<?php echo esc_url(admin_url('admin.php?page=slm_overview&slmms_view=software')); ?>">Licences logicielles</a>
            <a class="nav-tab <?php echo $view === 'classic' ? 'nav-tab-active' : ''; ?>"
               href="<?php echo esc_url(admin_url('admin.php?page=slm_overview&slmms_view=classic')); ?>">Licences classiques</a>
        </nav>
        <?php endif; ?>
        <?php if ($view === 'software' && $multi) : ?>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=slmms-offers')); ?>">
                Configurer une offre WooCommerce</a></p>
            <?php slmms_licenses_overview(); ?>
        <?php else : ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=slm_manage_license')); ?>"
           class="page-title-action"><?php esc_html_e('Add New', 'slm-plus'); ?></a>
        <hr class="wp-header-end">

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-1">
                <div id="post-body-content">

                    <div class="overview">
                        <?php

                        $options    = get_option('slm_plugin_options');
                        $slm_stats = $options['slm_stats'];

                        if ($slm_stats == 1 && !empty($slm_stats)) {
                            include SLM_ADMIN_ADDONS . 'partials/stats.php';
                        }
                        ?>
                    </div>
                    <div class="meta-box-sortables ui-sortable">
                        <form id="licenses-filter" method="get">
                            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                            <?php if ($multi) : ?><input type="hidden" name="slmms_view" value="classic" /><?php endif; ?>
                            <?php
                            $license_list->prepare_items();
                            $license_list->search_box(__('Search', 'slm-plus'), 'search-box-id');
                            $license_list->views();
                            $license_list->display(); ?>
                        </form>
                    </div>
                </div>
            </div>
            <br class="clear">
            <div id="post-body" class="metabox-holder columns-1">

            </div>
        </div>
        <?php endif; ?>
    </div>
<?php
}
