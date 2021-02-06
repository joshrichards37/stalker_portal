<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;

class TasksController extends \Controller\BaseStalkerController {
    
    protected $taskType = array();
    protected $taskState = array();
    protected $taskAllState = array();
    protected $taskArchive = array();
    protected $taskStatus = array();
    private $stateColor = array('primary','success','warning','danger', 'default');
    
    private $uid = FALSE;

    public function __construct(Application $app) {
        parent::__construct($app, __CLASS__);

        $this->taskType = array(
            array('id' => 'moderator_tasks', 'title' => $this->setLocalization('Movie')),
            array('id' => 'karaoke', 'title' => $this->setLocalization('Karaoke'))
        );
        $this->taskState = array(
            0=>array('id' => '1', 'title' => $this->setLocalization('Open')),
            3=>array('id' => '4', 'title' => $this->setLocalization('Expired'))
        );
        $this->taskAllState = array(
            0=>array('id' => '1', 'title' => $this->setLocalization('In process')),
            1=>array('id' => '2', 'title' => $this->setLocalization('Completed')),
            2=>array('id' => '3', 'title' => $this->setLocalization('Rejected')),
            3=>array('id' => '4', 'title' => $this->setLocalization('Expired'))
        );

        $this->taskArchive = array(
            0=>array('id' => '1', 'title' => $this->setLocalization('In the work')),
            1=>array('id' => '2', 'title' => $this->setLocalization('Can be archived')),
            2=>array('id' => '3', 'title' => $this->setLocalization('In the archive')),
            3=>array('id' => '4', 'title' => $this->setLocalization('Not in archive'))
        );

        $this->taskStatus = array(
            1=>array('id' => '1', 'title' => $this->setLocalization('Not ready')),
            0=>array('id' => '2', 'title' => $this->setLocalization('Ready'))
        );

        $this->uid = $this->admin->getId();
    }

    // ------------------- action method ---------------------------------------

    public function index() {
        if (empty($this->app['action_alias'])) {
            return $this->app->redirect($this->app['controller_alias'] . '/tasks-list');
        }
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function tasks_list() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $this->app['taskType'] = $this->taskType;
        $this->app['taskState'] = $this->taskAllState;
        $this->app['taskArchive'] = $this->taskArchive;
        $this->app['taskStatus'] = $this->taskStatus;
        $this->app['taskAdmin'] = $this->db->getAdmins(); // getAdmins( $user_id ) !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        
        $attribute = $this->getDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        if (empty($this->data['filters']['task_type'])) {
            if (empty($this->data['filters'])) {
                $this->data['filters'] = array('task_type' => 'moderator_tasks');
            } else {
                $this->data['filters']['task_type'] = 'moderator_tasks';
            }
        }

        if (!is_array($this->data['filters']) || !array_key_exists('archive', $this->data['filters'])) {
            if (empty($this->data['filters'])) {
                $this->data['filters'] = array('archive' => '4');
            } else {
                $this->data['filters']['archive'] = '4';
            }
        }

        $this->app['task_type_title'] = $this->getTaskTitle($this->data['filters']['task_type']);
        $this->app['task_type'] = $this->data['filters']['task_type'];
        $this->app['taskStateColor'] = $this->stateColor;
        
        $this->app['filters'] = $this->data['filters'];
        $this->app['breadcrumbs']->addItem($this->setLocalization('List of tasks in the category') . " '{$this->app['task_type_title']}'");
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function tasks_report() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $task_report_state = $this->taskAllState;
        $task_report_state[4] = array('id' => '5', 'title' => $this->setLocalization('Archive'));
        unset($task_report_state[0]);
        unset($task_report_state[3]);
        $this->app['taskType'] = $this->taskType;
        $this->app['taskState'] = $task_report_state;
        $this->app['videoQuality'] = array(
            0=>array('id' => '1', 'title' => 'SD'),
            1=>array('id' => '2', 'title' => 'HD'),
        );

        $attribute = $this->getReportDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        if (empty($this->data['filters']['task_type'])) {
            if (empty($this->data['filters'])) {
                $this->data['filters'] = array('task_type' => 'moderator_tasks');
            } else {
                $this->data['filters']['task_type'] = 'moderator_tasks';
            }
        }

        $this->app['task_type_title'] = $this->getTaskTitle($this->data['filters']['task_type']);
        $this->app['task_type'] = $this->data['filters']['task_type'];
        $this->app['taskStateColor'] = $this->stateColor;

        if ($this->data['filters']['task_type'] == 'moderator_tasks'){
            $this->app['allVideoDuration'] = 0;                              //!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
        }
        $this->app['filters'] = $this->data['filters'];
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function task_detail_video(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        if (empty($this->data['id']) && empty($this->postData['taskid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        
        $task_id = empty($this->data['id']) ? $this->postData['taskid']: $this->data['id'];
        $values = array();
        $values['type'] = $this->getTaskTitle('moderator_tasks');
        $values = array_merge($values, $this->db->getVideoTaskDetailInfoValues($task_id));

        if ($this->app['userlogin'] != 'admin' && ($values['from_usr_id'] != $this->uid && $values['to_usr_id'] != $this->uid )) {
            return $this->app->redirect('tasks-list');
        }

        $this->db->setReadedTaskMessage($task_id, $this->uid);
        $this->app['task_num'] = $task_id;
        $keys = $this->getVideoTaskDetailInfoFields();
        $this->app['taskTypeTitle'] = $values['type'];
        $values['state'] = "<span class='txt-{$this->stateColor[$values['state']]}'>{$this->taskAllState[$values['state']]['title']}</span>";
        
        $this->app['creator'] = $values['from_usr'];
        $this->app['comment'] = $values['comment'];
        $this->app['added'] = $values['added'];
        $this->app['recipientID'] = ($values['to_usr_id'] == $this->uid ? $values['from_usr_id']: $values['to_usr_id']);
        $this->app['toLeft'] = $values['from_usr_id'];

        unset($values['comment']);
        unset($values['added']);
        unset($values['from_usr_id']);
        unset($values['to_usr_id']);
        
        $this->app['infoTable'] = array_combine($keys, $values);
        $this->app['taskAllState'] = $this->taskAllState;
        $this->app['taskStateColor'] = $this->stateColor;
        
        $this->app['taskAll'] = array_map(function($val){
            $val['state'] = (int)$val['state']; 
            if ($val['state'] == 3) {
                $date = new \DateTime($val['start_time']);
                $val['end_time'] = $date->getTimestamp() + 86400;
            }
            return $val;
        }, $this->db->getVideoTaskChatList($task_id));
        $this->app['taskID'] = $task_id;
        $this->app['selfID'] = $this->uid;
        $this->app['task_type'] = 'moderator_tasks';
        $tmp = array_reverse($this->app['taskAll']);
        $last_row = array();
        foreach ($tmp as $row) {
            if($this->uid == $row['to_usr']){
               $last_row = $row;
            }
        }

        if (empty($last_row)) {
            $tmp = $this->app['taskAll'];
            $last_row = end($tmp);
        }
                
        $this->app['replyTo'] = $last_row['id'];
        $this->app['showForm'] = (!((bool)$last_row['archived']) && ($last_row['state'] != 1  && $last_row['state'] != 2));
        $this->app['showInput'] = TRUE;

        $this->app['breadcrumbs']->addItem($this->setLocalization('Tasks list'), $this->app['controller_alias'] . '/tasks-list');
        $this->app['breadcrumbs']->addItem($this->setLocalization('History of task') . " №{$this->app['task_num']} " . $this->setLocalization('in section') . " '{$this->app['taskTypeTitle']}'");
        
        return $this->app['twig']->render($this->getTemplateName("Tasks::task_detail"));
    }
    
    public function send_task_message_video(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        if (empty($this->postData['taskid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        if (!empty($this->postData['apply']) && $this->postData['apply']!='message') {
            $this->task_state_change();
        }
        if (!empty($this->postData['message'])) {
            $this->db->setTaskMessage($this->uid, $this->postData['recipientID'], $this->postData['taskid'], $this->postData['reply_to'], $this->postData['message']);
        }
        return $this->app->redirect('task-detail-video?id='.$this->postData['taskid']);
        
    }

    public function task_detail_karaoke(){
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        if (empty($this->data['id']) && empty($this->postData['taskid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        
        $task_id = empty($this->data['id']) ? $this->postData['taskid']: $this->data['id'];
        $task = $this->db->getKaraokeTaskChatList($task_id);

        if ($this->app['userlogin'] != 'admin' && ($task[0]['from_usr_id'] != $this->uid)) {
            return $this->app->redirect('tasks-list');
        }

        $this->app['task_num'] = $task_id;

        $keys = $this->getKaraokeTaskDetailInfoFields();
        $values = array();
        $values['type'] = $this->getTaskTitle('karaoke');
        $values['name'] = $task[0]['name'];
        $values['from_usr'] = $task[0]['from_usr'];
        $values['to_usr'] = $task[0]['from_usr'];
        $values['state'] = "<span class='txt-{$this->stateColor[$task[0]['state']]}'>{$this->taskAllState[$task[0]['state']]['title']}</span>";
        
        $this->app['taskTypeTitle'] = $values['type'];
        $this->app['creator'] = $task[0]['from_usr'];
        $this->app['added'] = $task[0]['added'];
        $this->app['toLeft'] = $task[0]['from_usr_id'];
        
        $this->app['infoTable'] = array_combine($keys, $values);
        $this->app['taskAllState'] = $this->taskAllState;
        $this->app['taskStateColor'] = $this->stateColor;
        
        $this->app['taskAll'] = array_map(function($val){$val['state'] = (int)$val['state']; return $val;}, $task);
        $this->app['taskID'] = $task_id;
        $this->app['selfID'] = $this->uid;
        $this->app['recipientID'] = $this->uid;
        $this->app['task_type'] = 'karaoke';
        $tmp = $this->app['taskAll'];
        $last_row = end($tmp);
        
        $this->app['replyTo'] = $last_row['id'];
        $this->app['showForm'] = (!((bool)$last_row['archived']) && ($last_row['state'] != 1  && $last_row['state'] != 2));
        $this->app['showInput'] = FALSE;

        $this->app['breadcrumbs']->addItem($this->setLocalization('Tasks list'), $this->app['controller_alias'] . '/tasks-list');
        $this->app['breadcrumbs']->addItem($this->setLocalization('History of task') . " №{$this->app['task_num']} " . $this->setLocalization('in section') . " '{$this->app['taskTypeTitle']}'");

        return $this->app['twig']->render($this->getTemplateName("Tasks::task_detail"));
    }
    
    public function send_task_message_karaoke(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        if (empty($this->postData['taskid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        if (!empty($this->postData['apply']) && $this->postData['apply']!='message') {
            $this->task_state_change();
        }
        return $this->app->redirect('task-detail-karaoke?id='.$this->postData['taskid']);
        
    }

    //----------------------- ajax method --------------------------------------
    
    public function tasks_list_json($local_uses = FALSE){

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
            'table' => 'moderator_tasks'
        );
        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);
        
        $like_filter = array();

        if (!array_key_exists('filters', $this->data) || !is_array($this->data['filters']) || !array_key_exists('archive', $this->data['filters'])) {
            if (empty($this->data['filters'])) {
                $this->data['filters'] = array('archive' => '4');
            } else {
                $this->data['filters']['archive'] = '4';
            }
        }

        $filter = $this->getTasksFilters($like_filter);
        if (!empty($filter['task_type'])) {
            $response['table'] = $filter['task_type'];
        }
        if (!empty($param['task_type'])) {
            $response['table'] = $param['task_type'];  
        }
        unset($filter['task_type']);
        
        $func = "getFields" . ucfirst($response['table']);
        $filds_for_select = $this->$func($response['table']);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }
        
        if (empty($query_param['like']) && !empty($like_filter)) {
            $query_param['like'] = $like_filter;
        } elseif (!empty($query_param['like']) && !empty($like_filter)) {
            $query_param['like'] = array_merge($query_param['like'], $like_filter);
        }
        
        $query_param['where'] = array_merge($query_param['where'], $filter);
        $query_param['where']['A.id is not '] = NULL;

        $prefix = implode('_', array_map(function($val){ 
            return strtoupper(substr($val, 0, 1));
        }, explode("_", $response['table'])));
        
        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        } else {
            $query_param['select'][] = "A.`id` as `user_id`";
            $query_param['select'][] = "archived_time";
            $query_param['select'][] = "not_readed";
            $query_param['select'][] = "media_id";
            $query_param['select'][] = "media_url";
            $query_param['select'][] = "item_status";
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        $func = "getJoined" . ucfirst($response['table']);
        $query_param['joined'] = $this->$func();

        $func = "getGropBy" . ucfirst($response['table']);
        $query_param['groupby'] = $this->$func();

        $query_param['from'] = "$response[table] as $prefix";

        if ($this->admin->getLogin() != 'admin'){
            if ($response['table']!='karaoke') {
                $query_param['where'][" ($prefix.to_usr = '{$this->admin->getId()}' or M_H.from_usr = '{$this->admin->getId()}') and '1'="]='1';
            } else {
                $query_param['where']["$prefix.add_by"]=$this->admin->getId();
            }
        }

        if (!empty($param['taskid'])) {
            $query_param['where'][end($query_param['groupby'])] = $param['taskid'];
        }

        if (!empty($query_param['like']['COUNT(M_T.`to_usr`)'])) {
            unset($query_param['like']['COUNT(M_T.`to_usr`)']);
        }

        if (!empty($param['media_id'])) {
            $query_param['where'][($response['table'] == 'karaoke' ? 'K.`id`': 'media_id')] = $param['media_id'];
            $response['action'] = 'updateTableRow';
        }

        $response['recordsTotal'] = $this->db->getTotalRowsTasksList($query_param, TRUE);
        $response["recordsFiltered"] = $this->db->getTotalRowsTasksList($query_param);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $dateObj = new \DateTime();
        $master = $response['table'] == 'karaoke' ? new \KaraokeMaster(): new \VideoMaster();

        $response['data'] = array_map(function($val) use($dateObj, $master){
            $val['state'] = (int)$val['state'];
            $val['RowOrder'] = "dTRow_" . $val['id'];

            $dateObj->setTimestamp(strtotime($val['start_time']));
            $val['start_time'] = ($dateObj instanceof \DateTime && (int)$dateObj->getTimestamp() > 0) ? $dateObj->getTimestamp(): 0;

            $dateObj->setTimestamp(strtotime($val['archived_time']));
            $val['archived_time'] = ($dateObj instanceof \DateTime && (int)$dateObj->getTimestamp() > 0) ? $dateObj->getTimestamp(): 0;

            settype($val['media_status'], 'int');
            settype($val['media_accessed'], 'int');
            settype($val['media_url'], 'int');
            settype($val['item_status'], 'int');

            return $val;
        }, $this->db->getTasksList($query_param));

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
    }
    
    public function tasks_report_json($local_uses = FALSE){

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
            'table' => 'moderator_tasks'
        );
        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);

        $like_filter = array();
        $filter = $this->getTasksFilters($like_filter);
        if (!empty($filter['task_type'])) {
            $response['table'] = $filter['task_type'];
        }

        if (array_key_exists('filters', $param)) {
            $param = array_merge($param, $param['filters']);
            unset($param['filters']);
        }

        if (!empty($param['task_type'])) {
            $response['table'] = $param['task_type'];  
        }
        unset($filter['task_type']);

        $func = "getFieldsReport" . ucfirst($response['table']);
        $filds_for_select = $this->$func($response['table']);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        if (empty($query_param['like']) && !empty($like_filter)) {
            $query_param['like'] = $like_filter;
        } elseif (!empty($query_param['like']) && !empty($like_filter)) {
            $query_param['like'] = array_merge($query_param['like'], $like_filter);
        }

        $query_param['where'] = array_merge($query_param['where'], $filter);
        $query_param['where']['A.id is not '] = NULL;

        if ($response['table'] == 'karaoke') {
            $query_param['where']['done'] = 1;
        } else {
            $query_param['where']['ended'] = 1;
        }

        $prefix = implode('_', array_map(function($val){
            return strtoupper(substr($val, 0, 1));
        }, explode("_", $response['table'])));

        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        } else {
            $query_param['select'][] = "A.`id` as `user_id`";
            $query_param['select'][] = "(archived<>0) as `archived`";
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        $func = "getJoinedReport" . ucfirst($response['table']);
        $query_param['joined'] = $this->$func();

        $func = "getGropByReport" . ucfirst($response['table']);
        $query_param['groupby'] = $this->$func();

        $query_param['from'] = "$response[table] as $prefix";

        if ($this->admin->getLogin() != 'admin'){
            if ($response['table']!='karaoke') {
                $query_param['where']["$prefix.to_usr"]=$this->admin->getId();
            } else {
                $query_param['where']["$prefix.add_by"]=$this->admin->getId();
            }
        }

        $response['recordsTotal'] = $this->db->getTotalRowsTasksList($query_param, TRUE);
        $response["recordsFiltered"] = $this->db->getTotalRowsTasksList($query_param);
        
        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $response['videotime'] = $this->getVideoTime($query_param);

        if (empty($query_param['order'])) {
            $query_param['order'] = array('id'=>'desc');
        }

        $response['data'] = array_map(function($val){
            $val['state'] = (int)$val['state']; 
            $val['start_time'] = (int)  strtotime($val['start_time']); 
            $val['end_time'] = (int)  strtotime($val['end_time']);
            $val['RowOrder'] = "dTRow_" . $val['id'];
            return $val;
        }, $this->db->getTasksList($query_param));
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
    }
    
    public function task_state_change() {
        
        if ($this->method != 'POST' || empty($this->postData['taskid']) || empty($this->postData['apply']) || empty($this->postData['task_type'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['taskid'];
        $error = $this->setLocalization('Error');
        
        $func = "changeState".implode('', array_map(function($val){return ucfirst($val);}, explode("_", $this->postData['task_type'])));

        $task_params = $this->postData;

        if ($this->postData['apply'] == 'archived') {
            if (!empty($this->postData['to_date'])){
                $year  = date("Y", strtotime($_GET['to_date']));
                $month = date("n", strtotime($_GET['to_date']));
            }else{
                $year  = date("Y");
                $month = date("n");
                if ($month == 1){
                    $month = 12;
                    $year--;
                }else{
                    $month--;
                }
            }

            $archive_table = (!empty($this->postData['task_type']) && $this->postData['task_type'] == 'karaoke') ? 'karaoke_archive': 'tasks_archive';

            $archive = $this->db->getArchivedTask(array('month' => $month, 'year'  => $year), $archive_table);
            if (!empty($archive)) {
                $task_params['archived'] = $archive['id'];
            } else {
                $task_params['archived'] = $this->db->setArchivedTask(array('date'  => 'NOW()', 'month' => $month, 'year'  => $year), $archive_table);
            }
        }

        $result = call_user_func_array(array($this, $func), array($task_params));
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $data = array_merge_recursive($data, $this->tasks_list_json(TRUE));
            if (empty($data['data'])) {
                $data['action'] = 'deleteTableRow';
            }
        }
        
        $response = $this->generateAjaxResponse($data, $error);

        if ($this->isAjax) {
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $error;
        }
    }
       
    //------------------------ service method ----------------------------------
    
    private function getTaskTitle($param) {
        foreach ($this->taskType as $row) {
            if ($row['id'] == $param) {
                return $row['title'];
            }
        }
        return '';
    }

    private function getDropdownAttribute() {
        return array(
            array('name'=>'id',             'title'=>$this->setLocalization('Number'),          'checked' => TRUE),
            array('name'=>'type',           'title'=>$this->setLocalization('Type'),            'checked' => FALSE),
            array('name'=>'name',           'title'=>$this->setLocalization('Title'),           'checked' => TRUE),
            array('name'=>'media_accessed', 'title'=>$this->setLocalization('Media status'),    'checked' => TRUE),
            array('name'=>'media_status',   'title'=>$this->setLocalization('Media accessed'),  'checked' => TRUE),
            array('name'=>'to_user_name',   'title'=>$this->setLocalization('Assigned to'),     'checked' => TRUE),
            array('name'=>'start_time',     'title'=>$this->setLocalization('Created'),         'checked' => TRUE),
            array('name'=>'messages',       'title'=>$this->setLocalization('Messages'),        'checked' => TRUE),
            array('name'=>'state',          'title'=>$this->setLocalization('State'),           'checked' => TRUE),
            array('name'=>'status',         'title'=>$this->setLocalization('Status'),          'checked' => TRUE),
            array('name'=>'archived',       'title'=>$this->setLocalization('Archive'),         'checked' => TRUE),
            array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),       'checked' => TRUE)
        );
    }
    
    private function getReportDropdownAttribute() {
        return array(
            array('name'=>'id',             'title'=>$this->setLocalization('Number'),      'checked' => TRUE),
            array('name'=>'type',           'title'=>$this->setLocalization('Type'),        'checked' => FALSE),
            array('name'=>'start_time',     'title'=>$this->setLocalization('Created'),     'checked' => TRUE),
            array('name'=>'end_time',       'title'=>$this->setLocalization('Done'),        'checked' => TRUE),
            array('name'=>'name',           'title'=>$this->setLocalization('Title'),       'checked' => TRUE),
            array('name'=>'video_quality',  'title'=>$this->setLocalization('Quality'),     'checked' => TRUE),
            array('name'=>'duration',       'title'=>$this->setLocalization('Duration (min)'),'checked' => TRUE),
            array('name'=>'to_user_name',   'title'=>$this->setLocalization('Assigned to'), 'checked' => TRUE),
            array('name'=>'state',          'title'=>$this->setLocalization('State'),       'checked' => TRUE)
        );
    }
    
    private function getFieldsModerator_tasks($table = ''){
        return array(
            "user_id"       => "A.`id` as `user_id`",
            "id"            => "M_T.`id` as `id`",
            "type"          => "'{$this->getTaskTitle($table)}'as `type`",
            "name"          => "V.`name` as `name`",
            "to_user_name"  => "A.`login` as `to_user_name`",
            "start_time"    => "CAST(M_T.`start_time` as CHAR ) as `start_time`",
            "messages"      => "COUNT(M_T.`to_usr`) as `messages`",
            "not_readed"    => "(SELECT COUNT(*) FROM moderators_history AS M_H_C WHERE M_H_C.`task_id` = M_T.`id` AND M_H_C.`readed` = 0) AS `not_readed`",
            "state"         => "if(ended=0 and archived=0 and (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(start_time))>864000, 3, M_T.`ended` + M_T.rejected) as `state`",
            "status"        => "(`M_T`.`ended` or `M_T`.`rejected`) as `status`",
            "archived"      => "`M_T`.`archived` as `archived`",
            "media_id"      => "V.`id` as `media_id`",
            "item_status"   => "`V`.`status` as `item_status`",
            "media_accessed"=> "`V`.`accessed` as `media_accessed`",
            "media_status"  => "(SELECT COUNT(*) FROM `video_series_files` AS V_S_F WHERE V_S_F.`video_id` = V.`id` AND V_S_F.`file_type` = 'video' AND V_S_F.`protocol` <> 'custom' AND V_S_F.`status` = 1) as `media_status`",
            "media_url"     => "(SELECT COUNT(*) FROM `video_series_files` AS V_S_F WHERE V_S_F.`video_id` = V.`id` AND V_S_F.`file_type` = 'video' AND V_S_F.`protocol` = 'custom' AND V_S_F.`status` = 1) as `media_url`",
            "archived_time" => "`M_T`.`archived_time` as `archived_time`"
        );

    }
    
    private function getFieldsKaraoke($table = ''){
        return array(
            "user_id"       => "A.`id` as `user_id`",
            "id"            => "K.`id` as `id`",
            "type"          => "'{$this->getTaskTitle($table)}'as `type`",
            "name"          => "concat_ws(' - ', K.`singer`, K.`name`) as `name`",
            "to_user_name"  => "A.`login` as `to_user_name`",
            "start_time"    => "CAST(K.`added` as CHAR ) as `start_time`",
            "messages"      => "K.`reason` as `messages`",
            "not_readed"    => "'' as `not_readed`",
            "state"         => "if(returned, 2, if(K.done=0 and K.archived=0 and (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(K.added))>864000, 3, K.done)) as `state`",
            "status"        => "K.`done` as `status`",
            "archived"      => "K.`archived` as `archived`",
            "media_id"      => "K.`id` as `media_id`",
            "item_status"   => "`K`.`status` as `item_status`",
            "media_accessed"=> "`K`.accessed as `media_accessed`",
            "media_status"  => "`K`.`status` as `media_status`",
            "media_url"     => "IF(`K`.`protocol` = 'custom' AND `K`.`rtsp_url` <> '' AND `K`.accessed, 1, 0) as `media_url`",
            "archived_time" => "K.`archived_time` as `archived_time`"
        );
    }
    
    private function getFieldsReportModerator_tasks($table = ''){
        
        $return = $this->getFieldsModerator_tasks($table);
        
        unset($return['messages']);
        
        $return["end_time"] = "CAST(M_T.`end_time` as CHAR ) as `end_time`";
        $return["video_quality"] = "if(V.hd = 0, 'SD', 'HD') as `video_quality`";
        $return["duration"] = "CAST(V.`time` as UNSIGNED) as `duration`";
        $return["archived"] = "(archived<>0) as `archived`";
        
        return $return;
            
    }
    
    private function getFieldsReportKaraoke($table = ''){
        $return = $this->getFieldsKaraoke($table);
        
        unset($return['messages']);
        
        $return["end_time"] = "CAST(K.`done_time` as CHAR ) as `end_time`";
        $return["video_quality"] = "'-' as `video_quality`";
        $return["duration"] = "'-' as `duration`";
        $return["archived"] = "(archived<>0) as `archived`";
        
        return $return;

    }
    
    private function getJoinedModerator_tasks(){
        return array(
            '`administrators` as A'         =>array('left_key'=>'M_T.`to_usr`',     'right_key'=>'A.`id`', 'type'=>'LEFT'),
            '`video` as V'                  =>array('left_key'=>'M_T.`media_id`',   'right_key'=>'V.`id`', 'type'=>'INNER'),
            '`moderators_history` as M_H'   =>array('left_key'=>'M_T.`id`',         'right_key'=>'M_H.`task_id` and M_T.`to_usr` = M_H.`to_usr`', 'type'=>'LEFT')
        );
    }
   
    private function getJoinedKaraoke(){
        return array(
            '`administrators` as A' => array('left_key'=>'K.`add_by`', 'right_key'=>'A.`id`', 'type'=>'LEFT')
        );
    }
    
    private function getJoinedReportModerator_tasks(){
        $return = $this->getJoinedModerator_tasks();
        unset($return['`moderators_history` as M_H']);
        return $return;
    }
    
    private function getJoinedReportKaraoke(){
        return $this->getJoinedKaraoke();
    }
    
    private function getGropByModerator_tasks(){
        return array('M_T.id');
    }
    
    private function getGropByKaraoke(){
        return array('K.id');
    }
    
    private function getGropByReportModerator_tasks(){
        return array();
    }
    
    private function getGropByReportKaraoke(){
        return array();
    }
    
    private function getTasksFilters(&$like_filter) {
        $return = array();

        if (!empty($this->data['filters'])){
            if (array_key_exists('task_type', $this->data['filters'])) {
                $return['task_type'] = $this->data['filters']['task_type'];
            } else {
                $return['task_type'] = 'moderator_tasks';
            }

            if (array_key_exists('state', $this->data['filters']) && !empty($this->data['filters']['state'])) {
                $state = ((int)$this->data['filters']['state']) - 1;
                if ($state != 4) {
                    if ($return['task_type'] == 'karaoke'){
                        if ($state!= 2) {
                            $return["if(done=0 and archived=0 and (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(added))>864000, 3, done + returned)="] = $state;
                        } else {
                            $return["returned"] = 1;
                        }
                    } else{
                        $return["if(ended=0 and archived=0 and (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(start_time))>864000, 3, ended + rejected)="] = $state;
                    }
                } else {
                    $return["archived<>"] = 0;
                }
            }

            if (array_key_exists('status', $this->data['filters']) && !empty($this->data['filters']['status'])) {
                $status = (int)$this->data['filters']['status'] - 1;
                if ($return['task_type'] == 'karaoke') {
                    $return['K.`done`'] = $status;
                } else {
                    $return['(M_T.`ended` or M_T.`rejected`)']  = $status;
                }
            }

            if (array_key_exists('archive', $this->data['filters']) && !empty($this->data['filters']['archive'])) {
                switch ((string)$this->data['filters']['archive']){
                    case '1': {
                        $return["`archived`"] = 0;
                        if ($return['task_type'] == 'karaoke') {
                            $return['K.`done`']     = 0;
                        } else {
                            $return['M_T.`ended`']  = 0;
                        }
                        break;
                    }
                    case '2': {
                        $return["`archived`"] = 0;
                        if ($return['task_type'] == 'karaoke') {
                            $return['K.`done`']     = 1;
                        } else {
                            $return['M_T.`ended`']  = 1;
                        }
                        break;
                    }
                    case '3': {
                        $return["`archived`<>"] = 0;
                        break;
                    }
                    case '4': {
                        $return["`archived`"] = 0;
                        break;
                    }
                }
            }

            if (array_key_exists('video_quality', $this->data['filters']) && !empty($this->data['filters']['video_quality']) && $return['task_type'] == 'moderator_tasks') {
                $return["`hd`"] = ((int)$this->data['filters']['video_quality']) - 1;
            }
            
            if (array_key_exists('interval_from', $this->data['filters']) && $this->data['filters']['interval_from']!= 0) {
                $time_end = (!empty($return['task_type']) && $return['task_type'] == 'karaoke')? 'done_time': 'end_time';
                $date = \DateTime::createFromFormat('d/m/Y', $this->data['filters']['interval_from']);
                $date->modify('today');
                $return["UNIX_TIMESTAMP($time_end)>="] = $date->getTimestamp();
            }
            if (array_key_exists('interval_to', $this->data['filters']) && $this->data['filters']['interval_to']!= 0) {
                $time_end = (!empty($return['task_type']) && $return['task_type'] == 'karaoke')? 'done_time': 'end_time';
                $date = \DateTime::createFromFormat('d/m/Y', $this->data['filters']['interval_to']);
                $date->modify('tomorrow');
                $return["UNIX_TIMESTAMP($time_end)<="] = $date->getTimestamp();
            }
            
            if (array_key_exists('to_user', $this->data['filters']) && !empty($this->data['filters']['to_user'])) {
                $return['A.`id`'] = $this->data['filters']['to_user'];
            }
//            if (array_key_exists('country', $this->data['filters']) && !is_numeric($this->data['filters']['country'])) {
//                $like_filter['country'] = "%" . $this->data['filters']['country'] . "%";
//            }
        } 

        return $return;
    }
    
    private function changeStateKaraoke($param) {
        $task_params = array();

        switch ($param["apply"]) {
            case "ended" : {
                $task_params['done'] = 1;
                $task_params['done_time'] = 'NOW()';
                break;
            }
            case "rejected" : {
                $task_params['reason'] = !empty($param['reason']) ? $param['reason'] : '';
                $task_params['returned'] = 1;
                break;
            }
            case "archived": {
                $task_params['archived'] = $param['archived'];
                $task_params['archived_time'] = 'NOW()';
                break;
            }
        }

        return $this->db->updateSimpleTasks($param['taskid'], 'karaoke', $task_params);
    }
    
    private function changeStateModeratorTasks($param) {

        $text = array('task' => $param['taskid'], 'event' => "task $param[apply]");
        $task = $this->db->getSimpleTasks($param['taskid'], 'moderator_tasks');
        $video = $this->db->getVideoById($task['media_id']);

        if ($param["apply"] == "ended") {
            $moderator_id = $task['to_usr'];

            $task_params['ended'] = 1;
            $task_params['end_time'] = 'NOW()';
            $_SERVER['TARGET'] = 'ADM';
            $master = new \VideoMaster();
            
            ob_start();
            try {
                $master->startMD5SumInAllStorages($video['path']);
            } catch (Exception $exception) {
                
            }
            ob_end_clean();
        } elseif ($param["apply"] == "rejected") {
            $moderator_id = $this->uid;
            $task_params['ended'] = 1;
            $task_params['end_time'] = 'NOW()';
            $task_params['rejected'] = 1;
        } elseif ($param["apply"] == "archived") {
            $moderator_id = $this->uid;
            $task_params['archived'] = $param['archived'];
            $task_params['archived_time'] = 'NOW()';
        }

        $result = $this->db->updateSimpleTasks($param['taskid'], 'moderator_tasks', $task_params);
        if (is_numeric($result)) {
            $this->db->videoLogWrite($video, serialize($text), $moderator_id);
        }
        return $result;
    }

    private function getVideoTaskDetailInfoFields() {
        return array(
            $this->setLocalization('Type'),
            $this->setLocalization('Title'),
            $this->setLocalization('Quality'),
            $this->setLocalization('Created by'),
            $this->setLocalization('Assigned to'),
            $this->setLocalization('State')
        );
    }
    
    private function getKaraokeTaskDetailInfoFields() {
        return array(
            $this->setLocalization('Type'),
            $this->setLocalization('Title'),
            $this->setLocalization('Created by'),
            $this->setLocalization('Assigned to'),
            $this->setLocalization('State')
        );
    }
    
    private function getVideoTime($params){
        if (strpos($params['from'], 'moderator_tasks') !== FALSE) {
            unset($params['select']);
            $params['select'][] = "sum(V.`time`) as `summtime`";
            $params['limit'] = array();
            $result = $this->db->getTasksList($params);
            return $result[0]['summtime'];
        }
        
        return -1;
    }
}
