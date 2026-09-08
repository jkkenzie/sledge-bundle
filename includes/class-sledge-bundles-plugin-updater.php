<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress plugin updates from the iYi license server.
 *
 * Talks to `/wp-json/iyi/v1/licenses/update-check` and `/plugin-info` on the
 * main site. The ZIP is only returned when the key is active, Updates is on,
 * and this domain holds an activation.
 */
final class Sledge_Bundles_Plugin_Updater
{
    const ERROR_OPTION = 'sledge_bundles_update_last_error';

    private $manager;
    private $injecting = false;

    public function __construct(Sledge_Bundles_License_Manager $manager)
    {
        $this->manager = $manager;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check'));
        add_filter('site_transient_update_plugins', array($this, 'inject'));
        add_filter('plugins_api', array($this, 'info'), 20, 3);
        add_filter('upgrader_package_options', array($this, 'refresh_package_url'));
        add_filter('update_plugins_iyisolutions.com', array($this, 'host_update'), 10, 4);
        add_action('admin_notices', array($this, 'notice'));
        add_action('load-plugins.php', array($this, 'maybe_force_check'), 1);
        add_action('load-update-core.php', array($this, 'maybe_force_check'), 1);
        add_action('load-update.php', array($this, 'maybe_force_check'), 1);
        add_action('upgrader_process_complete', array($this, 'after_upgrade'), 20, 2);
        add_action('sledge_bundles_flush_plugin_update_cache', array($this, 'flush_cache'));
    }

    public function maybe_force_check()
    {
        if (empty($_GET['force-check'])) {
            return;
        }

        $this->flush_cache();
        delete_site_transient('update_plugins');
    }

    public function flush_cache()
    {
        $key = trim($this->manager->get_stored_license_key());
        $versions = array_unique(array(SLEDGE_BUNDLES_VERSION, $this->installed_version()));
        foreach ($versions as $version) {
            foreach (array('update-check', 'plugin-info') as $action) {
                delete_site_transient('iyi_upd_' . md5($action . '|' . $key . '|' . $version));
            }
        }
    }

    /**
     * After this plugin is updated, WordPress immediately rebuilds the update
     * list while the previous PHP files are still loaded. Drop that stale row
     * so the Plugins screen does not offer the version that was just installed.
     *
     * @param WP_Upgrader $upgrader Upgrader instance.
     * @param array       $options  Hook extra.
     */
    public function after_upgrade($upgrader, $options)
    {
        unset($upgrader);

        if ((isset($options['action']) ? $options['action'] : '') !== 'update' || (isset($options['type']) ? $options['type'] : '') !== 'plugin') {
            return;
        }

        $basename = plugin_basename(SLEDGE_BUNDLES_FILE);
        $plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : array();
        if (!in_array($basename, $plugins, true)) {
            return;
        }

        $this->flush_cache();
        delete_site_transient('update_plugins');
        delete_site_option(self::ERROR_OPTION);

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
    }

    public function check($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        return $this->apply_payload($transient);
    }

    /**
     * Inject into a cached WordPress update list so Plugins does not wait
     * up to an hour for `wp_update_plugins()` to run again.
     */
    public function inject($transient)
    {
        if (!is_object($transient) || $this->injecting) {
            return $transient;
        }

        if (!is_admin()) {
            return $transient;
        }

        $this->injecting = true;
        $transient = $this->apply_payload($transient);
        $this->injecting = false;

        return $transient;
    }

    /**
     * WordPress 5.8+ Update URI host filter.
     */
    public function host_update($update, $plugin_data, $plugin_file, $locales)
    {
        unset($plugin_data, $locales);

        if ($plugin_file !== plugin_basename(SLEDGE_BUNDLES_FILE)) {
            return $update;
        }

        $data = $this->request('update-check');
        if (!is_array($data) || empty($data['update_available']) || empty($data['package']) || empty($data['version'])) {
            return false;
        }

        if (version_compare((string) $data['version'], $this->installed_version(), '<=')) {
            return false;
        }

        return array(
            'slug'         => $this->manager->get_plugin_slug(),
            'plugin'       => $plugin_file,
            'version'      => (string) $data['version'],
            'url'          => (string) (isset($data['homepage']) ? $data['homepage'] : ''),
            'package'      => (string) $data['package'],
            'requires'     => (string) (isset($data['requires']) ? $data['requires'] : ''),
            'tested'       => (string) (isset($data['tested']) ? $data['tested'] : ''),
            'requires_php' => (string) (isset($data['requires_php']) ? $data['requires_php'] : ''),
        );
    }

    public function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args)) {
            return $result;
        }

        $slug = (string) (isset($args->slug) ? $args->slug : '');
        if ($slug !== $this->manager->get_plugin_slug()) {
            return $result;
        }

        $data = $this->request('plugin-info');
        if (!is_array($data) || empty($data['valid'])) {
            return $result;
        }

        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : array();

        return (object) array(
            'name'          => (string) (isset($data['name']) ? $data['name'] : $slug),
            'slug'          => $slug,
            'version'       => (string) (isset($data['version']) ? $data['version'] : ''),
            'author'        => (string) (isset($data['author']) ? $data['author'] : 'iYi Solutions'),
            'homepage'      => (string) (isset($data['homepage']) ? $data['homepage'] : ''),
            'requires'      => (string) (isset($data['requires']) ? $data['requires'] : ''),
            'tested'        => (string) (isset($data['tested']) ? $data['tested'] : ''),
            'requires_php'  => (string) (isset($data['requires_php']) ? $data['requires_php'] : ''),
            'sections'      => $sections,
            'download_link' => (string) (isset($data['download_link']) ? $data['download_link'] : ''),
        );
    }

    /**
     * Package tokens expire in 20 minutes; refresh right before WordPress downloads.
     */
    public function refresh_package_url($options)
    {
        if (!is_array($options)) {
            return $options;
        }

        $hook = '';
        if (isset($options['hook_extra']['plugin'])) {
            $hook = (string) $options['hook_extra']['plugin'];
        }
        $basename = plugin_basename(SLEDGE_BUNDLES_FILE);
        if ($hook !== '' && $hook !== $basename) {
            return $options;
        }

        $package = isset($options['package']) ? (string) $options['package'] : '';
        if ($package === '' || strpos($package, '/api/licenses/package') === false) {
            if ($package === '' || strpos($package, '/licenses/package') === false) {
                return $options;
            }
        }

        $data = $this->request('update-check', true);
        if (is_array($data) && !empty($data['package'])) {
            $options['package'] = (string) $data['package'];
        }

        return $options;
    }

    public function notice()
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen === null || $screen->id !== 'plugins') {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        $error = (string) get_site_option(self::ERROR_OPTION, '');
        if ($error !== '') {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(sprintf(
                /* translators: %s: error message from the license server. */
                __('Sledge Bundles could not check for updates: %s', 'sledge-bundles'),
                $error
            ));
            echo ' <a href="' . esc_url(admin_url('plugins.php?force-check=1')) . '">';
            echo esc_html__('Try again', 'sledge-bundles');
            echo '</a></p></div>';
            return;
        }

        $data = $this->request('update-check');
        if (!is_array($data) || (isset($data['status']) ? $data['status'] : '') !== 'updates_not_allowed') {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'A newer version of Sledge Bundles may be available, but this license does not currently allow updates. Enable Updates on the license (or subscribe) in your iYi account.',
            'sledge-bundles'
        );
        echo '</p></div>';
    }

    private function apply_payload($transient)
    {
        $basename = plugin_basename(SLEDGE_BUNDLES_FILE);
        $installed = $this->installed_version();
        $data = $this->request('update-check');

        $offered = '';
        if (is_array($data) && !empty($data['version'])) {
            $offered = (string) $data['version'];
        } elseif (isset($transient->response[$basename]->new_version)) {
            $offered = (string) $transient->response[$basename]->new_version;
        }

        $already_installed = $offered !== '' && version_compare($offered, $installed, '<=');
        $no_remote_update = !is_array($data)
            || empty($data['update_available'])
            || empty($data['package'])
            || empty($data['version'])
            || $already_installed;

        if ($no_remote_update) {
            return $this->mark_current($transient, $basename, $installed);
        }

        $plugin = (object) array(
            'id'           => 'iyi/sledge-bundles',
            'slug'         => $this->manager->get_plugin_slug(),
            'plugin'       => $basename,
            'new_version'  => (string) $data['version'],
            'url'          => (string) (isset($data['homepage']) ? $data['homepage'] : ''),
            'package'      => (string) $data['package'],
            'requires'     => (string) (isset($data['requires']) ? $data['requires'] : ''),
            'tested'       => (string) (isset($data['tested']) ? $data['tested'] : ''),
            'requires_php' => (string) (isset($data['requires_php']) ? $data['requires_php'] : ''),
        );

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        $transient->response[$basename] = $plugin;
        if (isset($transient->no_update) && is_array($transient->no_update)) {
            unset($transient->no_update[$basename]);
        }

        return $transient;
    }

    private function mark_current($transient, $basename, $installed)
    {
        if (isset($transient->response) && is_array($transient->response)) {
            unset($transient->response[$basename]);
        }

        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = array();
        }

        $transient->no_update[$basename] = (object) array(
            'id'          => 'iyi/sledge-bundles',
            'slug'        => $this->manager->get_plugin_slug(),
            'plugin'      => $basename,
            'new_version' => $installed,
            'package'     => '',
        );

        return $transient;
    }

    /**
     * Version on disk. After an in-request upgrade the loaded constant is still
     * the previous version; the plugin header is already the new one.
     */
    private function installed_version()
    {
        if (!function_exists('get_plugin_data')) {
            $plugin_php = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($plugin_php)) {
                require_once $plugin_php;
            }
        }

        if (function_exists('get_plugin_data')) {
            $data = get_plugin_data(SLEDGE_BUNDLES_FILE, false, false);
            $from_file = trim((string) (isset($data['Version']) ? $data['Version'] : ''));
            if ($from_file !== '') {
                return $from_file;
            }
        }

        return SLEDGE_BUNDLES_VERSION;
    }

    private function request($action, $bypass_cache = false)
    {
        $key = trim($this->manager->get_stored_license_key());
        if ($key === '') {
            return null;
        }

        $version = $this->installed_version();
        $cache_key = 'iyi_upd_' . md5($action . '|' . $key . '|' . $version);

        if (!$bypass_cache) {
            $cached = get_site_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $client = $this->manager->get_client();
        $body = array(
            'license_key'    => $key,
            'domain'         => $this->manager->get_domain(),
            'plugin'         => $this->manager->get_plugin_slug(),
            'plugin_version' => $version,
            'request_nonce'  => wp_generate_uuid4(),
        );

        $data = $client->request_at($client->sibling_endpoint($action), $body);
        if (is_wp_error($data)) {
            update_site_option(self::ERROR_OPTION, $data->get_error_message());
            return null;
        }

        if (!is_array($data)) {
            update_site_option(
                self::ERROR_OPTION,
                __('The license server returned an invalid update response.', 'sledge-bundles')
            );
            return null;
        }

        delete_site_option(self::ERROR_OPTION);
        set_site_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);

        return $data;
    }
}
