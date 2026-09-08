<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress admin interface for license activation and status.
 */
final class Sledge_Bundles_License_Admin
{
    private $manager;
    private $hook_suffix = '';

    public function __construct(Sledge_Bundles_License_Manager $manager)
    {
        $this->manager = $manager;
        add_action('admin_menu', array($this, 'add_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_sledge_bundles_license_activate', array($this, 'activate'));
        add_action('admin_post_sledge_bundles_license_deactivate', array($this, 'deactivate'));
        add_action('admin_post_sledge_bundles_license_check', array($this, 'check'));
        add_action('admin_notices', array($this, 'license_prompt_notice'));
        add_filter('plugin_action_links_' . SLEDGE_BUNDLES_BASENAME, array($this, 'plugin_action_links'));
    }

    public function license_prompt_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['page']) && 'sledge-bundles-license' === sanitize_key(wp_unslash($_GET['page']))) {
            return;
        }

        // Free / development builds do not require a license yet (no feature split).
        if (!defined('SLEDGE_BUNDLES_EDITION') || 'premium' !== SLEDGE_BUNDLES_EDITION) {
            return;
        }

        if ($this->manager->is_premium_enabled()) {
            return;
        }

        $status = $this->manager->get_status();
        $message = $status['has_key']
            ? __('Your Sledge Bundles license is not active, so bundle features are disabled.', 'sledge-bundles')
            : __('Sledge Bundles Premium is installed but not activated. Enter your license key to enable bundle features.', 'sledge-bundles');

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Sledge Bundles', 'sledge-bundles'),
            esc_html($message),
            esc_url(admin_url('options-general.php?page=sledge-bundles-license')),
            esc_html__('Manage license', 'sledge-bundles')
        );
    }

    public function plugin_action_links($links)
    {
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=sledge-bundles-license')),
            esc_html__('License', 'sledge-bundles')
        );
        return $links;
    }

    public function add_page()
    {
        $this->hook_suffix = add_options_page(
            __('Sledge Bundles License', 'sledge-bundles'),
            __('Sledge Bundles License', 'sledge-bundles'),
            'manage_options',
            'sledge-bundles-license',
            array($this, 'render')
        );
    }

    public function activate()
    {
        $this->authorize('sledge_bundles_license_activate');
        $key = isset($_POST['license_key']) ? wp_unslash($_POST['license_key']) : '';
        $this->redirect_with_result($this->manager->activate($key));
    }

    public function deactivate()
    {
        $this->authorize('sledge_bundles_license_deactivate');
        $this->redirect_with_result($this->manager->deactivate());
    }

    public function check()
    {
        $this->authorize('sledge_bundles_license_check');
        $this->redirect_with_result($this->manager->validate());
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== $this->hook_suffix && 'settings_page_sledge-bundles-license' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'sledge-bundles-license-admin',
            SLEDGE_BUNDLES_URL . 'css/license-admin.css',
            array(),
            SLEDGE_BUNDLES_VERSION
        );
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $status = $this->manager->get_status();
        $notice = isset($_GET['sledge_license_notice']) ? sanitize_key(wp_unslash($_GET['sledge_license_notice'])) : '';
        $message = isset($_GET['sledge_license_message']) ? sanitize_text_field(wp_unslash(rawurldecode((string) $_GET['sledge_license_message']))) : '';
        $premium_active = $this->manager->is_premium_enabled();
        ?>
        <div class="wrap sledge-bundles-license">
            <h1><?php esc_html_e('Sledge Bundles License', 'sledge-bundles'); ?></h1>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: plugin version */
                    esc_html__('Version %s', 'sledge-bundles'),
                    esc_html(SLEDGE_BUNDLES_VERSION)
                );
                ?>
            </p>

            <?php if ($notice) : ?>
                <div class="notice <?php echo 'success' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <div class="license-status <?php echo $premium_active ? 'valid' : 'invalid'; ?>">
                <?php
                echo $premium_active
                    ? esc_html__('Bundle features are active', 'sledge-bundles')
                    : esc_html__('Bundle features are inactive', 'sledge-bundles');
                ?>
            </div>

            <table class="widefat striped" style="max-width:760px;margin:20px 0;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'sledge-bundles'); ?></th>
                        <td>
                            <strong><?php echo esc_html(ucfirst($status['status'])); ?></strong>
                            <?php if (!empty($status['in_grace'])) : ?>
                                — <?php esc_html_e('using the temporary offline grace period', 'sledge-bundles'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Registered domain', 'sledge-bundles'); ?></th>
                        <td><code><?php echo esc_html($status['domain']); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Expiration', 'sledge-bundles'); ?></th>
                        <td><?php echo !empty($status['expires']) ? esc_html($status['expires']) : esc_html__('Not provided', 'sledge-bundles'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last check', 'sledge-bundles'); ?></th>
                        <td>
                            <?php
                            echo !empty($status['last_checked'])
                                ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $status['last_checked']))
                                : esc_html__('Never', 'sledge-bundles');
                            ?>
                        </td>
                    </tr>
                    <?php if (!empty($status['last_error'])) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last error', 'sledge-bundles'); ?></th>
                            <td><?php echo esc_html($status['last_error']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $update_error = class_exists('Sledge_Bundles_Plugin_Updater')
                        ? (string) get_site_option(Sledge_Bundles_Plugin_Updater::ERROR_OPTION, '')
                        : '';
                    if ($update_error !== '') :
                        ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last update check', 'sledge-bundles'); ?></th>
                            <td><?php echo esc_html($update_error); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Features', 'sledge-bundles'); ?></th>
                        <td>
                            <?php
                            echo $premium_active
                                ? esc_html__('Unlocked', 'sledge-bundles')
                                : esc_html__('Locked until a valid license is active', 'sledge-bundles');
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <input type="hidden" name="action" value="sledge_bundles_license_activate">
                <?php wp_nonce_field('sledge_bundles_license_activate'); ?>
                <label for="sledge-bundles-license-key"><strong><?php esc_html_e('License key', 'sledge-bundles'); ?></strong></label><br>
                <input id="sledge-bundles-license-key" name="license_key" type="text" class="regular-text"
                    value="" placeholder="<?php echo esc_attr($this->manager->get_masked_key()); ?>"
                    autocomplete="off" required>
                <?php submit_button($status['has_key'] ? __('Update and activate', 'sledge-bundles') : __('Activate license', 'sledge-bundles'), 'primary', 'submit', false); ?>
            </form>

            <?php if (!empty($status['has_key'])) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="action" value="sledge_bundles_license_check">
                    <?php wp_nonce_field('sledge_bundles_license_check'); ?>
                    <?php submit_button(__('Check now', 'sledge-bundles'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="sledge_bundles_license_deactivate">
                    <?php wp_nonce_field('sledge_bundles_license_deactivate'); ?>
                    <?php submit_button(__('Deactivate and release domain', 'sledge-bundles'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <p class="description" style="max-width:760px;margin-top:16px;">
                <?php esc_html_e('With an active license and Updates enabled, WordPress will offer new versions on Plugins → Installed Plugins (Dashboard → Updates). Bedrock sites can also require iyi/sledge-bundles from the iYi Composer repository.', 'sledge-bundles'); ?>
                <?php if (current_user_can('update_plugins')) : ?>
                    <a href="<?php echo esc_url(admin_url('plugins.php?force-check=1')); ?>">
                        <?php esc_html_e('Check for plugin updates', 'sledge-bundles'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private function authorize($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage this license.', 'sledge-bundles'));
        }
        check_admin_referer($action);
    }

    private function redirect_with_result($result)
    {
        $success = !is_wp_error($result);
        $message = $success
            ? __('License operation completed successfully.', 'sledge-bundles')
            : $result->get_error_message();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                   => 'sledge-bundles-license',
                    'sledge_license_notice'  => $success ? 'success' : 'error',
                    'sledge_license_message' => rawurlencode($message),
                ),
                admin_url('options-general.php')
            )
        );
        exit;
    }
}
