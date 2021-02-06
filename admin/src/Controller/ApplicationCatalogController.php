<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\HttpFoundation\StreamedResponse as StreamedResponse;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;

class ApplicationCatalogController extends \Controller\BaseStalkerController {

    public function __construct(Application $app) {
        parent::__construct($app, __CLASS__);
    }

    // ------------------- action method ---------------------------------------

    public function index() {

        if (empty($this->app['action_alias'])) {
            return $this->app->redirect($this->app['controller_alias'] . '/application-list');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function application_list() {

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $tos = $this->db->getTOS('stalker_apps');
        if (empty($tos)) {
            return $this->app['twig']->render($this->getTemplateName('ApplicationCatalog::index'));
        } elseif (empty($tos[0]['accepted'])) {
            $this->app['tos'] = $tos[0];
            $this->app['tos_alias'] = 'stalker_apps';
            return $this->app['twig']->render($this->getTemplateName('ApplicationCatalog::tos'));
        }

        $attribute = $this->getApplicationListDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function smart_application_list() {

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $tos = $this->db->getTOS('launcher_apps');
        if (empty($tos)) {
            return $this->app['twig']->render($this->getTemplateName('ApplicationCatalog::index'));
        } elseif (empty($tos[0]['accepted'])) {
            $this->app['tos'] = $tos[0];
            $this->app['tos_alias'] = 'launcher_apps';
            return $this->app['twig']->render($this->getTemplateName('ApplicationCatalog::tos'));
        }

        $attribute = $this->getSmartApplicationListDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->app['allType'] = array(
            array('id' => 1, 'title' => $this->setLocalization('Application')),
            array('id' => 2, 'title' => $this->setLocalization('System'))
        );

        $this->app['allCategory'] = array(
            array('id' => "media",          'title' => $this->setLocalization('Media')),
            array('id' => "apps",           'title' => $this->setLocalization('Application')),
            array('id' => "games",          'title' => $this->setLocalization('Games')),
            array('id' => "notification",   'title' => $this->setLocalization('Notification'))
        );

        $this->app['allInstalled'] = array(
            array('id' => 1, 'title' => $this->setLocalization('Not installed')),
            array('id' => 2, 'title' => $this->setLocalization('Installed'))
        );

        $this->app['allStatus'] = array(
            array('id' => 1, 'title' => $this->setLocalization('Off')),
            array('id' => 2, 'title' => $this->setLocalization('On'))
        );

        $this->app['allCompatibility'] = array(
            array('id' => 1, 'title' => $this->setLocalization('Incompatible')),
            array('id' => 2, 'title' => $this->setLocalization('Compatible'))
        );

        $this->getSmartApplicationFilters();

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function accept_tos() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if ($this->app['userlogin'] === 'admin' && !empty($this->postData['accepted']) && !empty($this->postData['tos_alias'])){
            $this->db->setAcceptedTOS($this->postData['tos_alias']);
        }

        if (!empty($this->postData['tos_alias'])) {
            $redirect_path = "/application-catalog" . (($this->postData['tos_alias']) == 'launcher_apps' ? '/smart-application-list': '/application-list');
        } else {
            $redirect_path = "/";
        }

        return $this->app->redirect($this->workURL . $redirect_path);
    }

    public function application_detail(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if (empty($this->data['id'])) {
            return $this->app->redirect($this->workURL . '/' . $this->app['controller_alias']. '/application-list');
        }

        $attribute = $this->getApplicationDetailDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->app['app_info'] = $this->application_version_list_json(TRUE);
        $this->app['breadcrumbs']->addItem($this->setLocalization('Stalker applications'), 'application-catalog/application-list');
        $this->app['breadcrumbs']->addItem(!empty($this->app['app_info']['info']['name']) ? $this->app['app_info']['info']['name'] : $this->setLocalization('Undefined'));

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function smart_application_detail(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if (empty($this->data['id'])) {
            return $this->app->redirect($this->workURL . '/' . $this->app['controller_alias'] . '/smart-application-list');
        }

        $attribute = $this->getSmartApplicationDetailDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->app['app_info'] = $this->smart_application_version_list_json(TRUE);
        $this->app['breadcrumbs']->addItem($this->setLocalization('Applications of Smart Launcher'), 'application-catalog/smart-application-list');
        $this->app['breadcrumbs']->addItem(!empty($this->app['app_info']['info']['name']) ? $this->app['app_info']['info']['name'] : $this->setLocalization('Undefined'));

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function tos(){
        if (!empty($this->postData['tos_alias'])) {
            $redirect_path = "/application-catalog" . (($this->postData['tos_alias']) == 'launcher_apps' ? '/smart-application-list': '/application-list');
        } else {
            $redirect_path = "/";
        }

        return $this->app->redirect($this->workURL . $redirect_path);
    }

    //----------------------- ajax method --------------------------------------

    public function application_list_json($local_uses = FALSE) {

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0
        );

        $error = $this->setLocalization('Failed');
        try{
            $apps_list = new \AppsManager();
            if (!$local_uses) {
                $response['data'] = array_map(function($row){
                    $row['RowOrder'] = "dTRow_" . $row['id'];
                    return $row;
                },$apps_list->getList());
                $error = '';
            } else {
                $param = (!empty($this->data)?$this->data: $this->postData);
                $id = !empty($param['id']) ? $param['id'] : NULL;
                $app = $apps_list->getAppInfo($id);
                if (!empty($app)) {
                    unset($app['versions']);
                    $app['RowOrder'] = "dTRow_" . $app['id'];
                    $error = '';
                    $response['data'][] = $app;
                } else {
                    $response['msg'] = $error = $this->setLocalization('Application is not defined');
                }
            }
        } catch (\Exception $e){
            $response['msg'] = $error = $this->setLocalization('Failed to get the list of applications');
        }

        $response['recordsTotal'] = $response['recordsFiltered'] = count($response['data']);

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function smart_application_list_json($local_uses = FALSE){
        if (!$this->isAjax  && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'action' => ''
        );

        $filds_for_select = $this->getSmartApplicationFields();

        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);

        $filter = $this->getSmartApplicationFilters();

        $installed = NULL;
        if (array_key_exists('installed', $filter)) {
            $installed = (bool) $filter['installed'];
            unset($filter['installed']);
        }

        $query_param = $this->prepareDataTableParams($param, array('operations', /*'logo', 'name', 'available_version', 'conflicts', 'description',*/ 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        $query_param['where'] = array_merge($query_param['where'], $filter);

        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        $get_conflicts = FALSE;
        if (!empty($param['id'])) {
            $query_param['where'] = array('L_A.`id`' => $param['id']);
            $response['action'] = 'buildModalByAlias';
            $get_conflicts = TRUE;
            if (array_key_exists('curr_row', $param) && !array_key_exists('alias', $param)) {
                $response['curr_row'] = $param['curr_row'];
                $response['action'] = 'oneRowRender';
            }
        }

        if (!empty($query_param['like'])) {
            if (array_key_exists('description', $query_param['like'])) {
                $query_param['like']['localization'] = $query_param['like']['description'];
            } elseif (array_key_exists('name', $query_param['like'])){
                $query_param['like']['localization'] = $query_param['like']['name'];
            }
        }

        if (!in_array('L_A.`id` as `id`', $query_param['select'])) {
            $query_param['select'][] = 'L_A.`id` as `id`';
        }
        if (!in_array('L_A.`alias` as `alias`', $query_param['select'])) {
            $query_param['select'][] = 'L_A.`alias` as `alias`';
        }

        $query_param['in'] = array();
        if ($installed !== NULL) {
            $apps_manager = new \SmartLauncherAppsManager($this->app['language']);
            $apps_types = $this->db->getEnumValues('launcher_apps', 'type');
            $apps_types[] = '';
            $installed_app = array();
            foreach ($apps_types as $a_type){
                $installed_app = array_merge($installed_app, $apps_manager->getInstalledApps($a_type));
            }
            $query_param['in'][] = 'id';
            $query_param['in'][] = $this->getFieldFromArray($installed_app, 'id');
            $query_param['in'][] = !(bool)$installed;
        }

        $response['recordsTotal'] = $this->db->getTotalRowsSmartApplicationList();
        $response["recordsFiltered"] = $this->db->getTotalRowsSmartApplicationList($query_param['where'], $query_param['like'], $query_param['in']);
        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $base_obj = $this->db->getSmartApplicationList($query_param, FALSE);
        if ($get_conflicts || $installed !== NULL) {
            if(!isset($apps_manager)){
                $apps_manager = new \SmartLauncherAppsManager($this->app['language']);
            }

            while(list(,$row) = each($base_obj)) {
                try {
                    $info = $apps_manager->getAppInfo($row['id']);
                    if ($installed !== NULL && $installed !== $info['installed']) {
                        continue;
                    }
                    $row['name'] = $info['name'];
                    $row['description'] = $info['description'];
                    $row['available_version'] = $info['available_version'];
                    $row['conflicts'] = $row['available_version_conflicts'] = array();
                    if ($get_conflicts) {
                        if (!empty($row['current_version']) && !isset($param['curr_row'])) {
                            $row['conflicts'] = $apps_manager->getConflicts($row['id'], $row['current_version']);
                        }
                        if (!empty($row['available_version']) && (empty($row['current_version']) || $row['current_version'] != $row['available_version']) && !isset($param['curr_row'])) {
                            $row['available_version_conflicts'] = $apps_manager->getConflicts($row['id'], $row['available_version']);
                        }
                    }
                    $row['icon'] = !empty($info['icon']) ? $info['icon']: $this->getIconByType($row['type']);
                    $row['backgroundColor'] = $info['backgroundColor'];
                    settype($row['status'], 'int');
                    $row['installed'] = $info['installed'];
                    $row['rerendered'] = TRUE;
                    if (count($response["data"]) <= 50) {
                        $response["data"][] = $row;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        } else {
            $response["data"] = $base_obj;
        }

        if (!empty($response["data"])) {
            $response["data"] = array_map(function($row){
                $row['RowOrder'] = "dTRow_" . $row['id'];
                return $row;
            }, $response["data"]);
        }

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
    }

    public function application_get_data_from_repo(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['apps']['url'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'buildSaveForm';
        $response['data'] = array();
        $response['msg'] = '';
        try{
            $repo =  new \GitHub($this->postData['apps']['url']);
            $response['data'] = $repo->getFileContent('package.json');
            if (!array_key_exists('repository', $response['data'])) {
                $response['data']['repository']['url'] = $this->postData['apps']['url'];
            }
        } catch(\GitHubError $e){
            $response['msg'] = $this->setLocalization($e->getMessage());
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_get_data_from_repo(){

        if (!$this->isAjax || $this->method != 'POST' || (empty($this->postData['apps']['url']) && empty($this->postData['alias']))) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = (!empty($this->postData['alias']) ? 'buildModalByAlias': 'buildSaveForm');
        $response['data'] = array();
        $error = '';
        try{
            $search_str = !empty($this->postData['apps']['url']) ? $this->postData['apps']['url']: $this->postData['alias'];
            if (strpos($search_str, "://") === FALSE) {
                $repo =  new \Npm();
                $response['data'] = $repo->info($search_str, (!empty($this->postData['version'])? $this->postData['version']: NULL));
                $response['data']['name'] = isset($response['data']['config']['type']) && $response['data']['config']['type'] == 'app' && isset($response['data']['config']['name']) ? $response['data']['config']['name'] : $response['data']['name'];
                if (!empty($response['data'])) {
                    $response['data']['repository']['url'] = $search_str;
                } else {
                    $response['msg'] = $error = $this->setLocalization('No data about this apps');
                }
            } else {
                $response['msg'] = $error = $this->setLocalization('Invalid package name');
            }

        } catch(\Exception $e){
            $response['msg'] = $this->setLocalization($e->getMessage());
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function application_add(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['apps'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'updateTableData';
        $postData = $this->postData['apps'];
        if (!empty($postData['url'])) {
            $app = $this->db->getApplication(array('url' => $postData['url']));
            if (empty($app) && $this->db->insertApplication($postData)) {
                $error = '';
                $response['msg'] = $this->setLocalization('Installed');
            } else {
                $response['msg'] = $error = $this->setLocalization('Perhaps the application is already installed. You can update it if the new version is available or uninstall and install again');
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('URL of application is not defined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_add(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['apps'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'manageList';
        $postData = $this->postData['apps'];
        if (!empty($postData['url'])) {
            $app = $this->db->getSmartApplication(array('url' => $postData['url']));
            if (empty($app) && $this->db->insertSmartApplication($postData)) {
                $response['msg'] = $error = '';
            } else {
                $response['msg'] = $error = $this->setLocalization('Perhaps the application is already installed. You can update it if the new version is available or uninstall and install again');
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('Package name is not defined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function application_version_list_json($local_uses = FALSE){

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'action' => 'manageList',
            'info'=> array()
        );

        $id = FALSE;

        $version = !empty($this->postData['version']) ? $this->postData['version'] : FALSE;

        if (!empty($this->data['id'])) {
            $id = $this->data['id'];
        }
        if (!empty($this->postData['id'])) {
            $id = $this->postData['id'];
            $response['action'] = 'createOptionForm';
        }

        try{
            $apps_list = new \AppsManager();
            $app = $apps_list->getAppInfo($id);
        } catch (\Exception $e){
            $response['msg'] = $error = $this->setLocalization('Failed to get the list of versions of this applications') . '. ' . $e->getMessage();
            $app = FALSE;
        }

        if ($app !== FALSE) {
            $response["data"] = array_values(array_filter(array_map(function($row) use ($version){
                if ($version === FALSE || $version == $row['version']) {
                    $row['published'] = (int)strtotime($row['published']);
                    $row['published'] = $row['published'] < 0 ? 0 : $row['published'];
                    $row['RowOrder'] = "dTRow_" . str_replace('.', '_', $row['version']);
                    return $row;
                }
            }, $app['versions'])));
            $response['recordsTotal'] = count($response["data"]);
            $response['recordsFiltered'] = count($response["data"]);
            unset($app['versions']);
            $response['info'] = $app;
        }

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
    }

    public function smart_application_version_list_json($local_uses = FALSE){

        if (!$this->isAjax  && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'action' => 'manageList',
            'info'=> array()
        );

        $id = FALSE;

        $version = !empty($this->postData['version']) ? $this->postData['version'] : FALSE;

        if (!empty($this->data['id'])) {
            $id = $this->data['id'];
        }
        if (!empty($this->postData['id'])) {
            $id = $this->postData['id'];
            $response['action'] = 'createOptionForm';
        }

        try{
            $apps_list = new \SmartLauncherAppsManager($this->app['language']);
            $app = $apps_list->getAppInfo($id);
            $app['versions'] = $apps_list->getAppVersions($id);
            $app['conflicts'] = $apps_list->getConflicts($id, $version);
        } catch (\Exception $e){
            $response['msg'] = $error = $this->setLocalization('Failed to get the list of versions of this applications') . '. ' . $e->getMessage();
            $app = FALSE;
        }
        if ($app !== FALSE) {
            $id = $app['id'];
            $response["data"] = array_values(array_filter(array_map(function($row) use ($version, $apps_list, $id){
                if ($version === FALSE || $version == $row['version']) {
                    /*$row['published'] = (int)strtotime($row['published']);*/
                    $row['published'] = $row['published'] < 0 ? 0 : $row['published'];
                    try{
                        $row['conflicts'] = $apps_list->getConflicts($id, $row['version']);
                    }catch (\Exception $e){
                        $row['error'] = $e->getMessage();
                    }
                    return $row;
                }
            }, $app['versions'])));
            $response['recordsTotal'] = count($response["data"]);
            $response['recordsFiltered'] = count($response["data"]);
            unset($app['versions']);
            $response['info'] = $app;
        }

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
    }

    public function application_version_save_option(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['apps'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'changeStatus';
        $postData = $this->postData['apps'];
        if (!empty($postData['id'])) {
            $app_id = $postData['id'];
            unset($postData['id']);
            $option = json_encode($postData);

            $result = $this->db->updateApplication(array('options' => $option), $app_id);
            if (is_numeric($result)) {
                $response['msg'] = $error = '';
                if ($result === 0) {
                    $response['nothing_to_do'] = TRUE;
                }
            } else {
                $response['msg'] = $error = $this->setLocalization('Failed to update the parameters of application launch');
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('Application is undefined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_version_save_option(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['apps'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'manageList';
        $postData = $this->postData['apps'];
        if (!empty($postData['id'])) {
            $app_id = $postData['id'];
            unset($postData['id']);
            $option = json_encode($postData);

            $result = $this->db->updateSmartApplication(array('options' => $option), $app_id);
            if (is_numeric($result)) {
                $response['msg'] = $error = '';
                if ($result === 0) {
                    $response['nothing_to_do'] = TRUE;
                }
            } else {
                $response['msg'] = $error = $this->setLocalization('Failed to update the parameters of application launch');
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('Application is undefined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function application_version_install(){

        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'changeStatus';
        if (!empty($this->postData['id'])) {
            ignore_user_abort(true);
            set_time_limit(0);

            try{
                $apps = new \AppsManager();
                if (empty($this->postData['version'])) {
                    $result = $apps->installApp($this->postData['id']);
                } else {
                    $result = $apps->updateApp($this->postData['id'], $this->postData['version']);
                }
                if ($result !==FALSE ) {
                    $response['msg'] = $error = '';
                    $response['installed'] = 1;
                } else {
                    $response['msg'] = $error = $this->setLocalization('Error of installing the application');
                }
            } catch(\PharException $e){
                $response['msg'] = $this->setLocalization($e->getMessage());
            } catch(\Exception $e){
                $response['msg'] = $this->setLocalization($e->getMessage());
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('Application is undefined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_version_install(){

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $id = !empty($this->postData['id']) && is_numeric($this->postData['id']) ? $this->postData['id'] : (!empty($this->data['id']) && is_numeric($this->data['id']) ? $this->data['id']: FALSE);

        if ($id !== FALSE){
            $response['id'] = $id;
        }

        $curr_row = !empty($this->postData['curr_row']) ? $this->postData['curr_row'] : (!empty($this->data['curr_row']) ? $this->data['curr_row']: FALSE);
        if ($curr_row !== FALSE){
            $response['curr_row'] = strpos($curr_row, '#') === FALSE ? "#".$curr_row: $curr_row ;
        }
        if (!empty($this->postData['info'])) {
            $response['action'] = 'resetAllWarning';
            if ($id !== FALSE) {
                $response['url_id'] =  'install_app_' . $id;
                $response['modal_message'] = $this->setLocalization('Do you really want install this application?');
            }

            $response['button_message'] = $this->setLocalization('Install');
            $error = '';
        } else {

            $response['action'] = 'manageList';
            if ($id !== FALSE) {
                ignore_user_abort(true);
                set_time_limit(0);

                try {
                    $data['msg'] = $this->setLocalization('Installed');


                    $apps = new \SmartLauncherAppsManager($this->app['language']);

                    if (empty($this->postData['version'])) {

                        $response['action'] = '';
                        $this->beginNotifications();
                        $apps->setNotificationCallback(function($msg){
                            error_reporting(-1);
                            ini_set('display_errors','On');
                            ini_set('output_buffering', 'Off');
                            ini_set('output_handler', '');
                            ini_set('implicit_flush', 'On');
                            ob_implicit_flush(true);
                            while(ob_get_level()){
                                ob_end_clean();
                            }
                            ob_start();
                            echo '<script type="text/javascript"> var x = ' . microtime(TRUE) . ';</script>
                            ';
                            echo '<script  type="text/javascript"> window.parent.deliver("setModalMessage","' . $msg . '"); </script>
                            ';
                            ob_flush();
                        });
                    }

                    if (empty($this->postData['version'])) {
                        $result = $apps->installApp($id);
                    } else {
                        $result = $apps->updateApp($id, $this->postData['version']);
                    }
                    if ($result !== FALSE) {
                        $response['msg'] = $error = '';
                        $response['installed'] = 1;
                    } else {
                        $response['msg'] = $error = $this->setLocalization('Error of installing the application');
                    }
                } catch (\PharException $e) {
                    $error = $response['msg'] = $this->setLocalization($e->getMessage());
                } catch (\SmartLauncherAppsManagerException $e) {
                    $error = $response['msg'] = $this->setLocalization($e->getMessage());
                } catch (\SmartLauncherAppsManagerConflictException $e) {
                    $response['msg'] = $this->setLocalization($e->getMessage());
                    foreach ($e->getConflicts() as $row) {
                        $response['msg'] .= "<br>" . (!empty($row['target']) ? " $row[target] with " : '') . " $row[alias] $row[current_version]" . PHP_EOL;
                    }
                    $error = $response['msg'];
                } catch (\Exception $e) {
                    $response['msg'] = $this->setLocalization($e->getMessage());
                }
            } else {
                $response['msg'] = $error = $this->setLocalization('Application is undefined');
            }
        }
        $response = $this->generateAjaxResponse($response);
        $response = json_encode($response);
        if (empty($this->postData['info']) && empty($this->postData['version'])) {
            $this->endNotification($response, $error, "setModalMessage", "manageList");
            exit;
        }
        return new Response($response, (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function application_version_delete(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'changeStatus';
        if (!empty($this->postData['id'])) {
            ignore_user_abort(true);
            set_time_limit(0);

            $app_db = $this->db->getApplication(array('id' => $this->postData['id']));

            try{
                $apps = new \AppsManager();
                $apps->deleteApp($this->postData['id'], $this->postData['version']);
                $error = '';
                $response['msg'] = $this->setLocalization('Deleted');

                if ($app_db[0]['current_version'] == $this->postData['version']) {
                    $response['installed'] = 0;
                }

                $response['id'] =  $this->postData['id'];
            } catch(\Exception $e){
                $response['msg'] = $error = $this->setLocalization('Error of uninstalling the application.');
                $response['msg'] .= ' ' . $this->setLocalization($e->getMessage());
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('Application is undefined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_version_delete(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'manageList';
        if (!empty($this->postData['id'])) {
            ignore_user_abort(true);
            set_time_limit(0);

            $app_db = $this->db->getSmartApplication(array('id' => $this->postData['id']));

            try{
                $apps = new \SmartLauncherAppsManager($this->app['language']);
                $apps->deleteApp($this->postData['id'], $this->postData['version']);
                $response['msg'] = $error = '';

                if ($app_db[0]['current_version'] == $this->postData['version']) {
                    $response['installed'] = 0;
                }

            } catch(\Exception $e){
                $response['msg'] = $error = $this->setLocalization('Error of uninstalling the application.');
                $response['msg'] .= ' ' . $this->setLocalization($e->getMessage());
            }
        } else {
            $response['msg'] = $error = $this->setLocalization('Application is undefined');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function application_toggle_state(){

        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'changeStatus';
        $response['field'] = 'app_status';
        $postData = $this->postData;
        $response['id'] = $id = $postData['id'];
        $key = '';
        if (array_key_exists('status', $postData)) {
            $postData['status'] = !empty($postData['status']) && $postData['status'] != 'false' && $postData['status'] !== FALSE ? 1: 0;
            $key = 'status';
        }

        if (array_key_exists('autoupdate', $postData)) {
            $postData['autoupdate'] = !empty($postData['autoupdate']) && $postData['autoupdate'] != 'false' && $postData['autoupdate'] !== FALSE ? 1: 0;
            $response['field'] = 'app_autoupdate';
            $key = 'autoupdate';
        }

        unset($postData['id']);

        $result = $this->db->updateApplication($postData, $id);
        if (is_numeric($result)) {
            $response['msg'] = $error = '';
            if (!empty($postData['current_version'])) {
                $response['msg'] = $this->setLocalization('Activated. Current version') . ' ' . $postData['current_version'];
            } else {
                $response = array_merge_recursive($response, $this->application_list_json(TRUE));
                $response['action'] = 'updateTableRow';
            }
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $response['installed'] = !empty($postData[$key]) && $postData[$key] != 'false' && $postData[$key] !== FALSE? 1: 0;
        } else {
            $response['msg'] = $error = $this->setLocalization('Failed to activated of application.');
            if (!empty($postData['current_version'])) {
                $response['msg'] = $error .= $this->setLocalization('Version') . ' ' .$postData['current_version'];
            }
            $response['installed'] = (int)!(!empty($postData[$key]) && $postData[$key] != 'false' && $postData[$key] !== FALSE? 1: 0);
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_toggle_state(){

        if (!$this->isAjax) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'changeStatus';
        $response['field'] = 'app_status';
        $response['conflicts'] = FALSE;
        $postData = $this->postData;
        $response['id'] = $id = $postData['id'];
        $key = '';
        $error = '';

        if (array_key_exists('curr_row', $postData)) {
            $response['curr_row'] = $postData['curr_row'];
            unset($postData['curr_row']);
        }

        if (array_key_exists('status', $postData)) {
            $postData['status'] = !empty($postData['status']) && $postData['status'] != 'false' && $postData['status'] !== FALSE ? 1: 0;
            $key = 'status';
        }

        if (array_key_exists('autoupdate', $postData)) {
            $postData['autoupdate'] = !empty($postData['autoupdate']) && $postData['autoupdate'] != 'false' && $postData['autoupdate'] !== FALSE ? 1: 0;
            $response['field'] = 'app_autoupdate';
            $key = 'autoupdate';
        }

        $app = $this->db->getSmartApplication(array('id' => $id));

        if ($postData['status'] && !empty($app) && $app[0]['type'] == 'launcher') {
            $active_launchers = $this->db->getSmartApplication(array('id<>' => $id, 'status' => 1, 'type' => 'launcher'));
            if (!empty($active_launchers)) {
                $response['msg'] = $error = $this->setLocalization('Disable {ln} and then enable this launcher', '', $active_launchers[0]['url'], array('{ln}' => $active_launchers[0]['url']));
            }
        }

        try{
            $apps_list = new \SmartLauncherAppsManager($this->app['language']);
            $conflicts = $apps_list->getConflicts($id, (!empty($postData['current_version']) ? $postData['current_version']: NULL));
            $response['conflicts'] = !empty($conflicts);
        } catch (\Exception $e){

        }

        unset($postData['id']);

        if (empty($error)) {
            if (!$response['conflicts'] || !$postData['status']) {
                $result = $this->db->updateSmartApplication($postData, $id);
                if (is_numeric($result)) {
                    $response['msg'] = $error = '';
                    if (!empty($postData['current_version'])) {
                        $response['msg'] = $this->setLocalization('Activated. Current version') . ' ' . $postData['current_version'];
                    }
                    if ($result === 0) {
                        $response['nothing_to_do'] = TRUE;
                    }
                    $response['installed'] = !empty($postData[$key]) && $postData[$key] != 'false' && $postData[$key] !== FALSE ? 1 : 0;;
                } else {
                    $response['msg'] = $error = $this->setLocalization('Failed to activated of application.');
                    if (!empty($postData['current_version'])) {
                        $response['msg'] = $error .= $this->setLocalization('Version') . ' ' . $postData['current_version'];
                    }
                    $response['installed'] = (int)!(!empty($postData[$key]) && $postData[$key] != 'false' && $postData[$key] !== FALSE ? 1 : 0);
                }
            } else {
                $response['msg'] = $error = $this->setLocalization('This application version has conflicts');
            }
        }
        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function application_delete(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'deleteTableRow';
        $response['id'] = $this->postData['id'];

        if ($this->db->deleteApplication($this->postData)) {
            $error = '';
            $response['msg'] = $this->setLocalization('Application has been deleted');
        } else {
            $response['msg'] = $error = $this->setLocalization('Failed to delete application.');
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_delete(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response['action'] = 'manageList';

        try{
            $apps = new \SmartLauncherAppsManager($this->app['language']);
            $apps->deleteApp($this->postData['id']);
            $response['msg'] = $error = '';
            $response['msg'] = $this->setLocalization('Application has been deleted');
        } catch (\SmartLauncherAppsManagerException $e) {
            $response['msg'] = $error = $this->setLocalization('Failed to delete application.') . $e->getMessage();
        }

        $response = $this->generateAjaxResponse($response);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_reset_all(){

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response = array('action' => 'manageList');
        $error = $this->setLocalization('Failed');

        if (!empty($this->postData['info'])) {
            $response['action'] = 'resetAllWarning';
            $response['data'] = $this->db->getSmartApplication(array('manual_install' => 1));
            $error = '';
        } else {
            try{
                $response = array('action' => '');
                $this->beginNotifications();
                $apps = new \SmartLauncherAppsManager($this->app['language']);
                $apps->setNotificationCallback(function($msg){
                    error_reporting(-1);
                    ini_set('display_errors','On');
                    ini_set('output_buffering', 'Off');
                    ini_set('output_handler', '');
                    ini_set('implicit_flush', 'On');
                    ob_implicit_flush(true);
                    while(ob_get_level()){
                        ob_end_clean();
                    }
                    ob_start();
                    echo '<script type="text/javascript"> var x = ' . microtime(TRUE) . ';</script>
                    ';
                    echo '<script  type="text/javascript"> window.parent.deliver("setModalMessage","' . $msg . '"); </script>
                    ';
                    ob_flush();
                });
                if ($apps->resetApps()){
                    $error = '';
                }
            } catch (\SmartLauncherAppsManagerConflictException $e) {
                $response['msg'] = $error = $e->getMessage();
                foreach($e->getConflicts() as $row){
                    $error .= "<br>" . (!empty($row['target'])? "$row[target] with ": '') . " $row[alias] $row[current_version]";
                }
            } catch (\SmartLauncherAppsManagerException $e) {
                $response['msg'] = $error = $e->getMessage();
            }
        }
        $response = $this->generateAjaxResponse($response);
        $response = json_encode($response);
        if (empty($this->postData['info'])) {
            $this->endNotification($response, $error, "setModalMessage", "manageList");
            exit;
        }

        return new Response($response, (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_download_list(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $response = array('action' => '');
        $error = $this->setLocalization('Failed');

        try{
            $apps = new \SmartLauncherAppsManager($this->app['language']);

            header('Set-Cookie: fileDownload=true; path=/');
            header('Cache-Control: max-age=60, must-revalidate');
            header("Content-type: text/json");
            header('Content-Disposition: attachment; filename="stalker-apps-snapshot-' . \DateTime::createFromFormat('U', time())->format('Y_m_d_H_i') . '.json"');

            echo $apps->getSnapshot();
            exit;
        } catch (\SmartLauncherAppsManagerException $e) {
            $response['msg'] = $error = $e->getMessage();
        }

        $response = $this->generateAjaxResponse($response);
        $response = json_encode($response);
        return new Response($response, (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_upload_list(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $response = array('action' => '');
        $error = $this->setLocalization('Failed');

        if (empty($this->data['install_path']) || empty($this->data['install_file'])) {
            try{
                $storage = new \Upload\Storage\FileSystem('/tmp', TRUE);
                $file = new \Upload\File('files', $storage);
                // Success!
                $file->upload();
                $response['install_path'] = $file->getPath();
                $response['install_file'] = $file->getNameWithExtension();
                $response['msg'] = $this->setLocalization('Loaded');
                $error = '';
                $response['action'] = 'setUploadMessage';
            } catch (\Exception $e) {
                $response['msg'] = $error = $e->getMessage();
                if (!empty($file)) {
                    $error .= ' ' . $file->getErrors();
                }
                $response['msg'] = $error;
            }
        } else {
            try {
                $json_str = file_get_contents($this->data['install_path'] . '/' . $this->data['install_file']);
                @unlink($this->data['install_path'] . '/' . $this->data['install_file']);
                ignore_user_abort(TRUE);
                set_time_limit(0);
                $this->beginNotifications();
                $apps = new \SmartLauncherAppsManager($this->app['language']);
                $apps->setNotificationCallback(function($msg){
                    error_reporting(-1);
                    ini_set('display_errors','On');
                    ini_set('output_buffering', 'Off');
                    ini_set('output_handler', '');
                    ini_set('implicit_flush', 'On');
                    ob_implicit_flush(true);
                    while(ob_get_level()){
                        ob_end_clean();
                    }
                    ob_start();
                    echo '<script type="text/javascript"> var x = ' . microtime(TRUE) . ';</script>
                    ';
                    echo '<script  type="text/javascript"> window.parent.deliver("setUploadMessage","' . $msg . '"); </script>
                    ';
                    ob_flush();
                });

                $apps->restoreFromSnapshot($json_str);
                $error = '';
                $response['action'] = '';
                $response['msg'] = $this->setLocalization('Loaded');
            } catch (\SmartLauncherAppsManagerConflictException $e) {
                $response['msg'] = $error = $e->getMessage();
                foreach ($e->getConflicts() as $row) {
                    $error .= "<br>" . (!empty($row['target']) ? "$row[target] with " : '') . " $row[alias] $row[current_version]";
                }
            } catch (\SmartLauncherAppsManagerException $e) {
                $response['msg'] = $error = $e->getMessage();
            }
        }

        $response = $this->generateAjaxResponse($response);
        $response = json_encode($response);
        if (!empty($this->data['install_path']) && !empty($this->data['install_file'])) {
            $this->endNotification($response, $error, "setUploadMessage", "manageList");
            exit;
        }

        return new Response($response, (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_update(){

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $response = array('action' => 'manageList');
        $error = $this->setLocalization('Failed');

        $id = !empty($this->postData['id']) && is_numeric($this->postData['id']) ? $this->postData['id'] : (!empty($this->data['id']) && is_numeric($this->data['id']) ? $this->data['id']: FALSE);

        if ($id !== FALSE){
            $response['id'] = $id;
        }

        $curr_row = !empty($this->postData['curr_row']) ? $this->postData['curr_row'] : (!empty($this->data['curr_row']) ? $this->data['curr_row']: FALSE);
        if ($curr_row !== FALSE){
            $response['curr_row'] = strpos($curr_row, '#') === FALSE ? "#".$curr_row: $curr_row ;
        }
        if (!empty($this->postData['info'])) {
            $response['action'] = 'resetAllWarning';
            if ($id !== FALSE) {
                $response['url_id'] =  'update_app_' . $id;
                $response['modal_message'] = $this->setLocalization('Do you really want update this application?');
            } else {
                $response['url_id'] =  'update_all_apps';
                $response['modal_message'] = $this->setLocalization('Do you really want update all application?');
            }

            $response['button_message'] = $this->setLocalization('Update');
            $error = '';
        } else {
            try {
                $data['msg'] = $this->setLocalization('Updated');

                $response['action'] = '';
                $this->beginNotifications();
                $apps = new \SmartLauncherAppsManager($this->app['language']);
                $apps->setNotificationCallback(function($msg){
                    error_reporting(-1);
                    ini_set('display_errors','On');
                    ini_set('output_buffering', 'Off');
                    ini_set('output_handler', '');
                    ini_set('implicit_flush', 'On');
                    ob_implicit_flush(true);
                    while(ob_get_level()){
                        ob_end_clean();
                    }
                    ob_start();
                    echo '<script type="text/javascript"> var x = ' . microtime(TRUE) . ';</script>
                        ';
                    echo '<script  type="text/javascript"> window.parent.deliver("setModalMessage","' . $msg . '"); </script>
                        ';
                    ob_flush();
                });
                $param = array();
                $func = 'updateApps';

                if ($id !== FALSE) {
                    $func = 'updateApp';
                    $param[] = $id;
                }
                $error = '';
                call_user_func_array(array($apps, $func), $param);

            } catch (\SmartLauncherAppsManagerException $e) {
                $error = $e->getMessage();
            }
        }

        $response = $this->generateAjaxResponse($response);
        $response = json_encode($response);
        if (empty($this->postData['info'])) {
            $this->endNotification($response, $error, "setModalMessage", "manageList");
            exit;
        }

        return new Response($response, (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function smart_application_check_update(){

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $response = array('action' => 'manageList');
        $error = $this->setLocalization('Failed');

        $id = !empty($this->postData['id']) && is_numeric($this->postData['id']) ? $this->postData['id'] : (!empty($this->data['id']) && is_numeric($this->data['id']) ? $this->data['id']: FALSE);

        if ($id !== FALSE){
            $response['id'] = $id;
        }

        $curr_row = !empty($this->postData['curr_row']) ? $this->postData['curr_row'] : (!empty($this->data['curr_row']) ? $this->data['curr_row']: FALSE);
        if ($curr_row !== FALSE){
            $response['curr_row'] = strpos($curr_row, '#') === FALSE ? "#".$curr_row: $curr_row ;
        }
        if (!empty($this->postData['info'])) {
            $response['action'] = 'resetAllWarning';
            if ($id !== FALSE) {
                $response['url_id'] =  'check_update_app_' . $id;
                $response['modal_message'] = $this->setLocalization('Do you really want checking update for this application?');
            } else {
                $response['url_id'] =  'check_update_apps';
                $response['modal_message'] = $this->setLocalization('Do you really want checking updates for all application?');
            }

            $response['button_message'] = $this->setLocalization('Check');
            $error = '';
        } else {
            try {
                $data['msg'] = $this->setLocalization('Checked');

                $response['action'] = '';
                $this->beginNotifications();
                $apps = new \SmartLauncherAppsManager($this->app['language']);
                $apps->setNotificationCallback(function($msg){
                    error_reporting(-1);
                    ini_set('display_errors','On');
                    ini_set('output_buffering', 'Off');
                    ini_set('output_handler', '');
                    ini_set('implicit_flush', 'On');
                    ob_implicit_flush(true);
                    while(ob_get_level()){
                        ob_end_clean();
                    }
                    ob_start();
                    echo '<script type="text/javascript"> var x = ' . microtime(TRUE) . ';</script>
                        ';
                    echo '<script  type="text/javascript"> window.parent.deliver("setModalMessage","' . $msg . '"); </script>
                        ';
                    ob_flush();
                });
                $param = array();
                $func = 'updateAllAppsInfo';

                if ($id !== FALSE) {
                    $func = 'getAppInfo';
                    $param[] = $id;
                    $param[] = TRUE;

                }
                $error = '';
                call_user_func_array(array($apps, $func), $param);

            } catch (\SmartLauncherAppsManagerException $e) {
                $error = $e->getMessage();
            }
        }

        $response = $this->generateAjaxResponse($response);
        $response = json_encode($response);
        if (empty($this->postData['info'])) {
            $this->endNotification($response, $error, "setModalMessage", "manageList");
            exit;
        }

        return new Response($response, (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));

    }

    //------------------------ service method ----------------------------------

    private function getApplicationListDropdownAttribute(){
        $attribute = array(
            array('name' => 'id',               'title' => $this->setLocalization('ID'),                'checked' => TRUE),
            array('name' => 'name',             'title' => $this->setLocalization('Application'),       'checked' => TRUE),
            /*array('name' => 'publisher',        'title' => $this->setLocalization('Publisher'),     'checked' => TRUE),*/
            array('name' => 'url',              'title' => $this->setLocalization('URL'),               'checked' => TRUE),
            array('name' => 'current_version',  'title' => $this->setLocalization('Current version'), 'checked' => TRUE),
            array('name' => 'status',           'title' => $this->setLocalization('State'),             'checked' => TRUE),
            array('name' => 'operations',       'title' => $this->setLocalization('Operations'),        'checked' => TRUE)
        );
        return $attribute;
    }

    private function getApplicationDetailDropdownAttribute() {
        $attribute = array(
            array('name' => 'version',      'title' => $this->setLocalization('Application version'),   'checked' => TRUE),
            array('name' => 'published',    'title' => $this->setLocalization('Release date'),          'checked' => TRUE),
            array('name' => 'status',       'title' => $this->setLocalization('State'),                 'checked' => TRUE),
            array('name' => 'operations',   'title' => $this->setLocalization('Operations'),            'checked' => TRUE)
        );
        return $attribute;
    }

    private function getSmartApplicationListDropdownAttribute(){
        $attribute = array(
            array('name' => 'icon',             'title' => $this->setLocalization('Logo'),              'checked' => TRUE),
            array('name' => 'name',             'title' => $this->setLocalization('Application'),       'checked' => TRUE),
            array('name' => 'type',             'title' => $this->setLocalization('Type'),              'checked' => TRUE),
            array('name' => 'category',         'title' => $this->setLocalization('Category'),          'checked' => TRUE),
            array('name' => 'current_version',  'title' => $this->setLocalization('Current version'),   'checked' => TRUE),
            array('name' => 'available_version','title' => $this->setLocalization('Actual version'),    'checked' => TRUE),
            /*array('name' => 'conflicts',    'title' => $this->setLocalization('Compatibility'),     'checked' => TRUE),*/
            array('name' => 'author',           'title' => $this->setLocalization('Author'),            'checked' => TRUE),
            array('name' => 'status',           'title' => $this->setLocalization('State'),             'checked' => TRUE),
            array('name' => 'description',      'title' => $this->setLocalization('Description'),       'checked' => TRUE),
            array('name' => 'operations',       'title' => $this->setLocalization('Operations'),        'checked' => TRUE)
        );
        return $attribute;
    }

    private function getSmartApplicationDetailDropdownAttribute(){
        $attribute = array(
            array('name' => 'version',          'title' => $this->setLocalization('Available versions'),'checked' => TRUE),
            array('name' => 'published',        'title' => $this->setLocalization('Date'),              'checked' => TRUE),
            array('name' => 'conflicts',        'title' => $this->setLocalization('Compatibility'),     'checked' => TRUE),
            array('name' => 'status',           'title' => $this->setLocalization('State'),             'checked' => TRUE),
            array('name' => 'operations',       'title' => $this->setLocalization('Operations'),        'checked' => TRUE)
        );
        return $attribute;
    }

    private function getSmartApplicationFields(){
        $attribute = array(
            'id' => 'L_A.`id` as `id`',
            'icon' => '"" as `icon`',
            'name' => 'L_A.`name` as `name`',
            'type' => 'L_A.`type` as `type`',
            'category' => 'L_A.`category` as `category`',
            'current_version' => 'L_A.`current_version` as `current_version`',
            'available_version' => '"" as `available_version`',
            'alias' => 'L_A.`alias` as `alias`',
            'conflicts' => '"" as `conflicts`',
            'author' => 'L_A.`author` as `author`',
            'status' => 'L_A.`status` as `status`',
            'localization' => 'L_A.`localization` as `localization`',
            'description' => '"" as `description`'
        );
        return $attribute; //L_A
    }

    private function getSmartApplicationFilters() {
        $return = array();

        if (empty($this->data['filters'])){
            $this->data['filters'] = array();
        }
        if (!array_key_exists('type', $this->data['filters'])) {
            $this->data['filters']['type'] ='1';
        }

        if ((string)$this->data['filters']['type'] != "0") {
            $return['`L_A`.`type`' . ($this->data['filters']['type'] == 1? '=': '<>')] = 'app';
        }

        if (array_key_exists('category', $this->data['filters']) && (string)$this->data['filters']['category'] != "0") {
            $return['`L_A`.`category`'] = $this->data['filters']['category'];
        }

        if (array_key_exists('installed', $this->data['filters']) && (string)$this->data['filters']['installed']!= "0") {
            $return['installed'] = $this->data['filters']['installed'] - 1;
        }

        if (array_key_exists('status', $this->data['filters']) && (string)$this->data['filters']['status']!= "0") {
            $return['`L_A`.`status`'] = $this->data['filters']['status'] - 1;
        }

        if (array_key_exists('conflicts', $this->data['filters']) && (string)$this->data['filters']['conflicts']!= "0") {
            /*$return['conflicts'] = $this->data['filters']['conflicts'] - 1;*/
        }

        $this->app['filters'] = $this->data['filters'];

        return $return;
    }

    private function getIconByType($type){
        switch ($type){
            case 'core':{
                return 'img/Core_icon2.png';
            }
            case 'launcher':{
                return 'img/Launcher_icon2.png';
            }
            case 'osd':{
                return 'img/OSD_icon2.png';
            }
            case 'plugin':{
                return 'img/Plugin_icon2.png';
            }
            case 'system':{
                return 'img/System_icon2.png';
            }
            case 'theme':{
                return 'img/Theme_icon2.png';
            }
            default:{
                return 'img/no_image.png';
            }
        }
    }

    private function beginNotifications(){
        ignore_user_abort(TRUE);
        set_time_limit(0);

        error_reporting(-1);
        ini_set('display_errors','On');
        ini_set('output_buffering', 'Off');
        ini_set('output_handler', '');
        ini_set('zlib.output_compression', 'Off');
        ini_set('implicit_flush', 'On');
        ob_implicit_flush(true);
        while(ob_get_level()){
            ob_end_clean();
        }
        ob_start();
        header($_SERVER["SERVER_PROTOCOL"]." 200 Ok");
        header('X-Accel-Buffering: no');
        header('Content-Type: text/html; charset=utf-8');
        ob_flush();
        echo '<!DOCTYPE html>
                            <head></head>
                            <body>
                            ';
        $sended = 0;
        $send_str = '<br/>
                ';
        $send_str_len = mb_strlen($send_str);
        while (($sended += $send_str_len) <= 1024) {
            echo $send_str;
        }
        ob_flush();
    }

    private function endNotification($response, $error, $msg_func, $act_func){
        error_reporting(-1);
        ini_set('display_errors','On');
        ini_set('output_buffering', 'Off');
        ini_set('output_handler', '');
        ini_set('implicit_flush', 'On');
        ob_implicit_flush(true);
        while(ob_get_level()){
            ob_end_clean();
        }
        ob_start();
        echo '<script type="text/javascript"> var x = ' . microtime(TRUE) . ';</script>
            ';
        if (empty($error)) {
            echo '<script type="text/javascript"> window.parent.deliver("'. $msg_func .'","' . $this->setLocalization('Done') . '"); </script>
                ';
            echo '<script type="text/javascript"> window.parent.deliver("'. $act_func .'", ' . $response . '); </script>
                ';
        } else {
            echo '<script type="text/javascript"> window.parent.deliver("'. $msg_func .'","' . $this->setLocalization('Error') . '! ' .  $error . '"); </script>
                ';
            echo '<script type="text/javascript"> window.parent.deliver("'. $act_func .'Error", ' . $response . '); </script>
                ';
        }
        echo '</body>
            </html>';
        ob_end_flush();
    }

}
