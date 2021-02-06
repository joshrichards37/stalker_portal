<?php

use Stalker\Lib\Core\Mysql;
use Stalker\Lib\Core\Config;
use Stalker\Lib\Core\Cache;

class SmartLauncherAppsManager
{
    private $lang;
    private $callback;
    private static $instance;

    public static function getInstance($lang = null){
        if (self::$instance !== null){
            return self::$instance;
        }
        return new self($lang);
    }

    public function __construct($lang = null){
        $this->lang = $lang ? $lang : 'en';
        self::$instance = $this;
    }

    public function setNotificationCallback($callback){
        if (!is_callable($callback)){
            throw new SmartLauncherAppsManagerException('Not valid callback');
        }
        $this->callback = $callback;
    }

    public function sendToCallback($msg){
        if (is_null($this->callback)){
            return;
        }

        call_user_func($this->callback, $msg);
    }

    public static function getLauncherUrl(){

        $core = Mysql::getInstance()->from('launcher_apps')->where(array('type' => 'core', 'status' => 1))->get()->first();

        if (empty($core)){
            return false;
        }

        if (!empty($core['config'])) {
            $core['config'] = json_decode($core['config'], true);
        }

        $url = 'http'.(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 's' : '')
        .'://'.(strpos($_SERVER['HTTP_HOST'], ':') > 0 ? $_SERVER['HTTP_HOST'] : $_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'])
        .'/'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
        .$core['alias']
        .'/'.$core['current_version'].'/'.(isset($core['config']['uris']['app']) ? $core['config']['uris']['app'].'/' : 'app/');

        return $url;
    }

    public static function getLauncherProfileUrl(){

        return 'http'.(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 's' : '')
            .'://'.(strpos($_SERVER['HTTP_HOST'], ':') > 0 ? $_SERVER['HTTP_HOST'] : $_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'])
            .'/'.Config::getSafe('portal_url', '/stalker_portal/')
            .'/server/api/launcher_profile.php';
    }

    public function getAppInfoByUrl($url, $force_npm = false) {

        $app = $original_app = Mysql::getInstance()->from('launcher_apps')->where(array('url' => $url))->get()->first();

        if (empty($app)){
            return null;
        }

        return $this->getAppInfo($app['id'], $force_npm);
    }

    /**
     * @param int $app_id
     * @param bool $force_npm
     * @return mixed
     * @throws SmartLauncherAppsManagerException
     */
    public function getAppInfo($app_id, $force_npm = false) {

        $app = $original_app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)) {
            throw new SmartLauncherAppsManagerException('App not found, id=' . $app_id);
        }

        if (empty($app['alias']) || $force_npm) {

            $this->sendToCallback('Getting info for '. $app['url'].'...');

            Cache::getInstance()->del($app_id.'_launcher_app_info');
            $info = self::getNpmInfo($app);

            if (empty($info)) {
                throw new SmartLauncherAppsManagerException('Unable to get info for ' . $app['url']);
            }

            $app['type'] = isset($info['config']['type']) ? $info['config']['type'] : null;
            $app['alias'] = $info['name'];
            $app['name'] = $app['type'] == 'app' && isset($info['config']['name']) ? $info['config']['name'] : $info['name'];
            $app['description'] = isset($info['config']['description']) ? $info['config']['description'] : (isset($info['description']) ? $info['description'] : '');
            $app['available_version'] = isset($info['version']) ? $info['version'] : '';
            $app['author'] = isset($info['author']) ? $info['author'] : '';
            $app['category'] = isset($info['config']['category']) ? $info['config']['category'] : null;
            $app['is_unique'] = isset($info['config']['unique']) && $info['config']['unique'] ? 1 : 0;

            $update_data = array();

            if (!$original_app['alias'] && $app['alias']) {
                $update_data['alias'] = $app['alias'];
            }

            if (!$original_app['name'] && $app['name']) {
                $update_data['name'] = $app['name'];
            }

            if (!$original_app['description'] && $app['description']) {
                $update_data['description'] = $app['description'];
            }

            if (!$original_app['author'] && $app['author'] || $original_app['author'] != $app['author']) {
                $update_data['author'] = $app['author'];
            }

            $update_data['category'] = $app['category'];
            $update_data['available_version'] = $app['available_version'];

            if (!empty($update_data)) {
                Mysql::getInstance()->update('launcher_apps', $update_data, array('id' => $app_id));
            }

        }else{
            $app['is_unique'] = (int) $app['is_unique'];
        }

        unset($app['options']);

        $app['icon'] = '';
        $app['icon_big'] = '';
        $app['backgroundColor'] = '';

        if ($app['config']){
            $app['config'] = json_decode($app['config'], true);
        }

        if ($app['current_version']){

            $app_path = realpath(PROJECT_PATH.'/../../'
                .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                .($app['type'] == 'plugin' ? 'plugins/' : '')
                .$app['url']
                .'/'.$app['current_version']);

            $app['app_path'] = $app_path;

            $app['installed'] = $app_path && is_dir($app_path);

            if ($app['installed'] && isset($app['config']['uris']['icons']['720']['logoNormal']) && !empty($_SERVER['HTTP_HOST'])){

                $icon_path = realpath($app_path.'/'.(isset($app['config']['uris']['app']) ? $app['config']['uris']['app'] : 'app').'/'.$app['config']['uris']['icons']['720']['logoNormal']);

                $app['icon'] = $icon_path && is_readable($icon_path) ?
                        'http'.(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 's' : '')
                        .'://'.(strpos($_SERVER['HTTP_HOST'], ':') > 0 ? $_SERVER['HTTP_HOST'] : $_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'])
                        .'/'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                        .$app['alias']
                        .'/'.$app['current_version'].'/'.(isset($app['config']['uris']['app']) ? $app['config']['uris']['app'] : 'app').'/'
                        .$app['config']['uris']['icons']['720']['logoNormal']
                    : '';

                $icon_big_path = realpath($app_path.'/'.(isset($app['config']['uris']['app']) ? $app['config']['uris']['app'] : 'app').'/'.$app['config']['uris']['icons']['1080']['logoNormal']);

                $app['icon_big'] = $icon_big_path && is_readable($icon_big_path) ?
                    'http'.(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 's' : '')
                    .'://'.(strpos($_SERVER['HTTP_HOST'], ':') > 0 ? $_SERVER['HTTP_HOST'] : $_SERVER['HTTP_HOST'].':'.$_SERVER['SERVER_PORT'])
                    .'/'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                    .$app['alias']
                    .'/'.$app['current_version'].'/'.(isset($app['config']['uris']['app']) ? $app['config']['uris']['app'] : 'app').'/'
                    .$app['config']['uris']['icons']['1080']['logoNormal']
                    : '';

                if ($app['icon'] || $app['icon_big']){
                    $app['backgroundColor'] = isset($app['config']['colors']['splashBackground']) ? $app['config']['colors']['splashBackground'] : '';
                }
            }
        }else{
            $app['installed'] = false;
        }

        if ($app['localization'] && ($localization = json_decode($app['localization'], true))){
            if (!empty($localization[$this->lang]['name'])){
                $app['name'] = $localization[$this->lang]['name'];
            }

            if (!empty($localization[$this->lang]['description'])){
                $app['description'] = $localization[$this->lang]['description'];
            }
        }

        return $app;
    }

    public function updateAllAppsInfo(){

        $this->resetAppsCache();

        $apps = Mysql::getInstance()->from('launcher_apps')->get()->all();

        foreach ($apps as $app){
            try {
                $this->getAppInfo($app['id'], true);
            } catch (\SmartLauncherAppsManagerException $e) {
                $this->sendToCallback('Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param $app_id
     * @return array
     * @throws SmartLauncherAppsManagerException
     */
    public function getAppVersions($app_id){

        $app = $original_app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)){
            throw new SmartLauncherAppsManagerException('App not found, id='.$app_id);
        }

        $info = self::getNpmInfo($app);

        if (empty($info)){
            throw new SmartLauncherAppsManagerException('Unable to get info for '.$app['url']);
        }

        $versions = array();

        if (isset($info['versions']) && is_string($info['versions'])){
            $info['versions'] = array($info['versions']);
        }

        $option_values = json_decode($app['options'], true);

        if (empty($option_values)){
            $option_values = array();
        }

        if (isset($info['versions']) && is_array($info['versions'])){

            if (array_key_exists('time', $info)) {
                unset($info['time']['modified']);
                unset($info['time']['created']);
            } else {
                $info['time'] = array_combine($info['versions'], array_pad(array(), count($info['versions']), 0));
            }

            foreach ($info['time'] as $ver => $time){

                $version = array(
                    'version'     => $ver,
                    'published'   => strtotime($time),
                    'installed'   => is_dir(realpath(PROJECT_PATH.'/../../'
                        .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                        .($app['type'] == 'plugin' ? 'plugins/' : '')
                        .$app['alias']
                        .'/'.$ver)),
                    'current'     => $ver == $app['current_version'],
                );

                $info = self::getNpmInfo($app, $ver);

                $option_list = isset($info['config']['options']) ? $info['config']['options'] : array();

                if (isset($option_list['name'])){
                    $option_list = array($option_list);
                }

                $option_list = array_map(function($option) use ($option_values){

                    if (isset($option_values[$option['name']])){
                        $option['value'] = $option_values[$option['name']];
                    }elseif (!isset($option['value'])){
                        $option['value'] = null;
                    }

                    if (isset($option['info'])){
                        $option['desc'] = $option['info'];
                    }

                    return $option;
                }, $option_list);

                $version['options'] = $option_list;

                $versions[] = $version;
            }
        }

        return $versions;
    }

    /**
     * @param int $app_id
     * @param string $version
     * @param bool $skip_info_check
     * @param bool $fake_install
     * @return bool
     * @throws SmartLauncherAppsManagerException
     */
    public function installApp($app_id, $version = null, $skip_info_check = false, $fake_install = false){

        $app = $original_app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)){
            throw new SmartLauncherAppsManagerException('App not found, id='.$app_id);
        }

        $npm = Npm::getInstance();

        if (!$skip_info_check) {

            $info = $npm->info($app['url'], $version);

            if (empty($info)) {
                throw new SmartLauncherAppsManagerException('Unable to get info for ' . $app['url']);
            }

            if ($app['current_version'] == $info['version']) {
                throw new SmartLauncherAppsManagerException('Nothing to install');
            }

            $version = $info['version'];

            $conflicts = $this->getConflicts($app_id, $info['version']);

            if (!empty($conflicts)) {
                throw new SmartLauncherAppsManagerConflictException('Conflicts detected', $conflicts);
            }
        }

        $this->sendToCallback("Installing ".$app['url']."...");

        if (!$fake_install) {
            $result = $npm->install($app['url'], $version);

            if (empty($result)) {
                throw new SmartLauncherAppsManagerException('Unable to install application ' . $app['url'] . '@' . $version);
            }
        }else{
            $result = true;
        }

        $update_data = array('current_version' => isset($info['version']) ? $info['version'] : '');
        $update_data['type'] = isset($info['config']['type']) ? $info['config']['type'] : null;

        if (empty($app['alias'])) {
            $update_data['alias'] = !empty($info['name']) ? $info['name'] : $app['url'];
        }

        if (empty($app['name'])) {
            $update_data['name'] = $update_data['type'] == 'app' && isset($info['config']['name']) ? $info['config']['name'] : (!empty($info['name']) ? $info['name'] : $app['url']);
        }

        if (empty($app['description'])) {
            $update_data['description'] = isset($info['config']['description']) ? $info['config']['description'] : (isset($info['description']) ? $info['description'] : '');
        }

        $update_data['author']    = isset($info['author']) ? $info['author'] : '';
        $update_data['category']  = isset($info['config']['category']) ? $info['config']['category'] : null;
        $update_data['is_unique'] = isset($info['config']['unique']) && $info['config']['unique'] ? 1 : 0;
        $update_data['status'] = 1;

        if (!empty($info['config'])){
            $update_data['config'] = json_encode($info['config']);
        }

        if ($version){
            $update_data['updated'] = 'NOW()';
        }

        Mysql::getInstance()->update('launcher_apps',
            $update_data,
            array('id' => $app_id)
        );

        $localization = $this->getAppLocalization($app_id, isset($info['version']) ? $info['version'] : null);

        if (!empty($localization)){

            Mysql::getInstance()->update('launcher_apps',
                array('localization' => json_encode($localization)),
                array('id' => $app_id)
            );
        }

        return $result;
    }

    /**
     * @param int $app_id
     * @param null $version
     * @return bool
     * @throws SmartLauncherAppsManagerException
     */
    public function updateApp($app_id, $version = null){

        Cache::getInstance()->del($app_id.'_launcher_app_info');

        return $this->installApp($app_id, $version);
    }

    /**
     * @param int $app_id
     * @param string $version
     * @return bool
     * @throws SmartLauncherAppsManagerException
     */
    public function deleteApp($app_id, $version = null){

        Cache::getInstance()->del($app_id.'_launcher_app_info');

        $app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app['alias']) || empty($app['current_version'])){
            throw new SmartLauncherAppsManagerException('Nothing to delete');
        }

        if ($version === null){
            $version = '';
        }

        $path = realpath(PROJECT_PATH.'/../../'
            .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
            .($app['type'] == 'plugin' ? 'plugins/' : '')
            .$app['alias']
            .'/'.$version
        );

        if (is_dir($path)){
            self::delTree($path);
        }

        if ($version && $version == $app['current_version']){
            Mysql::getInstance()->update('launcher_apps', array('current_version' => ''), array('id' => $app_id));
        }elseif (!$version){
            Mysql::getInstance()->delete('launcher_apps', array('id' => $app_id));
        }

        return true;
    }

    /**
     * @param int $app_id
     * @param string $version
     * @return array|bool
     */
    private function getAppLocalization($app_id, $version = null){

        $app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (!$version){
            $version = $app['current_version'];
        }

        if (empty($app) || empty($app['alias'])){
            return false;
        }

        $path = PROJECT_PATH.'/../../'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/').$app['alias'].'/'.$version.'/';

        $app_localizations = array();

        $config = json_decode($app['config'], true);

        $entry = isset($config['uris']['app']) ? $config['uris']['app'] : 'app/';

        if (!is_dir($path.'/'.$entry.'/lang/')){
            return false;
        }

        $scanned_directory = array_diff(scandir($path.'/'.$entry.'/lang/'), array('..', '.'));

        $languages = array_map(function($file){
            return str_replace('.json', '', $file);
        }, $scanned_directory);

        $languages = array_merge(array($this->lang, 'en'), array_diff($languages, array($this->lang, 'en')));

        foreach ($languages as $lang){
            if (is_readable($path.'/'.$entry.'/lang/'.$lang.'.json')){
                $localization = json_decode(file_get_contents($path.'/'.$entry.'/lang/'.$lang.'.json'), true);
                if (!empty($localization['data'][''])){
                    $localization = $localization['data'][''];

                    if (!empty($localization[$app['name']])){
                        $app_localizations[$lang]['name'] = $localization[$app['name']];
                    }

                    if (!empty($localization[$app['description']])){
                        $app_localizations[$lang]['description'] = $localization[$app['description']];
                    }
                }
            }
        }

        return $app_localizations;
    }

    public function addApplication($url, $autoinstall = false, $skip_info_check = false, $version = null, $fake_install = false){

        $app = Mysql::getInstance()->from('launcher_apps')->where(array('url' => $url))->get()->first();

        if (!empty($app)){
            return false;
        }

        $app_id = Mysql::getInstance()->insert('launcher_apps', array(
            'url'   => $url,
            'added' => 'NOW()'
        ))->insert_id();

        if ($autoinstall){
            $this->installApp($app_id, $version, $skip_info_check, $fake_install);
        }else{
            $this->getAppInfo($app_id, true);
        }

        return $app_id;
    }

    public function startAutoUpdate(){

        $need_to_update = Mysql::getInstance()->from('launcher_apps')->where(array('status' => 1, 'autoupdate' => 1))->get()->all();

        foreach ($need_to_update as $app){
            $this->updateApp($app['id']);
        }
    }

    public function updateApps(){

        $system_apps = $this->getSystemApps();
        $apps = $this->getInstalledApps('app');
        $themes = $this->getInstalledApps('theme');

        $installed_apps = array_merge($system_apps, $themes, $apps);

        $need_to_update = array_values(array_filter($installed_apps, function($app){
            return $app['current_version'] != $app['available_version'];
        }));

        foreach ($need_to_update as $app){
            $this->sendToCallback("Updating package ".$app['alias']."...");
            try{
                $this->updateApp($app['id']);
            } catch (SmartLauncherAppsManagerException $e){
                $this->sendToCallback("Error: " . $e->getMessage());
            }
        }
    }

    public function getFullAppDependencies($app_id){

        $app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)){
            return false;
        }

        $info = file_get_contents(realpath(PROJECT_PATH.'/../../'
            .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
            .($app['type'] == 'plugin' ? 'plugins/' : '')
            .$app['alias']
            .'/'.$app['current_version'].'/package.json'));

        if (!$info){
            return false;
        }

        $info = json_decode($info, true);

        if (!$info){
            return false;
        }

        $full_dependencies = array();

        $dependencies = isset($info['dependencies']) ? $info['dependencies'] : array();

        foreach ($dependencies as $package => $version_expression){

            $dep_app = Mysql::getInstance()->from('launcher_apps')->where(array('alias' => $package))->get()->first();

            if (empty($dep_app) || empty($dep_app['current_version'])){
                continue;
            }

            $range = new SemVerExpression($version_expression);

            if ($range->satisfiedBy(new SemVer($dep_app['current_version']))){
                //$full_dependencies[$package] = '../../../'.($dep_app['type'] == 'plugin' ? 'plugins/' : '').$package.'/'.$dep_app['current_version'].'/';
                $full_dependencies[$package] = $dep_app['current_version'];
            }elseif(!$dep_app['is_unique']){
                $dep_app_path = realpath(PROJECT_PATH.'/../../'
                    .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                    .($dep_app['type'] == 'plugin' ? 'plugins/' : '')
                    .$dep_app['alias']);

                if (!$dep_app_path){
                    throw new SmartLauncherAppsManagerException('Unable to find app path '.$dep_app['alias'].' path');
                }

                $files = array_diff(scandir($dep_app_path), array('.','..'));

                $max_version = null;

                foreach ($files as $file){
                    if (is_dir($dep_app_path.'/'.$file)){

                        $semver = new SemVer($file);

                        if ($range->satisfiedBy($semver)){
                            if (is_null($max_version)){
                                $max_version = $semver->getVersion();
                            } else if (SemVer::gt($semver->getVersion(), $max_version)){
                                $max_version = $semver->getVersion();
                            }
                        };
                    }
                }

                if (is_null($max_version)){
                    throw new SmartLauncherAppsManagerException('Unresolved dependency '.$dep_app['alias'].' for '.$app['alias']);
                }

                $full_dependencies[$package] = $max_version;
            }else{
                throw new SmartLauncherAppsManagerException('Unresolved dependency '.$dep_app['alias'].' for '.$app['alias']);
            }

        }

        return $full_dependencies;
    }

    /**
     * @param $app_id
     * @param null $version
     * @return array
     * @throws SmartLauncherAppsManagerException
     */
    public function getConflicts($app_id, $version = null) {

        $app = $original_app = Mysql::getInstance()->from('launcher_apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)) {
            throw new SmartLauncherAppsManagerException('App not found, id=' . $app_id);
        }

        $info = self::getNpmInfo($app, $version);

        if (empty($info)){
            throw new SmartLauncherAppsManagerException('Unable to get info for '.$app['url']);
        }

        $dependencies = isset($info['dependencies']) ? $info['dependencies'] : array();

        $conflicts = array();

        foreach ($dependencies as $package => $version_expression) {

            $dep_app = Mysql::getInstance()->from('launcher_apps')->where(array('alias' => $package))->get()->first();

            $range = new SemVerExpression($version_expression);

            if ($package == 'magcore-app-auth-stalker'){

                $sap_path = realpath(PROJECT_PATH.'/../deploy/src/sap/');
                $sap_versions = array_diff(scandir($sap_path), array('.','..'));

                if (empty($dep_app)){
                    $dep_app = array('id' => 0, 'url' => $package);
                }

                $dep_info = self::getNpmInfo($dep_app);

                if (isset($dep_info['config']['apiVersion']) && array_search($dep_info['config']['apiVersion'], $sap_versions) !== false){
                    $version_expression = $dep_info['config']['apiVersion'];
                    $dep_range = new SemVerExpression($version_expression);
                }else{
                    $dep_range = $range;
                }

                $suitable_sap = null;

                foreach ($sap_versions as $sap_version) {
                    if ($dep_range->satisfiedBy(new SemVer($sap_version))){
                        $suitable_sap = $sap_version;
                        break;
                    }
                }

                if (empty($suitable_sap)){
                    $conflicts[] = array(
                        'alias'           => $package,
                        'current_version' => $version_expression,
                        'target'          => $app['url']
                    );
                }

            } elseif ($package == 'magcore-theme'){

                $themes = Mysql::getInstance()->from('launcher_apps')->where(array('type' => 'theme', 'status' => 1))->get()->all();

                if (empty($dep_app) || empty($dep_app['current_version'])){
                    continue;
                }

                foreach ($themes as $theme){
                    $theme_info = self::getNpmInfo($theme);

                    $theme_dependencies = isset($theme_info['dependencies']) ? $theme_info['dependencies'] : array();

                    if (!isset($theme_dependencies['magcore-theme'])){
                        continue;
                    }

                    if (!$range->satisfiedBy(new SemVer($dep_app['current_version']))){
                        $conflicts[] = array(
                            'alias'           => $theme['url'].' - '.$package,
                            'current_version' => $dep_app['current_version'],
                            'target'          => $app['url']
                        );
                    }
                }
            }

            if (empty($dep_app) || empty($dep_app['current_version']) || !$dep_app['is_unique']) {
                continue;
            }

            if (!$range->satisfiedBy(new SemVer($dep_app['current_version']))){
                $conflicts[] = array(
                    'alias'           => $package,
                    'current_version' => $dep_app['current_version'],
                    'target'          => $app['url']
                );
            }
        }

        return $conflicts;
    }

    public function getInstalledApps($type = 'app'){

        $apps = Mysql::getInstance()->from('launcher_apps')->where(array('type' => $type))->get()->all();

        // apps order
        $metapackage_info = json_decode(file_get_contents(realpath(PROJECT_PATH.'/../../'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/').'/package.json')), true);

        $app_names = array_map(function ($app){
            return $app['alias'];
        }, $apps);

        if ($metapackage_info && isset($metapackage_info['order'])){
            $order = $metapackage_info['order'];

            $ordered_apps = array();

            foreach ($order as $alias){
                if (($idx = array_search($alias, $app_names)) !== false){
                    $ordered_apps[] = $apps[$idx];
                    array_splice($apps, $idx, 1);
                    array_splice($app_names, $idx, 1);
                }
            }

            $apps = array_merge($ordered_apps, array_values($apps));
        }

        return array_values(array_filter($apps, function($app){
            return !empty($app['alias']) && $app['status'] == 1 && is_dir(realpath(PROJECT_PATH.'/../../'
                .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                .($app['type'] == 'plugin' ? 'plugins/' : '')
                .$app['alias']
                .'/'.$app['current_version']));
        }));
    }

    public function getSystemApps(){

        $apps = Mysql::getInstance()->from('launcher_apps')->not_in('type', array('app', 'theme'))->get()->all();

        return array_values(array_filter($apps, function($app){
            return !empty($app['alias']) && $app['status'] == 1 && is_dir(realpath(PROJECT_PATH.'/../../'
                .Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/')
                .($app['type'] == 'plugin' ? 'plugins/' : '')
                .$app['alias']
                .'/'.$app['current_version']));
        }));
    }

    private static function delTree($dir) {
        if (!is_dir($dir)){
            return false;
        }
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function syncApps(){

        $repos = Config::getSafe('launcher_apps_extra_metapackages', array());

        $npm = new Npm();

        foreach ($repos as $repo){

            $info = $npm->info($repo);

            if (!$info){
                continue;
            }

            $apps = isset($info['dependencies']) ? $info['dependencies'] : array();

            if (is_string($apps)){
                $apps = array($apps);
            }

            foreach ($apps as $app => $ver){
                $this->addApplication($app);
            }
        }
    }

    public function initApps(){
        $apps = Mysql::getInstance()->from('launcher_apps')->count()->get()->counter();

        if ($apps == 0){
            return $this->resetApps();
        }

        return false;
    }

    public function resetApps($metapackage = null){

        $orig_metapackage = $metapackage;

        if (strpos($orig_metapackage, '@')){
            list($orig_metapackage_name, $ver) = explode('@', $orig_metapackage);
        }else{
            $orig_metapackage_name = $orig_metapackage;
        }

        if (is_null($metapackage)){
            $metapackage = Config::getSafe('launcher_apps_base_metapackage', 'stalker-apps-base');
        }

        if (empty($metapackage)){
            return false;
        }

        if (!strpos($metapackage, '@')){

            $stalker_version = file_get_contents('../../c/version.js');
            $start = strpos($stalker_version, "'")+1;
            $end = strrpos($stalker_version, "'");
            $stalker_version = substr($stalker_version, $start, $end-$start);

            $metapackage_name = $metapackage;
            $metapackage = $metapackage_name.'@'.$stalker_version;
        }else{
            list($metapackage_name, $stalker_version) = explode('@', $metapackage);
        }

        if ($stalker_version){
            $exploded_version = explode('-', $stalker_version);
            $stalker_version = $exploded_version[0];
        }

        $npm = Npm::getInstance();

        if (is_null($orig_metapackage)) {

            $info = $npm->info($metapackage_name);

            if (!$info) {
                return false;
            }

            if (isset($info['versions']) && is_string($info['versions'])){
                $info['versions'] = array($info['versions']);
            }

            $this_release_versions = array();

            if ($stalker_version && $info['versions']){
                foreach ($info['versions'] as $version){
                    if (strpos($version, $stalker_version.'-r') === 0){
                        $version_weight = (int) str_replace($stalker_version.'-r', '', $version);
                        $this_release_versions[$version_weight] = $version;
                    }elseif (strpos($version, $stalker_version) === 0){
                        $this_release_versions[0] = $version;
                    }
                }
            }

            if ($this_release_versions){
                $stalker_version = $this_release_versions[max(array_keys($this_release_versions))];
            }

            if (empty($this_release_versions)){
                throw new SmartLauncherAppsManagerException('A metapackage not found for release '.$stalker_version);
            }
        }

        $this->resetAppsCache();

        $this->sendToCallback("Removing apps...");

        Mysql::getInstance()->truncate('launcher_apps');

        $apps_path = realpath(PROJECT_PATH.'/../../'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/'));

        if ($apps_path){
            $ignore = array('.','..');
            if ($orig_metapackage){
                $ignore[] = $orig_metapackage_name;
            }
            $files = array_diff(scandir($apps_path), $ignore);
            foreach ($files as $file){
                $this->sendToCallback("Removing package ".$file."...");
                self::delTree($apps_path.'/'.$file);
            }

            if (is_file($apps_path.'/package.json')){
                unlink($apps_path.'/package.json');
            }
        }

        $this->sendToCallback("Installing metapackage ".$metapackage_name.(is_null($orig_metapackage) ? '@'.$stalker_version : '')."...");

        $result = $this->addApplication($metapackage_name, true, !is_null($orig_metapackage), is_null($orig_metapackage) ? $stalker_version : null);

        // save metapackage package.json
        copy($apps_path.'/'.$metapackage_name.'/'.(is_null($orig_metapackage) ? $stalker_version : '').'/package.json', $apps_path.'/package.json');

        Mysql::getInstance()->delete('launcher_apps', array('url' => $metapackage_name));

        $this->syncApps();

        return (bool) $result;
    }

    /**
     * @return string package.json content
     */
    public function getSnapshot(){

        $snapshot = array(
            'name' => 'mag-apps-snapshot',
            'version' => '0.0.1',
            'dependencies' => array()
        );

        $system_apps = $this->getSystemApps();
        $apps = $this->getInstalledApps('app');
        $themes = $this->getInstalledApps('theme');

        $dependencies = array_merge($system_apps, $themes, $apps);

        foreach ($dependencies as $dependency){
            $snapshot['dependencies'][$dependency['url']] = $dependency['current_version'];
        }

        return json_encode($snapshot, 192);
    }

    /**
     * @param string $json
     * @return bool
     * @throws SmartLauncherAppsManagerException
     */
    public function restoreFromSnapshot($json){

        $package = json_decode($json, true);

        if (!$package){
            throw new SmartLauncherAppsManagerException('Unable to decode JSON file');
        }

        if (empty($package['name']) || empty($package['version']) || empty($package['dependencies'])){
            throw new SmartLauncherAppsManagerException('Required fields in JSON file are missing.');
        }

        $apps_path = realpath(PROJECT_PATH.'/../../'.Config::getSafe('launcher_apps_path', 'stalker_launcher_apps/'));

        if (!$apps_path){
            throw new SmartLauncherAppsManagerException('Unable to get launcher apps path');
        }

        if (!is_dir($apps_path.'/'.$package['name'])) {

            umask(0);
            $mkdir = mkdir($apps_path.'/'.$package['name'], 0777);

            if (!$mkdir) {
                throw new SmartLauncherAppsManagerException('Unable to create metapackage folder');
            }
        }

        $file_result = file_put_contents($apps_path.'/'.$package['name'].'/package.json', $json);

        if (!$file_result){
            throw new SmartLauncherAppsManagerException('Unable to create package.json in metapackage folder');
        }

        return $this->resetApps($package['name']);
    }

    public static function getNpmInfo($app, $version = null){

        $cache = Cache::getInstance();

        $key = $version ? $app['id'].'_'.$version.'_launcher_app_info' : $app['id'].'_launcher_app_info';

        $cached_info = $cache->get($key);

        if (empty($cached_info)){
            $npm = Npm::getInstance();
            $info = $npm->info($app['url'], $version);
        }else{
            $info = $cached_info;
        }

        if (empty($info)){
            return null;
        }

        if (empty($cached_info)){
            $cache->set($key, $info, 0, 0);
        }
        
        return $info;
    }

    public function resetAppsCache(){

        $cache = Cache::getInstance();

        $apps = Mysql::getInstance()->from('launcher_apps')->get()->all();

        foreach ($apps as $app){

            $this->sendToCallback("Cleaning cache ".$app['url']."...");

            $info = self::getNpmInfo($app);

            if (isset($info['versions'])){

                if (!is_array($info['versions'])){
                    $info['versions'] = array($info['versions']);
                }

                foreach ($info['versions'] as $version){
                    $cache->del($app['id'].'_'.$version.'_launcher_app_info');
                }
            }

            $cache->del($app['id'].'_launcher_app_info');
        }

    }
}

class SmartLauncherAppsManagerException extends Exception{}

class SmartLauncherAppsManagerConflictException extends SmartLauncherAppsManagerException{
    protected $conflicts;

    /**
     * SmartLauncherAppsManagerException constructor.
     * @param string $message
     * @param array $conflicts
     */
    public function __construct($message, $conflicts) {
        parent::__construct($message);
        $this->conflicts = $conflicts;
    }

    /**
     * @return array
     */
    public function getConflicts(){
        return $this->conflicts;
    }
}