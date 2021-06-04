<?php

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Plugin;
use Grav\Plugin\GitSyncBis\AdminController;
use Grav\Plugin\GitSyncBis\GitSyncBis;
use Grav\Plugin\GitSyncBis\Helper;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class GitSyncBisPlugin
 *
 * @package Grav\Plugin
 */
class GitSyncBisPlugin extends Plugin
{
    protected $controller;
    protected $git;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 1000],
            'onPageInitialized'    => ['onPageInitialized', 0],
            'onFormProcessed'      => ['onFormProcessed', 0]
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        require_once __DIR__ . '/vendor/autoload.php';
        $this->enable(['gitsyncbis' => ['synchronize', 0]]);
        $this->init();

        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths'  => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables'  => ['onTwigSiteVariables', 0],
                'onAdminMenu'          => ['onAdminMenu', 0],
                'onAdminSave'          => ['onAdminSave', 0],
                'onAdminAfterSave'     => ['onAdminAfterSave', 0],
                'onAdminAfterSaveAs'   => ['synchronize', 0],
                'onAdminAfterDelete'   => ['synchronize', 0],
                'onAdminAfterAddMedia' => ['synchronize', 0],
                'onAdminAfterDelMedia' => ['synchronize', 0],
            ]);

            return;
        } else {
            $config  = $this->config->get('plugins.' . $this->name);
            $route   = $this->grav['uri']->route();
            $webhook = isset($config['webhook']) ? $config['webhook'] : false;

            if ($route === $webhook) {
                try {
                    $this->synchronize();

                    echo json_encode([
                        'status'  => 'success',
                        'message' => 'GitSyncBis completed the synchronization'
                    ]);
                } catch (\Exception $e) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'GitSyncBis failed to synchronize'
                    ]);
                }
                exit;
            }
        }
    }

    public function onAdminMenu()
    {
        $base = rtrim($this->grav['base_url'], '/') . '/' . trim($this->grav['admin']->base, '/');
        $options = [
            //            'route' => $this->admin_route . '/plugins/tntsearch',
            'hint' => 'Synchronize GitSyncBis',
            'class' => 'gitsyncbis-sync',
            'data'  => [
                'gitsyncbis-useraction' => 'sync',
                'gitsyncbis-uri' => $base . '/plugins/git-sync-bis'
            ],
            'icon' => 'fa-' . $this->grav['plugins']->get('git-sync-bis')->blueprints()->get('icon')
        ];
        $this->grav['twig']->plugins_quick_tray['GitSyncBis'] = $options;
    }

    public function init()
    {
        if ($this->isAdmin()) {
            /** @var AdminController controller */
            $this->controller = new AdminController($this);
        } else {
            $this->controller      = new \stdClass;
            $this->controller->git = new GitSyncBis($this);
        }

        $this->git = $this->controller->git;
    }

    /**
     * Execute command in background, cross-platform
     * @param $cmd string Command to execute
     * @return int|string Command result
     */
    public static function execInBackground($cmd)
    {
        if (false !== stripos(PHP_OS, 'win')) {
            // Windows platform
            return pclose(popen('start /B ' . $cmd, 'r'));
        }
        // Unix-like platform
        return exec($cmd . ' > /dev/null &');
    }

    /**
     * Checks if /bin/plugin exists in the given path
     * @param string $path Path to check
     * @return boolean
     */
    private static function doesBinPluginExist($path)
    {
        // Find /bin path
        if (!($binPath = \realpath($path . DIRECTORY_SEPARATOR . 'bin'))) {
            // /bin path could not be found automatically
            return false;
        }

        // Find /plugin executable
        $pluginPath = $binPath . DIRECTORY_SEPARATOR . 'plugin';
        return \file_exists($pluginPath) && \is_readable($pluginPath);
    }

    /**
     * Finds the root path of the running Grav instance
     * @param array $config Git sync plugin config
     * @return string Grav root path
     */
    private static function findRootPath($config = [])
    {
        // Find root path
        if (\array_key_exists('root_path', $config)
            && $rootPath = \realpath($config['root_path'])) {
            return $rootPath;
        }
        // root path has not (or incorrectly) been set in configuration, guess automatically
        if ($rootPath = \realpath(\dirname(__DIR__, 2))) {
            return $rootPath;
        }
        // root path could not be found
        throw new \RuntimeException('Git synchronize error: Grav root path is not configured properly.');
    }
    
    /**
     * Executes "sync" CLI command in background
     * @param array $config Git sync plugin configuration
     * @return bool Returns true when the command has been executed
     * @throws \Exception
     */
    private function synchronizeInBackground($config = [])
    {
        $rootPath = static::findRootPath($config);
        if (true !== static::doesBinPluginExist($rootPath)) {
            throw new \RuntimeException(sprintf('/bin/plugin not found in %s, set path manually in Git sync plugin config', $rootPath));
        }
        // Execute CLI synchronize command in background
        $cmd = 'cd ' . $rootPath . ' && php bin' . DIRECTORY_SEPARATOR . 'plugin git-sync-bis sync';
        static::execInBackground($cmd);

        return true;
    }

    public function synchronize()
    {
        if (!Helper::isGitInstalled() || !Helper::isGitInitialized()) {
            return true;
        }

        // Synchronize in background ?
        $pluginConfig = $this->config->get('plugins.' . $this->name);

        if (isset($pluginConfig['execute_in_background']['enabled'])
            && true === $pluginConfig['execute_in_background']['enabled']) {
            return $this->synchronizeInBackground($pluginConfig['execute_in_background']);
        }

        $this->grav->fireEvent('onGitSyncBisBeforeSynchronize');

        if (!$this->git->isWorkingCopyClean()) {
            // commit any change
            $this->git->commit();
        }

        // synchronize with remote
        $this->git->sync();

        $this->grav->fireEvent('onGitSyncBisAfterSynchronize');

        return true;
    }

    public function reset()
    {
        if (!Helper::isGitInstalled() || !Helper::isGitInitialized()) {
            return true;
        }

        $this->grav->fireEvent('onGitSyncBisBeforeReset');

        $this->git->reset();

        $this->grav->fireEvent('onGitSyncBisAfterReset');

        return true;
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Set needed variables to display cart.
     */
    public function onTwigSiteVariables()
    {
        // workaround for admin plugin issue that doesn't properly unsubscribe
        // events upon plugin uninstall
        if (!class_exists('Grav\Plugin\GitSyncBis\Helper')) {
            return false;
        }

        $settings = [
            'first_time'    => !Helper::isGitInitialized(),
            'git_installed' => Helper::isGitInstalled()
        ];

        $this->grav['twig']->twig_vars['git_sync'] = $settings;

        if ($this->grav['uri']->path() === '/admin/plugins/git-sync-bis') {
            $this->grav['assets']->addCss('plugin://git-sync-bis/css-compiled/git-sync-bis.css');
        } else {
            $this->grav['assets']->addInlineJs('var GitSyncBis = ' . json_encode($settings) . ';');
        }

        $this->grav['assets']->addJs('plugin://git-sync-bis/js/app.js', ['loading' => 'defer', 'priority' => 0]);
        
        return true;
    }

    public function onPageInitialized()
    {
        if ($this->isAdmin() && $this->controller->isActive()) {
            $this->controller->execute();
            $this->controller->redirect();
        }


    }

    public function onAdminSave($event)
    {
        $obj           = $event['object'];
        $isPluginRoute = $this->grav['uri']->path() == '/admin/plugins/' . $this->name;
        $config        = $this->config->get('plugins.' . $this->name);

        if ($obj instanceof Data) {
            if (!$isPluginRoute || !Helper::isGitInstalled()) {
                if (!isset($config['sync_on_all_admin_save_events']) || !$config['sync_on_all_admin_save_events']) {
                    return true;
                }
            } else {
                // empty password, keep current one or encrypt if haven't already
                $password = $obj->get('password', false);
                if (!$password) { // set to !()
                    $current_password = $this->controller->git->getPassword();
                    // password exists but was never encrypted
                    if (substr($current_password, 0, 8) !== 'gitsyncbis-') {
                        $current_password = Helper::encrypt($current_password);
                    }
                } else {
                    // password is getting changed
                    $current_password = Helper::encrypt($password);
                }

                $obj->set('password', $current_password);
            }
        }

        return $obj;
    }

    public function onAdminAfterSave($event)
    {
        $obj           = $event['object'];
        $isPluginRoute = $this->grav['uri']->path() == '/admin/plugins/' . $this->name;
        $config        = $this->config->get('plugins.' . $this->name);

        /*
        $folders = $this->controller->git->getConfig('folders', []);
        if (!$isPluginRoute && !in_array('config', $folders)) {
            return true;
        }
        */

        if ($obj instanceof Data) {
            if (!$isPluginRoute || !Helper::isGitInstalled()) {
                if (!isset($config['sync_on_all_admin_save_events']) || !$config['sync_on_all_admin_save_events']) {
                    return true;
                }
            } else {
                $this->controller->git->setConfig($obj);

                // initialize git if not done yet
                $this->controller->git->initializeRepository();

                // set committer and remote data
                $this->controller->git->setUser();
                $this->controller->git->addRemote();
            }
        }

        $this->synchronize();

        return true;
    }

    public function onFormProcessed(Event $event)
    {
        $action = $event['action'];

        if ($action == 'gitsyncbis') {
            $this->synchronize();
        }
    }
}
