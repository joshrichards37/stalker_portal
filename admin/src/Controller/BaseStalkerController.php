<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Stalker\Lib\Core\Config;

class BaseStalkerController {

    protected $app;
    protected $request;
    protected $baseDir;
    protected $baseHost;
    protected $workHost;
    protected $relativePath;
    protected $workURL;
    protected $refferer;
    protected $Uri;
    protected $method;
    protected $isAjax;
    protected $data;
    protected $postData;
    protected $db;
    protected $admin;
    protected $session;
    protected $access_level = 0;
    protected $access_levels = array(
        0 => 'denied',
        1 => 'view',
        2 => 'edit',
        3 => 'edit',
        4 => 'action',
        5 => 'all',
        6 => 'all',
        7 => 'all',
        8 => 'all',
    );
    protected $sidebar_cache_time;
    protected $language_codes_en = array();
    protected $redirect = FALSE;

    public function __construct(Application $app, $modelName = '') {

        $this->app = $app;
        $this->request = $app['request_stack']->getCurrentRequest();

        if (session_id()) {
            session_write_close();
            $this->app['session']->save();
        }
        if (!$this->app['session']->isStarted() ) {
            $this->app['session']->start();
        }
        $this->admin = \Admin::getInstance();
        \Admin::checkLanguage($app['language']);

        $user_info = array(
            'id'                => \Admin::getInstance()->getId(),
            'login'             => \Admin::getInstance()->getLogin(),
            'reseller_id'       => \Admin::getInstance()->getResellerID(),
            'access_level'      => 0,
            'group_id'          => \Admin::getInstance()->getGID(),
            'group_name'        => '',
            'language'          => \Admin::getInstance()->getAdminLanguage(),
            'theme'             => \Admin::getInstance()->getTheme(),
            'task_count'        => 0,
            'notification_count'=> 0
        );
        $twig_theme = $this->admin->getTheme();

        if(!empty($this->app["themes"]) && array_key_exists($twig_theme, $this->app["themes"]) && is_dir($this->app["themes"][$twig_theme])){
            $twig_theme = $this->app["themes"][$twig_theme];
        } else {
            $twig_theme = 'default';
        }

        $this->app['twig_theme'] = $twig_theme;

        $this->app['userlogin'] = $this->admin->getLogin();

        $this->baseDir = rtrim(str_replace(array("src", "Controller"), '', __DIR__), '//');
        $this->getPathInfo();
        $this->setRequestMethod();
        $this->setAjaxFlag();
        $this->getData();

        $modelName = "Model\\" . (empty($modelName) ? 'BaseStalker' : str_replace(array("\\", "Controller"), '', $modelName)) . 'Model';
        $this->db = FALSE;
        $modelName = (class_exists($modelName) ? $modelName : 'Model\BaseStalkerModel');
        if (class_exists($modelName)) {
            $this->db = new $modelName;
            if (!($this->db instanceof $modelName)) {
                $this->db = FALSE;
            }
        }

        $this->checkLastLocation();
        $uid = $this->admin->getId();

        $this->app['user_id'] = $uid;

        $this->app['reseller'] = $this->admin->getResellerID();
        $this->db->setReseller($this->app['reseller']);
        $this->db->setAdmin($this->app['user_id'], $this->app['userlogin']);
        $this->saveFiles = $app['saveFiles'];

        if ($this->app['userlogin'] == 'admin') {
            $this->setControllerAccessMap();
            $this->access_level = 8;
        } else {
            $this->setAccessLevel();
        }

        $user_info['access_level'] = $this->access_level;
        $user_info['task_count'] = !empty($user_info['id']) ? $this->db->getCountUnreadedMsgsByUid($user_info['id']): 0;
        $this->sidebar_cache_time = Config::getSafe('admin_panel_sidebar_cache_time', 0);
        $this->app['certificate_server_health_check'] = Config::getSafe('certificate_server_health_check', TRUE);
        $this->setDataTablePluginSettings();
        if (!$this->isAjax) {

            $update = $this->db->getAllFromTable('updates', 'id');
            if (!empty($update)) {
                $this->app['new_version'] = end($update);
            } else {
                $this->app['new_version'] = FALSE;
            }
            if (is_file($this->baseDir . '/../c/version.js')) {
                $tmp = file_get_contents($this->baseDir . '/../c/version.js');
                if (preg_match('/[\d\.]+/i', $tmp, $ver) && !empty($ver)) {
                    $this->app['current_version'] = $ver[0];
                } else {
                    $this->app['current_version'] = FALSE;
                }
            }
            $this->setSideBarMenu();
            if ($this->app['userlogin'] == 'admin') {

                $feed = new \NotificationFeed();
                $user_info['notification_count'] = $feed->getCount();
            }
            $this->setBreadcrumbs();
            $this->app['session']->set('cached_lang', $this->app['language']);
        }

        if (isset($this->data['set-dropdown-attribute'])) {
            $this->set_dropdown_attribute();
            exit;
        }
        $this->app['user_info'] = $user_info;

        if (exec('npm -v') !== '2.15.11') {
            $this->app['npmVersionError'] = 1;
        }

        $this->app['COOKIE'] = $this->request->cookies;

    }

    protected function getTemplateName($method_name, $extend = '') {
        $method_name = explode('::', str_replace(array(__NAMESPACE__, '\\'), '', $method_name));
        $method_name[] = end($method_name);
        return $this->app['twig_theme'] . '/' . implode('/', $method_name) . $extend . ".twig";
    }

    private function getPathInfo() {
        $tmp = explode('/', trim($this->request->getPathInfo(), '/'));
        $this->app['controller_alias'] = $tmp[0];
        $this->app['action_alias'] = (count($tmp) == 2) ? $tmp[1] : '';
        $getenv = getenv('STALKER_ENV');
        $this->app['stalker_env'] = ($getenv && $getenv == 'develop') ? 'dev': 'min';

        $ext_path = (!empty($tmp[0]) ? implode('', array_map('ucfirst', explode('-', $tmp[0]))): 'Index') . '/' . (!empty($tmp[1]) ? str_replace('-', '_', $tmp[1]): 'index') . '/';
        $this->app['assetic_ext_min_name'] = (!empty($tmp[0]) ? str_replace('-', '_', $tmp[0]): 'index') . '_' . (!empty($tmp[1]) ? str_replace('-', '_', $tmp[1]): 'index');

        $this->app['assetic_path_to_source'] = $this->baseDir . '/../server/adm/';

        $this->baseHost = $this->request->getSchemeAndHttpHost();
        $this->workHost = $this->baseHost . Config::getSafe('portal_url', '/stalker_portal/');
        $this->app['relativePath'] = $this->relativePath = Config::getSafe('portal_url', '/stalker_portal/');
        $this->app['workHost'] = $this->workHost;
        $this->Uri = $this->app['request_stack']->getCurrentRequest()->getUri();
        $controller = (!empty($this->app['controller_alias']) ? "/" . $this->app['controller_alias'] : '');
        $action = (!empty($this->app['action_alias']) ? "/" . $this->app['action_alias'] : '');
        $workUrl = explode("?", str_replace(array($action, $controller), '', $this->Uri));
        $this->workURL = $workUrl[0];

        $this->app['breadcrumbs']->addItem('Ministra', $this->workURL);
        $this->refferer = $this->request->server->get('HTTP_REFERER');

        if ($this->app['stalker_env'] == 'min') {
            $this->app['assetic_base_web_path'] = $this->workURL . '/min/';
            $this->app['assetic_base_js_path'] =  $this->app['twig_theme'] . '/js/';
            $this->app['assetic_base_css_path'] = $this->app['twig_theme'] . '/css/';
            $this->app['assetic_ext_web_path'] = $ext_path;
        } else {
            $this->app['assetic_base_web_path'] = rtrim($this->workURL, '/') . '/';
            $this->app['assetic_base_js_path'] =  'js/dev/';
            $this->app['assetic_base_css_path'] = 'css/dev/';
            $this->app['assetic_ext_web_path'] = $ext_path;
        }
    }

    private function setSideBarMenu() {
        if (!$this->checkCachedMenu('side_bar') || empty($this->app['side_bar'])) {
            $side_bar = json_decode(str_replace(array("_(", ")"), '', file_get_contents($this->baseDir . '/json_menu/menu.json')), TRUE);
            $this->setControllerAccessMap();
            $this->cleanSideBar($side_bar);
            $this->app['side_bar'] = $side_bar;
            $this->setCachedMenu('side_bar');
        }
    }

    private function setTopBarMenu() {
        if ($this->checkCachedMenu('top_bar') === FALSE || empty($this->app['top_bar'])) {
            $top_bar = json_decode(str_replace(array("_(", ")"), '', file_get_contents($this->baseDir . '/json_menu/top_menu.json')), TRUE);
            if (!empty($this->app['userlogin'])) {
                $top_bar[1]['add_params'] = '<span class="hidden-xs">"' . $this->app['userlogin'] . '"</span>';
                if (!empty($this->app['userTaskMsgs'])) {
                    $top_bar[1]['action'][1]['add_params'] = '<span class="hidden-xs badge">' . $this->app['userTaskMsgs'] . '</span>';
                }
            }

            $this->setControllerAccessMap();
            $this->cleanSideBar($top_bar);
            $this->app['top_bar'] = $top_bar;
            $this->setCachedMenu('top_bar');
        }
    }

    private function setRequestMethod() {
        $this->method = $this->request->getMethod();
    }

    private function setAjaxFlag() {
        $this->isAjax = $this->request->isXmlHttpRequest();
    }

    private function getData() {
        $this->data = $this->request->query->all();
        $this->postData = $this->request->request->all();

        if (!empty($this->postData['group_key']) &&
            is_string($this->postData[$this->postData['group_key']]) &&
            ($parsed_json = json_decode($this->postData[$this->postData['group_key']], TRUE)) &&
            json_last_error() == JSON_ERROR_NONE) {
            $this->postData[$this->postData['group_key']] = $parsed_json;
        }

    }

    public function setLocalization($source = array(), $fieldname = '', $number = FALSE, $params = array()) {
        if (!empty($source)) {
            if (!is_array($source)) {
                $translate = '';
                if ($number === FALSE) {
                    $translate = $this->app['translator']->trans($source, $params);
                } else {
                    $translate = $this->app['translator']->transChoice($source, $number, $params);
                }
                return (!empty($translate) ? $translate : $source);
            } elseif (array_key_exists($fieldname, $source)) {
                $source[$fieldname] = $this->setLocalization((string)$source[$fieldname], $fieldname, $number, $params);
            } else {
                while (list($key, $row) = each($source)) {
                    $source[$key] = $this->setLocalization($row, $fieldname, $number, $params);
                }
            }
        }
        return $source;
    }

    public function getFieldFromArray($array, $field) {
        $return_array = array();
        if (is_array($array) && !empty($array)) {
            $tmp = array_values($array);
            if (!empty($tmp) && is_array($tmp[0]) && array_key_exists($field, $tmp[0])) {
                foreach ($array as $key => $value) {
                    $return_array[] = $value[$field];
                }
            }
        }
        return $return_array;
    }

    public function generateAjaxResponse($data = array(), $error = '') {
        $response = array();

        if (!empty($this->postData['for_validator'])) {
            $error = trim($error);
            $response['valid'] = empty($error) && !empty($data);
            $response['message'] = array_key_exists('chk_rezult', $data) ? trim($data['chk_rezult']) : $error;
        } else {
            if (empty($error) && !empty($data)) {
                $response['success'] = TRUE;
                $response['error'] = FALSE;
            } else {
                $response['success'] = FALSE;
                $response['error'] = $error;
            }

            $response = array_merge($response, $data);
        }

        return $response;
    }

    protected function checkAuth() {
        if (empty($this->app['controller_alias']) || ($this->app['controller_alias'] != 'register' && $this->app['controller_alias'] != 'login')) {
            if (!$this->admin->isAuthorized()) {
                if ($this->isAjax) {
                    $response = $this->generateAjaxResponse(array(), $this->setLocalization('Need authorization'));
                    return new Response(json_encode($response), 401);
                } else {
                    return $this->app->redirect(trim($this->workURL, '/') . '/login', 302);
                }
            }

            $parent_access = $this->getParentActionAccess();

            if(
                $this->access_level < 1 ||
                (!empty($this->postData) && !$this->isAjax && $this->access_level < 2) ||
                (!empty($this->postData) && $this->isAjax && $this->access_level < 4 && $parent_access === FALSE) ||
                ($parent_access !== FALSE && !$parent_access)
            ) {
                if ($this->isAjax) {
                    $response = $this->generateAjaxResponse(array('msg' => $this->setLocalization('Access denied')), 'Access denied');
                    return new Response(json_encode($response), 403, array('Content-Type' => 'application/json; charset=UTF-8'));
                } else {
                    return $this->app['twig']->render($this->getTemplateName("AccessDenied::index"));
                }
            }
        }
    }

    protected function getCoverFolder($id) {

        $dir_name = ceil($id / 100);
        $dir_path = realpath(PROJECT_PATH . '/../' . Config::getSafe('screenshots_path', 'screenshots/')) . '/' . $dir_name;
        if (!is_dir($dir_path)) {
            umask(0);
            if (!mkdir($dir_path, 0777)) {
                return -1;
            } else {
                return $dir_path;
            }
        } else {
            return $dir_path;
        }
    }

    protected function transliterate($st) {

        $st = trim($st);
        $replace = array('а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ж' => 'g', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
            'т' => 't', 'у' => 'u', 'ф' => 'f', 'ы' => 'i', 'э' => 'e', 'А' => 'A',
            'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ж' => 'G',
            'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Ы' => 'I', 'Э' => 'E', 'ё' => "yo", 'х' => "h",
            'ц' => "ts", 'ч' => "ch", 'ш' => "sh", 'щ' => "shch", 'ъ' => '', 'ь' => '',
            'ю' => "yu", 'я' => "ya", 'Ё' => "Yo", 'Х' => "H", 'Ц' => "Ts", 'Ч' => "Ch",
            'Ш' => "Sh", 'Щ' => "Shch", 'Ъ' => '', 'Ь' => '', 'Ю' => "Yu", 'Я' => "Ya",
            ' ' => "_", '!' => "", '?' => "", ',' => "", '.' => "", '"' => "", '\'' => "",
            '\\' => "", '/' => "", ';' => "", ':' => "", '«' => "", '»' => "", '`' => "",
            '-' => "-", '—' => "-"
        );
        $st = strtr($st, $replace);

        $st = preg_replace("/[^a-z0-9_-]/i", "", $st);

        return $st;
    }

    protected function prepareDataTableParams($params = array(), $drop_columns = array()) {
        $query_param = array(
            'select' => array(),
            'like' => array(),
            'order' => array(),
            'limit' => array('offset' => 0, 'limit' => FALSE)
        );
        if (empty($params) || !is_array($params) || !array_key_exists('columns', $params)) {
            return $query_param;
        }

        if (array_key_exists('length', $params)) {
            $query_param['limit']['limit'] = $params['length'];
        } else {
            $query_param['limit']['limit'] = FALSE;
        }

        if (array_key_exists('start', $params)) {
            $query_param['limit']['offset'] = $params['start'];
        } else {
            $query_param['limit']['offset'] = NULL;
        }

        if (!empty($params['order'])) {
            foreach ($params['order'] as $val) {
                $column = $params['columns'][(int)$val['column']];

                $direct = $val['dir'];
                $col_name = !empty($column['name']) ? $column['name'] : (!empty($column['data']) ? $column['data'] : FALSE);

                if ($col_name === FALSE || in_array($col_name, $drop_columns)) {
                    continue;
                }
                if ($column['orderable']) {
                    $query_param['order'][$col_name] = $direct;
                }
            }
        }

        if (!empty($params['columns'])) {
            foreach ($params['columns'] as $key => $column) {
                $col_name = !empty($column['name']) ? $column['name'] : (!empty($column['data']) ? $column['data'] : FALSE);
                if ($col_name === FALSE || in_array($col_name, $drop_columns)) {
                    continue;
                }
                $query_param['select'][] = $col_name;
                if (!array_key_exists('visible', $column) || $column['visible'] != 'false') {
                    settype($params['search']['value'], 'string');
                    if (!empty($column['searchable']) && $column['searchable'] == 'true' && (!empty($params['search']['value']) || $params['search']['value'] === '0') && $params['search']['value'] != "false") {
                        $query_param['like'][$col_name] = "%" . addslashes($params['search']['value']) . "%";
                    }
                }
            }
        }

        return $query_param;
    }

    protected function cleanQueryParams(&$data, $filds_for_delete = array(), $fields_for_replace = array(), $order_no_replace = FALSE) {
        reset($data);
        while (list($key, $block) = each($data)) {
            if ($order_no_replace !== FALSE && $key == 'order') {
                continue;
            }
            foreach ($filds_for_delete as $field) {
                if (array_key_exists($field, $block)) {
                    $new_name = str_replace(" as `$field`", '', $fields_for_replace[$field]);
                    if (array_key_exists($field, $fields_for_replace) && !is_numeric($new_name)) {
                        $data[$key][$new_name] = $data[$key][$field];
                    }
                    unset($data[$key][$field]);
                } elseif (($search = array_search($field, $block)) !== FALSE && array_search($fields_for_replace[$field], $block) === FALSE) {
                    if (array_key_exists($field, $fields_for_replace)) {
                        $data[$key][] = $fields_for_replace[$field];
                    }
                    unset($data[$key][$search]);
                }
            }
        }
    }

    protected function orderByDeletedParams(&$data, $param) {
        foreach ($param as $field => $direct) {
            $direct = strtoupper($direct) == 'ASC' ? 1 : -1;
            usort($data, function ($a, $b) use ($field, $direct) {
                return (($a[$field] >= $b[$field]) ? -1 : 1) * $direct;
            });
        }
    }

    protected function checkDisallowFields(&$data, $fields = array()) {
        $return = array();
        while (list($key, $block) = each($data)) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $block)) {
                    $return[$key][$field] = $block[$field];
                    unset($data[$key][$field]);
                } elseif (($search = array_search($field, $block)) !== FALSE) {
                    $return[$key][$field] = $block[$search];
                    unset($data[$key][$search]);
                }
            }
        }
        return $return;
    }

    private function setAccessLevel() {
        $this->setControllerAccessMap();
        $controller_alias = !empty($this->app['controller_alias']) ? $this->app['controller_alias'] : 'index';
        if (array_key_exists($controller_alias, $this->app['controllerAccessMap']) && $this->app['controllerAccessMap'][$controller_alias]['access']) {
            if ($this->app['action_alias'] == '' || $this->app['action_alias'] == 'index') {
                $this->access_level = $this->app['controllerAccessMap'][$controller_alias]['access'];
                return;
            } elseif (array_key_exists($this->app['action_alias'], $this->app['controllerAccessMap'][$controller_alias]['action'])) {
                $parent_access = $this->getParentActionAccess();
                $this->access_level = ($parent_access !== FALSE) ? $parent_access : $this->app['controllerAccessMap'][$controller_alias]['action'][$this->app['action_alias']]['access'];
                return;
            }
        }
        $this->access_level = 0;
    }

    private function setControllerAccessMap() {
        if (empty($this->app['controllerAccessMap'])) {
            $is_admin = (!empty($this->app['userlogin']) && $this->app['userlogin'] == 'admin');
            $gid = ($is_admin) ? '' : $this->admin->getGID();
            $map = array();
            $tmp_map = $this->db->getControllerAccess($gid, $this->app['reseller']);
            foreach ($tmp_map as $row) {
                if (!array_key_exists($row['controller_name'], $map)) {
                    $map[$row['controller_name']]['access'] = (!$is_admin) ? $this->getDecFromBin($row) : '8';
                    if ($map[$row['controller_name']]['access'] == 0) {
                        continue;
                    }
                    $map[$row['controller_name']]['action'] = array();
                }
                if ((!empty($row['action_name']) && $row['action_name'] != 'index') || $row['controller_name'] != 'index') {
                    $map[$row['controller_name']]['action'][$row['action_name']]['access'] = (!$is_admin) ? $this->getDecFromBin($row) : '8';
                }
            }
            $this->app['controllerAccessMap'] = $map;
        }
    }

    private function getDecFromBin($row) {
        return bindec($row['action_access'] . $row['edit_access'] . $row['view_access']);
    }

    private function cleanSideBar(&$side_bar) {
        $this->setControllerAccessMap();
        $dont_remove = (!empty($this->app['userlogin']) && $this->app['userlogin'] == 'admin');
        while (list($key, $row) = each($side_bar)) {
            $controller = str_replace('_', '-', $row['alias']);
            $side_bar[$key]['name'] = $this->setLocalization($row['name']);

            if ((!$dont_remove && !array_key_exists($controller, $this->app['controllerAccessMap'])) ||
                (array_key_exists($controller, $this->app['controllerAccessMap']) && $this->app['controllerAccessMap'][$controller]['access'] == 0)) {
                unset($side_bar[$key]);
                continue;
            }
            while (list($key_a, $row_a) = each($row['action'])) {
                $side_bar[$key]['action'][$key_a]['name'] = $this->setLocalization($row_a['name']);
                $action = str_replace('_', '-', $row_a['alias']);
                if ((!$dont_remove && !array_key_exists($action, $this->app['controllerAccessMap'][$controller]['action'])) ||
                    (array_key_exists($action, $this->app['controllerAccessMap'][$controller]['action']) && $this->app['controllerAccessMap'][$controller]['action'][$action]['access'] == 0)) {
                    unset($side_bar[$key]['action'][$key_a]);
                }
            }
        }

    }

    protected function setBreadcrumbs(){
        if (empty($this->app['side_bar'])) {
            $this->setSideBarMenu();
        }
        $side_bar = $this->app['side_bar'];
        while (list($key, $row) = each($side_bar)) {
            $controller = str_replace('_', '-', $row['alias']);
            if ($this->app['controller_alias'] == $controller) {
                $this->app['breadcrumbs']->addItem($row['name'], $this->workURL . "/$controller");

                while (list($key_a, $row_a) = each($row['action'])) {
                    $action = str_replace('_', '-', $row_a['alias']);
                    if ($this->app['controller_alias'] == $controller && $this->app['action_alias'] == $action) {
                        $this->app['breadcrumbs']->addItem($row_a['name'], $this->workURL . "/$controller/$action");
                        break;
                    }
                }
                break;
            }
        }
    }

    protected function infliction_array($dest = array(), $source = array()) {
        if (is_array($dest)) {
            while (list($d_key, $d_row) = each($dest)) {
                if (is_array($source)) {
                    if (array_key_exists($d_key, $source)) {
                        $dest[$d_key] = $this->infliction_array($d_row, $source[$d_key]);
                    } else {
                        continue;
                    }
                } else {
                    return $dest;
                }
            }
        } elseif (!is_array($source)) {
            return $source;
        }
        return $dest;
    }

    protected function checkDropdownAttribute(&$attribute, $filters = '') {

        $param = array();
        $param['admin_id'] = $this->admin->getId();
        $param['controller_name'] = $this->app['controller_alias'];
        $param['action_name'] = (empty($this->app['action_alias']) ? 'index' : $this->app['action_alias']) . $filters;

        $attribute['all'] = array(
            'name' => 'all',
            'title' => $this->setLocalization('All'),
            'checked' => (bool)array_sum($this->getFieldFromArray($attribute, 'checked'))
        );
        $base_attribute = $this->db->getDropdownAttribute($param);
        if (empty($base_attribute)) {
            return $attribute;
        }

        $dropdown_attributes = unserialize($base_attribute['dropdown_attributes']);

        foreach ($dropdown_attributes as $key => $value){
            reset($attribute);
            while (list($num, $row) = each($attribute)) {
                if ($row['name'] === $key && $num !== 'all' ) {
                    $attribute[$num]['checked'] = ($value == 'true');
                    $attribute['all']['checked'] = $attribute['all']['checked'] && $attribute[$num]['checked'];
                    break;
                }
            }
        }
    }

    protected function setDataTablePluginSettings() {
        $this->app['datatable_lang_file'] = "./plugins/datatables/lang/" . str_replace('utf8', 'json', $this->app['used_locale']);
    }

    protected function getParentActionAccess() {
        $return = FALSE;
        if ($this->app['userlogin'] !== 'admin' && $this->isAjax && preg_match("/-json$/", $this->app['action_alias'])) {
            $action_alias = preg_replace(array('/-composition/i', '/-datatable\d/i', '/-version/'), '', $this->app['action_alias'], 1);
            $parent_1 = str_replace('-json', '', $action_alias);
            $parent_2 = str_replace('-list-json', '', $action_alias);
            $parent_access = 0;
            if ($parent_1 == $this->app['controller_alias'] || $parent_2 == $this->app['controller_alias']) {
                $parent_access = $this->app['controllerAccessMap'][$this->app['controller_alias']]['access'];
            } elseif (array_key_exists($parent_1, $this->app['controllerAccessMap'][$this->app['controller_alias']]['action'])) {
                $parent_access = $this->app['controllerAccessMap'][$this->app['controller_alias']]['action'][$parent_1]['access'];
            } elseif (array_key_exists($parent_2, $this->app['controllerAccessMap'][$this->app['controller_alias']]['action'])) {
                $parent_access = $this->app['controllerAccessMap'][$this->app['controller_alias']]['action'][$parent_2]['access'];
            } elseif (array_key_exists($action_alias, $this->app['controllerAccessMap'][$this->app['controller_alias']]['action'])) {
                $parent_access = $this->app['controllerAccessMap'][$this->app['controller_alias']]['action'][$action_alias]['access'];
            }
            $return = (int)($parent_access > 0);
        }
        return $return;
    }

    protected function mb_ucfirst($str) {
        $fc = mb_strtoupper(mb_substr($str, 0, 1, 'UTF-8'), 'UTF-8');
        return $fc . mb_substr($str, 1, mb_strlen($str), 'UTF-8');
    }

    protected function getUCArray($array = array(), $field = '') {
        reset($array);
        while (list($key, $row) = each($array)) {
            if (!empty($field)) {
                $row[$field] = $this->mb_ucfirst($row[$field]);
            } else {
                $row = $this->mb_ucfirst($row);
            }
            $array[$key] = $row;
        }
        return $array;
    }

    protected function getLanguageCodesEN($code = FALSE) {
        if (empty($this->language_codes_en)) {
            $this->language_codes_en = $this->db->getAllFromTable('languages', 'name');

            $this->language_codes_en = $this->setLocalization(array_combine($this->getFieldFromArray($this->language_codes_en, 'iso_639_code'), $this->getFieldFromArray($this->language_codes_en, 'name')));
        }

        return ($code !== FALSE) ? (is_array($this->language_codes_en) && array_key_exists($code, $this->language_codes_en) ? $this->language_codes_en[$code]: '') : $this->language_codes_en;
    }

    private function checkCachedMenu($menu_name) {
        $cached_lang = $this->app['session']->get('cached_lang', '');
        return !$this->isCacheTimeOut($menu_name) && $cached_lang == $this->app['language'] ? $this->getCachedMenu($menu_name) : FALSE;
    }

    private function setCachedMenu($menu_name) {
        if (is_string($menu_name) && !empty($menu_name)) {
            $is_cached_field = $menu_name . '_last_cached';
            $dir = $this->baseDir . '/resources/cache/sidebar';
            $cached_file_name = $this->app->offsetExists($is_cached_field) ? $dir . '/' . $this->app[$is_cached_field] . '_' . $this->app['user_id'] . '.' . $menu_name: '';
            if (is_dir($dir)) {
                if (!empty($cached_file_name) && is_file($cached_file_name)) {
                    @unlink($cached_file_name);
                }
                $tmp = $this->app[$is_cached_field];
                $this->app[$is_cached_field] = time();
                $this->app['session']->set($is_cached_field, $this->app[$is_cached_field]);
                file_put_contents($dir . '/' . $this->app[$is_cached_field] . '_' . $this->app['user_id'] . '.' . $menu_name, serialize($this->app[$menu_name]));
            }
        }
    }

    private function isCacheTimeOut($field_name) {
        $is_cached_field = $field_name . '_last_cached';
        $this->app[$is_cached_field] = $this->app['session']->get($is_cached_field, 0);
        return empty($this->app[$is_cached_field]) || (time() - ((int)$this->app[$is_cached_field] + $this->sidebar_cache_time)) > 0;
    }

    private function getCachedMenu($menu_name) {
        if (is_string($menu_name) && !empty($menu_name)) {
            $is_cached_field = $menu_name . '_last_cached';
            $dir = $this->baseDir . '/resources/cache/sidebar';
            $cached_file_name = $dir . '/' . $this->app[$is_cached_field] . '_' . $this->app['user_id'] . '.' . $menu_name;
            if (is_dir($dir) && is_file($cached_file_name)) {
                $this->app[$menu_name] = unserialize(file_get_contents($cached_file_name));
                return !empty($this->app[$menu_name]);
            }
        }
        return FALSE;
    }

    public function checkLastLocation(){
        if (empty($this->app['userlogin'])) {
            \Admin::getInstance()->isAuthorized();
            $this->app['userlogin'] = \Admin::getInstance()->isAuthorized() ? \Admin::getInstance()->getLogin(): NULL;
        }
        if (!$this->isAjax) {
            if ($this->app['controller_alias'] != 'login' && $this->app['controller_alias'] != 'logout' && $this->app['action_alias'] != 'auth-user-logout') {
                if (!empty($this->app['userlogin'])) {
                    $location_path = $this->app['controller_alias'];
                    if (!empty($this->app['action_alias'])){
                        $location_path .= ('/' . $this->app['action_alias']);
                    }
                    if (!empty($this->data)) {
                        $location_path .= '?' . urldecode($this->request->getQueryString());
                    }

                    $last_location = $this->request->cookies->get("last_location");
                    if (($parsed_json = json_decode($last_location, TRUE)) && $parsed_json && json_last_error() == JSON_ERROR_NONE) {
                        $last_location_array = $parsed_json;
                    } else {
                        $last_location_array = array();
                    }

                    $last_location_array[md5($this->app['userlogin'])] = trim($this->workURL, "/") . "/$location_path";
                    $cookie_all = $this->request->cookies->all();
                    $cookie_all['last_location'] = $last_location_array;

                    while (count($last_location_array) != 0 && strlen(json_encode($cookie_all)) > 4000) {
                        array_shift($last_location_array);
                        $cookie_all['last_location'] = $last_location_array;
                    }

                    setcookie('last_location', '', time() - 3600);
                    setcookie('last_location', json_encode($last_location_array), time()+60*60*24, "/");
                }
            } else {
                $refferer = explode('/', $this->refferer);
                $refferer = end($refferer);
                if ($refferer == 'login' && !empty($this->app['userlogin'])) {
                    $last_location = $this->request->cookies->get("last_location");
                    if (($parsed_json = json_decode($last_location, TRUE)) && $parsed_json && json_last_error() == JSON_ERROR_NONE) {
                        $last_location_array = $parsed_json;
                    } else {
                        $last_location_array = array();
                    }

                    if (array_key_exists(md5($this->app['userlogin']), $last_location_array)) {
                        $this->redirect = $last_location_array[md5($this->app['userlogin'])];
                    }
                }
            }
        }
        return FALSE;
    }

    public function groupPostAction($method, $post_key){

        if (method_exists($this, $method)) {
            $parsed_json = FALSE;
            $data = array('group' => array());
            $error = FALSE;
            $group_action = FALSE;
            if (!empty($this->postData['group_action'])) {
                $group_action =  $this->postData['group_action'];
            }

            if (!empty($this->postData[$post_key]) && is_string($this->postData[$post_key]) && ($parsed_json = json_decode($this->postData[$post_key], TRUE)) && json_last_error() == JSON_ERROR_NONE) {
                if (is_array($parsed_json)) {
                    reset($parsed_json);
                }

                while(list($num, $postdata) = each($parsed_json)){
                    $this->postData[$post_key] = $postdata;
                    $data['group'][$num] = $this->{$method}();
                    $error = ($error || !empty($data['group'][$num]['error']));
                }
            }

            /*$data['group'] = call_user_func_array('array_merge_recursive', array_values($data['group']));

            while (list($key, $row_data) = each($data['group'])){
                if (is_array($row_data)) {
                    $data['group'][$key] = array_unique($row_data);
                }
            }*/

            $response = $this->generateAjaxResponse($data, $error);

            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        }
        $this->app->abort( 501, $this->setLocalization('Unrecognized group operation'));
    }

    public function groupMessageList($id, $result, $msg_tmpl){

        if ($result !== 0) {
            if (is_numeric($result)) {
                return array(
                    'status' => $msg_tmpl['success']['status'],
                    'msg' => $this->setLocalization($msg_tmpl['success']['msg'], '', $id, array('{updid}' => $id))
                );
            } elseif(array_key_exists('error', $msg_tmpl)) {
                return array(
                    'status' => $msg_tmpl['error']['status'],
                    'msg' => $this->setLocalization($msg_tmpl['error']['msg'], '', $id, array('{updid}' => $id))
                );
            } else {
                return $this->groupMessageList($id, 0, $msg_tmpl);
            }
        } else {
            return array(
                'status' => $msg_tmpl['failed']['status'],
                'msg' => $this->setLocalization($msg_tmpl['failed']['msg'], '', $id, array('{updid}' => $id))
            );
        }
    }

    public function setSQLDebug($flag = 0){
        if ($this->db) {
            $this->db->setSQLDebug($flag);
        }
    }
}
