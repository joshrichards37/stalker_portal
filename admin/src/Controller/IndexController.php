<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;
use Stalker\Lib\Core\Config;
use Stalker\Lib\Core\SMACAccess;

class IndexController extends \Controller\BaseStalkerController {

    public function __construct(Application $app) {
        parent::__construct($app, __CLASS__);
        $this->app['error_local'] = array();
        $this->app['baseHost'] = $this->baseHost;
    }

    // ------------------- action method ---------------------------------------

    public function index() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $datatables['datatable-1'] = $this->index_datatable1_list_json('local_uses');
        $datatables['datatable-2'] = $this->index_datatable2_list_json('local_uses');
        $datatables['datatable-3'] = $this->index_datatable3_list_json('local_uses');

        $this->app['datatables'] = $datatables;

        $this->app['breadcrumbs']->addItem($this->setLocalization('Dashboard'));

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    //----------------------- ajax method --------------------------------------

    public function set_dropdown_attribute() {

        if (!$this->isAjax || empty($this->postData)) {
            $this->app->abort(404, 'Page not found');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'dropdownAttributesAction';
        $error = $this->setLocalization('Failed');

        $aliases = trim(str_replace($this->workURL, '', $this->refferer), '/');
        $aliases = array_pad(explode('/', $aliases), 2, 'index');
        
        $aliases[1] = urldecode($aliases[1]);
        $filters = explode('?', $aliases[1]);
        $aliases[1] = $filters[0];
        if (count($filters) > 1 && (!empty($this->data['set-dropdown-attribute']) && $this->data['set-dropdown-attribute'] == 'with-button-filters')) {
            $filters[1] = explode("&", $filters[1]);
            $filters[1] = $filters[1][0];
            $filters[1] = str_replace(array('=', '_'), '-', $filters[1]);
            $filters[1] = preg_replace('/(\[[^\]]*\])/i', '', $filters[1]);
            $aliases[1] .= "-$filters[1]";
        }
//        print_r($filters);exit;
        $param = array();
        $param['controller_name'] = $aliases[0];
        $param['action_name'] = $aliases[1];
        $param['admin_id'] = $this->admin->getId();
        $this->db->deleteDropdownAttribute($param);

        $param['dropdown_attributes'] = serialize($this->postData);
        $id = $this->db->insertDropdownAttribute($param);
        
        if ($id && $id != 0) {
            $error = '';
            $data['nothing_to_do'] = 1;
        }

        $response = $this->generateAjaxResponse($data, $error);
        if (empty($error)) {
            header($_SERVER['SERVER_PROTOCOL'] . " 200 OK", true, 200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($response);
        } else {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
        }

        exit;
    }

    public function index_datatable1_list_json($local_uses = FALSE){

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'data' => array(),
            'action' => 'datatableReload',
            'datatableID' => 'datatable-1',
            'json_action_alias' => 'index-datatable1-list-json'
        );
        $error = $this->setLocalization('Failed');
        $row = array('category'=>'', 'number' => '');

        if ($this->isAjax) {

            $row['category'] = $this->setLocalization('Users online');
            $row['number'] = '<span class="txt-success">' . $this->db->get_users('online') . '</sapn>';
            $data['data'][] = $row;

            $row['category'] = $this->setLocalization('Mobile');
            $row['number'] = '<span class="txt-success">' . $this->db->get_users('online', TRUE) . '</sapn>';
            $data['data'][] = $row;

            $row['category'] = $this->setLocalization('Users offline');
            $row['number'] = '<span class="txt-danger">' . $this->db->get_users('offline') . '</sapn>';
            $data['data'][] = $row;

            $row['category'] = $this->setLocalization('TV channels');
            $row['number'] = $this->db->getCountForStatistics('itv', array('status' => 1));
            $data['data'][] = $row;

            $row['category'] = $this->setLocalization('Films, serials');
            $row['number'] = $this->db->getCountForStatistics('video', array('status' => 1, 'accessed' => 1));
            $data['data'][] = $row;

            $row['category'] = $this->setLocalization('Audio albums');
            $row['number'] = $this->db->getCountForStatistics('audio_albums', array('status' => 1));
            $data['data'][] = $row;

            $row['category'] = $this->setLocalization('Karaoke songs');
            $row['number'] = $this->db->getCountForStatistics('karaoke', array('status' => 1));
            $data['data'][] = $row;
/*
            $row['category'] = $this->setLocalization('Installed applications');
            $row['number'] = 0;
            $data['data'][] = $row;*/

            $data["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

            $error = '';
            $data = $this->generateAjaxResponse($data);
            return new Response(json_encode($data), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            $data['data'][] = $row;
            return $data;
        }
    }

    public function index_datatable2_list_json($local_uses = FALSE){

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'datatableReload',
            'datatableID' => 'datatable-2',
            'json_action_alias' => 'index-datatable2-list-json'
        );

        $error = $this->setLocalization('Failed');


        $storages = $this->db->getStorages();

        if (count($storages)) {
            $data['data'] = array();
        }
        $data["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        $row_tmp = array(
            'storage' => '',
            'video' => '-',
            'tv_archive' => '-',
            'timeshift' => '-',
            'loading' => 0
        );
        if ($this->isAjax) {
            foreach ($storages as $storage) {
                $row = $row_tmp;
                $row['storage'] = $storage['storage_name'];
                $records = $this->db->getStoragesRecords($row['storage']);
                $total_storage_loading = $this->db->getStoragesRecords($row['storage'], TRUE);
                $row['loading'] = (int)$storage['max_online'] ? round(($total_storage_loading * 100) / $storage['max_online'], 2) . "%" : '-';
                foreach ($records as $record) {
                    if ($record['now_playing_type'] == 2) {
                        $row['video'] = $record['count'];
                    } elseif ($record['now_playing_type'] == 11) {
                        $row['tv_archive'] = $record['count'];
                    } elseif ($record['now_playing_type'] == 14) {
                        $row['timeshift'] = $record['count'];
                    }
                }
                $data['data'][] = $row;
            }
            $error = '';
            $data = $this->generateAjaxResponse($data);
            return new Response(json_encode($data), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            if (array_key_exists('data', $data)) {
                $data['data'][] = $row_tmp;
            }
            return $data;
        }
    }

    public function index_datatable3_list_json($local_uses = FALSE){

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'datatableReload',
            'datatableID' => 'datatable-3',
            'json_action_alias' => 'index-datatable3-list-json'
        );

        $error = $this->setLocalization('Failed');

        $streaming_servers = $this->db->getStreamServer();
        if (count($streaming_servers)) {
            $data['data'] = array();
        }

        $data["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        if ($this->isAjax) {
            foreach($streaming_servers as $server){
                $user_sessions = $this->db->getStreamServerStatus($server['id'], TRUE);
                $row = array(
                    'server'=> $server['name'],
                    'sessions' => $user_sessions,
                    'loading' => ((int) $server['max_sessions'] > 0 ? round(($user_sessions * 100)/$server['max_sessions'], 2)."%" : "&infin;")
                );
                $data['data'][] = $row;
            }
            $error = '';
            $data = $this->generateAjaxResponse($data);
            return new Response(json_encode($data), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            if (array_key_exists('data', $data)) {
                $data['data'][] = array('server'=> '', 'sessions' => '', 'loading' => '');
            }
            return $data;
        }
    }

    public function index_datatable4_list_json($local_uses = FALSE){

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'data' => array(),
            'action' => 'datatableReload',
            'datatableID' => 'datatable-4',
            'json_action_alias' => 'index-datatable4-list-json'
        );
        $error = $this->setLocalization('Failed');

        $data['data'] = array();

        $types = array('tv' => 1,'video' => 2, 'karaoke' => 3, 'audio' => 4, 'radio' => 5);
        $all_sessions = 0;

        foreach($types as $key=>$type){
            $data['data'][$key] = array();
            $data['data'][$key]['sessions'] = $this->db->getCurActivePlayingType($type);
            $all_sessions += $data['data'][$key]['sessions'];
        }

        $data['data'] = array_map(function($row) use ($all_sessions){
            settype($row['sessions'], 'int');
            $row['percent'] = ($all_sessions)? round(($row['sessions'] * 100)/$all_sessions,0): 0;
            return $row;
        }, $data['data']);

        $data['data']['all_sessions'] = (int)$all_sessions;

        if ($this->isAjax) {
            $error = '';
            $data = $this->generateAjaxResponse($data);
            return new Response(json_encode($data), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $data;
        }

    }

    public function index_datatable5_list_json($local_uses = FALSE){

        if (!$this->isAjax && $local_uses === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'data' => array(),
            'action' => 'datatableReload',
            'datatableID' => 'datatable-5',
            'json_action_alias' => 'index-datatable5-list-json'
        );
        $error = $this->setLocalization('Failed');

        $data['data'] = $this->db->getUsersActivity();

        $reseller = (int) $this->app['reseller'];

        $data['data'] = array_map(function($row) use ($reseller){
            settype($row['time'], 'int');
            $row['users_online'] = @json_decode($row['users_online'], TRUE);
            $key = empty($reseller) ? 'total': $reseller;
            $row['users_online'] = (is_array($row['users_online']) && array_key_exists($key, $row['users_online'])) ? (int) $row['users_online'][$key] : 0;
            return array($row['time'], $row['users_online']);
        }, $data['data']);

        if ($this->isAjax) {
            $error = '';
            $data = $this->generateAjaxResponse($data);
            return new Response(json_encode($data), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $data;
        }

    }

    public function opinion_check(){
        if (!$this->isAjax) {
            $this->app->abort(404, 'Page not found');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'setOpinionModal';
        $error = '';
        $data['remind'] = $this->app['session']->get('remind', FALSE);

        if ($this->admin->isSuperUser() && (is_null($this->admin->getOpinionFormFlag()) || $this->admin->getOpinionFormFlag() == 'remind')) {
            $data['link'] = $this->app['language'] == 'ru' ? 'https://goo.gl/forms/2bZsWJ06feIas5Aa2': 'https://goo.gl/forms/AQx9JhtJ9FYaBEJa2';
        } else {
            $data['remind'] = TRUE;
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function opinion_set(){
        if (!$this->isAjax || empty($this->postData['opinion'])) {
            $this->app->abort(404, 'Page not found');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'setOpinionData';
        $data['remind'] = TRUE;
        $data['link'] = $this->app['language'] == 'ru' ? 'https://goo.gl/forms/2bZsWJ06feIas5Aa2': 'https://goo.gl/forms/AQx9JhtJ9FYaBEJa2';
        $error = '';

        $this->db->getOpinionFormFlag($this->postData['opinion']);
        $this->app['session']->set('remind', TRUE);

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }


    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function note_list()
    {
        if (!$this->isAjax) {
            $this->app->abort(404, 'Page not found');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $error = '';
        $data = [];
        try {
            $feed = new \NotificationFeed();
            $data = $feed->getNotDeletedItems();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $response = $this->generateAjaxResponse(['data' => $data], $error);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function note_list_mark_deleted()
    {
        if (!$this->isAjax || empty($this->postData['guid'])) {
            $this->app->abort(404, 'Page not found');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $error = '';
        $data = [];
        try {
            $feed = new \NotificationFeed();
            $data = $feed->deleteByGuid($this->postData['guid']);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $response = $this->generateAjaxResponse(['data' => $data], $error);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function note_list_set_readed()
    {
        if (!$this->isAjax) {
            $this->app->abort(404, 'Page not found');
        }
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $guid = isset($this->postData['feed_item_id']) ? $this->postData['feed_item_id'] : null;

        $data = [];
        $error = '';
        try {
            $feed = new \NotificationFeed();
            $data = $feed->setRedByGuid($guid);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $response = $this->generateAjaxResponse(['data' => $data], $error);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function note_list_set_remind()
    {
        if (!$this->isAjax || empty($this->postData['feeditemid'])) {
            $this->app->abort(404, 'Page not found');
        }
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = [];
        $error = '';
        try {
            $feed = new \NotificationFeed();
            $item = $feed->getItemByGUId($this->postData['feeditemid']);
            $data = $item ? $item->setDelay(60 * 24) : [];
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $response = $this->generateAjaxResponse(['data' => $data], $error);
        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function check_certificate_server_health(){
        if (!$this->isAjax || empty($this->postData)) {
            $this->app->abort(404, 'Page not found');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'health_status' => FALSE,
            'time' => time() * 1000
        );

        $error = '';

        if (Config::getSafe('certificate_server_health_check', TRUE)) {
            try {
                if (array_key_exists('check_health_time', $this->postData)) {
                    if ((time() - (int)($this->postData['check_health_time']/1000)) > 3600) {
                        $smac = new SMACAccess();
                        $data['health_status'] = $smac->health(); // boolean
                        if (!$data['health_status']) {
                            $data['action'] = 'healthServerAlert';
                        }
                    }
                    $data['nothing_to_do'] = TRUE;
                } else {
                    $error = $this->setLocalization('Undefined last health check time');
                }
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $data['health_status'] = TRUE;
            $data['nothing_to_do'] = TRUE;
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));

    }

    //------------------------ service method ----------------------------------
}
