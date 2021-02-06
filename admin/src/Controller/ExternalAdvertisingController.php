<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use \Stalker\Lib\Core\Advertising;

class ExternalAdvertisingController extends \Controller\BaseStalkerController {

    public function __construct(Application $app) {

        parent::__construct($app, __CLASS__);

    }

    // ------------------- action method ---------------------------------------

    public function index() {

        if (empty($this->app['action_alias'])) {
            return $this->app->redirect($this->app['controller_alias'] . '/company-list');
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function company_list() {

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $curr_company_count = $this->db->getCompanyList(NULL, TRUE);

        if (empty($curr_company_count)) {
            return $this->app->redirect($this->workURL . '/' .$this->app['controller_alias'] . '/company-add');
        }

        if (empty($this->data['filters'])) {
            $this->data['filters'] = array();
        }

        $attribute = $this->getDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function register(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if ($this->method == 'POST' && array_key_exists('form', $this->postData)) {
            $data = $this->postData['form'];
        } else {
            $data = array();
        }

        $form = $this->buildRegisterForm($data);

        if ($this->saveRegisterData($form)) {
            if (!empty($data['submit_type'])) {
                if ($data['submit_type'] == 'skip') {
                    return $this->app->redirect($this->workURL . '/' . $this->app['controller_alias'] . '/settings');
                } else if ($data['submit_type'] == 'save') {
                    try {

                        if ($data['region'] != 'other') {
                            $country_field_name  = ($this->app['language'] == 'ru' ? 'name': 'name_en');
                            $countries = $this->db->getAllFromTable('countries');
                            $countries = array_combine($this->getFieldFromArray($countries, 'iso2'), $this->getFieldFromArray($countries, $country_field_name));

                            $data['region'] = array_key_exists($data['region'], $countries) ? $countries[$data['region']] : $this->setLocalization('undefined');

                        } else {
                            $data['region'] = $data['other_region'];
                        }

                        Advertising::registration($data['name'], $data['email'], $data['phone'], $data['region'] != 'other' ? $data['region']: $data['other_region'], $data['num_users'], $data['website']);
                    } catch (\Exception $e) {

                    }
                }
                $this->app['breadcrumbs']->addItem($this->setLocalization('Congratulations!'));
                return $this->app['twig']->render($this->getTemplateName('ExternalAdvertising::register_confirm'));
            }
        }

        $this->app['form'] = $form->createView();
        $this->app['breadcrumbs']->addItem($this->setLocalization('Register'));

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function company_add() {

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $form = $this->buildCompanyForm();

        if ($this->saveCompanyData($form)){
            return $this->app->redirect($this->workURL . '/' .$this->app['controller_alias'] . '/company-list');
        }

        $this->app['form'] = $form->createView();

        $this->app['breadcrumbs']->addItem($this->setLocalization('List of campaigns'), $this->app['controller_alias'] . '/company-list');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Campaign add'));

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function company_edit() {

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if ($this->method == 'POST' && !empty($this->postData['form']['id'])) {
            $data = $this->postData['form'];
        } else if ($this->method == 'GET' && !empty($this->data['id'])) {
            $data = $this->company_list_json(TRUE);
            $data = !empty($data['data']) ? $data['data'][0]:array();
        }

        if (empty($data)) {
            return $this->app->redirect($this->workURL . '/' .$this->app['controller_alias'] . '/company-add');
        }

        $data[$data['platform']] = array();
        $data[$data['platform']. "_skip"] = array();
        $is_positions = $this->db->getAdPositions($data['id']);
        if (!empty($is_positions)) {
            $data[$data['platform']] = array_combine($this->getFieldFromArray($is_positions, 'position_code'), $this->getFieldFromArray($is_positions, 'blocks'));
            $data[$data['platform']. "_skip"] = array_combine($this->getFieldFromArray($is_positions, 'position_code'), $this->getFieldFromArray($is_positions, 'skip_after'));
        }

        $form = $this->buildCompanyForm($data);

        if ($this->saveCompanyData($form)){
            return $this->app->redirect($this->workURL . '/' .$this->app['controller_alias'] . '/company-list');
        }

        $this->app['form'] = $form->createView();

        $this->app['breadcrumbs']->addItem($this->setLocalization('List of campaigns'), $this->app['controller_alias'] . '/company-list');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Campaign edit') . ': "' . $data['name'] . '"');

        return $this->app['twig']->render($this->getTemplateName("ExternalAdvertising::company_add"));
    }

    public function settings(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if ($this->method == 'POST' && array_key_exists('form', $this->postData)) {
            $data = $this->postData['form'];
        } else {
            $data = $this->db->getSourceList(array(
                'select' => array('E_A_S.id', 'E_A_S.source'),
            ));
            if (!empty($data)) {
                $sources = $this->getFieldFromArray($data, 'source');
                $ids = $this->getFieldFromArray($data, 'id');
                $data = array(
                    'source' => !empty($sources) ? array_combine($ids, $sources) : array(''),
                    'new_source' => array('')
                );
            } else {
                $data = array('new_source' => array(''));
            }
        }

        $form = $this->buildSettingsForm($data);

        if($this->saveSettingsData($form)) {
            return $this->app->redirect($this->workURL . '/' .$this->app['controller_alias'] . '/settings');
        }
        $this->app['form'] = $form->createView();

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function tos() {

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    //----------------------- ajax method --------------------------------------

    public function company_list_json($local_use = FALSE){

        if (!$this->isAjax && $local_use === FALSE) {
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

        $filds_for_select = $this->getCompanyFields();
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        if (!empty($param['id'])) {
            $query_param['where']['E_A_C.`id`'] = $param['id'];
        }

        if (!empty($this->app['reseller'])) {
            $query_param['joined'] = $this->getJoinedCompanyTables();
        }

        $response['recordsTotal'] = $this->db->getCompanyRowsList($query_param, 'ALL');
        $response["recordsFiltered"] = $this->db->getCompanyRowsList($query_param);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $response['data'] = array_map(function($row){
            $row['RowOrder'] = "dTRow_" . $row['id'];
            settype($row['status'], 'int');
            return $row;
        },$this->db->getCompanyList($query_param));

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax && !$local_use) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function toggle_company_state(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['id'];
        $data['data'] = array();
        $error = $this->setLocalization('Failed');

        $result = $this->db->updateCompanyData(array('status' => empty($this->postData['status'])), $this->postData['id']);
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $data = array_merge_recursive($data, $this->company_list_json(TRUE));
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function delete_company(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['id'];
        $error = $this->setLocalization('Failed');

        $result = $this->db->deleteCompanyData($this->postData['id']);
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    //------------------------ service method ----------------------------------

    private function buildRegisterForm(&$data = array()) {

        $builder = $this->app['form.factory'];

        $regions = Advertising::getSupportedCountries();

        $country_field_name  = ($this->app['language'] == 'ru' ? 'name': 'name_en');
        $countries = $this->db->getAllFromTable('countries');

        $countries = array_combine($this->getFieldFromArray($countries, 'iso2'), $this->getFieldFromArray($countries, $country_field_name));

        reset($regions);
        while(list($num, $val) = each($regions)) {
            $regions[$val] = array_key_exists($val, $countries) ? $countries[$val]: '';
            if (is_numeric($num)) {
                unset($regions[$num]);
            }
        }

        $regions = array_filter($regions);

        if (empty($regions)) {
            $regions = array();
        }

        $regions['other'] = $this->setLocalization('Other');

        $form = $builder->createBuilder('form', $data)
            ->add('submit_type', 'hidden')
            ->add('name', 'text')
            ->add('phone', 'text')
            ->add('email', 'text')
            ->add('num_users', 'text')
            ->add('website', 'text')
            ->add('region', 'choice', array(
                    'choices' => $regions,
                    'data' => (empty($data['region']) ? '': $data['region']),
                )
            )
            ->add('other_region', 'text', array(
                    'required'  => (array_key_exists('region', $data) && $data['region'] == 'Other')
                )
            )
            ->add('save', 'submit')
            ->add('skip', 'submit');
        return $form->getForm();
    }

    private function saveRegisterData(&$form) {

        if (!empty($this->method) && $this->method == 'POST') {
            $form->handleRequest($this->request);

            if ($form->isValid()) {
                    return TRUE;
            }
        }
        return FALSE;
    }

    private function buildSettingsForm(&$data = array(), $show = FALSE){

        $this->app['is_show'] = $show;

        $builder = $this->app['form.factory'];

        $form = $builder->createBuilder('form', $data);
        if (!empty($data['source'])) {
            $form->add('source', 'collection', array(
                'entry_type'   => 'text',
                'entry_options'  => array(
                    'attr'      => array('class' => 'form-control', 'data-validation' => 'number')
                )
            ));
        }
        $form->add('new_source', 'collection', array(
            'entry_type'   => 'text',
            'entry_options'  => array(
                'attr' => array('class' => 'form-control', 'data-validation' => 'number', 'data-validation-optional' => "true")
            ),
            'required' => FALSE,
            'allow_add' => TRUE
        ))
            ->add('save', 'submit');
        return $form->getForm();
    }

    private function saveSettingsData(&$form) {

        if (!empty($this->method) && $this->method == 'POST') {
            $form->handleRequest($this->request);
            $data = $form->getData();

            if ($form->isValid()) {
                $curr_fields = $this->db->getTableFields('ext_adv_sources');
                $curr_fields = $this->getFieldFromArray($curr_fields, 'Field');
                $curr_fields = array_flip($curr_fields);

                $old_sources = !empty($data['source']) ? $data['source']: array();
                $new_sources = !empty($data['new_source']) ? $data['new_source']: array();

                $data = array_intersect_key($data, $curr_fields);
                $data['updated'] = 'NOW()';

                $result = 0;
                $params = array(
                    'updated' => 'NOW()'
                );

                foreach($old_sources as $source_id => $source_val) {
                    if (is_numeric($result)) {
                        $params['source'] = $source_val;
                        $result += (!empty($source_val) ? $this->db->updateSourceData($params, $source_id): $this->db->deleteSourceData($source_id));
                    } else {
                        $result = FALSE;
                        break;
                    }
                }

                $params['added'] = 'NOW()';

                foreach($new_sources as $source_val) {
                    if (!empty($source_val)) {
                        $params['source'] = $source_val;
                        if (is_numeric($result) && is_numeric($this->db->insertSourceData($params))) {
                            $result++;
                        } else {
                            $result = FALSE;
                            break;
                        }
                    }
                }

                if (is_numeric($result) && $result > 0) {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    private function buildCompanyForm(&$data = array(), $show = FALSE){

        $this->app['is_show'] = $show;

        $builder = $this->app['form.factory'];

        $sources = $this->db->getSourceList(array(
            'select' => array('E_A_S.id as id', 'E_A_S.source as source')
        ));

        $sources = array_combine($this->getFieldFromArray($sources, 'id'), $this->getFieldFromArray($sources, 'source'));
        $platforms = array(
            'stb' => 'Set-Top Box',
            'ios' => 'iOS',
            'android' => 'Android',
            'smarttv' => 'SmartTV'
        );

        $platform_list = array(
            'stb' => array('101' => array('label' => $this->setLocalization('Classic Launcher'), 'count' => 0), '201' => array('label' => $this->setLocalization('Smart Launcher'), 'count' => 0)),
            'ios' => array('401' => array('label' => $this->setLocalization('iOS'), 'count' => 0)),
            'android' => array('301' => array('label' => $this->setLocalization('Android'), 'count' => 0)),
            'smarttv' => array('501' => array('label' => $this->setLocalization('SmartTV'), 'count' => 0))
        );

        if (array_key_exists('status', $data)) {
            settype($data['status'], 'bool');
        }

        $ad_positions = $this->db->getAllFromTable('ext_adv_positions', 'position_code');
        $parts_labels = array();
        $parts_platform = array();
        foreach($platforms as $platform=>$label) {
            $platform_skip = $platform . "_skip";
            if (!array_key_exists($platform, $parts_platform)) {
                $parts_platform[$platform] = array();
                $parts_platform[$platform_skip] = array();
            }
            if (!array_key_exists($platform, $parts_labels)) {
                $parts_labels[$platform] = array();
            }
            $position_code_prefix = $position_code = '';
            foreach($ad_positions as $row) {
                if (in_array($row['position_code'], array('205', '203', '103'))) {
                    continue;
                }
                if ($position_code_prefix !== $row['position_code'][0]) {
                    $position_code_prefix = $row['position_code'][0];
                    $position_code = $row['position_code'];
                }
                if($row['platform'] == $platform){
                    $parts_labels[$platform][$row['position_code']] = $this->setLocalization($row['label']);
                    $parts_platform[$platform][$row['position_code']] = array_key_exists($platform, $data) && array_key_exists($row['position_code'], $data[$platform]) && $data[$platform][$row['position_code']] ? $data[$platform][$row['position_code']] : '';
                    $parts_platform[$platform_skip][$row['position_code']] = array_key_exists($platform_skip, $data) && array_key_exists($row['position_code'], $data[$platform_skip]) && $data[$platform_skip][$row['position_code']] ? TRUE : FALSE;
                }

                if (array_key_exists($platform, $platform_list) && array_key_exists($position_code, $platform_list[$platform])) {
                    $platform_list[$platform][$position_code]['count']++;
                    $platform_list[$platform][$position_code]['prefix'] = $position_code_prefix;
                }
            }
            ksort($parts_platform[$platform]);
        }

        $data = array_merge($data, $parts_platform);

        $this->app['platform_list'] = $platform_list;

        $form = $builder->createBuilder('form', $data)
            ->add('id', 'hidden')
            ->add('name', 'text', array(
                'attr'      => array('class' => 'form-control', 'data-validation' => 'required'),
                'required' => TRUE
            ))
            ->add('source', 'choice', array(
                    'choices' => $sources,
                    'required' => TRUE,
                    'constraints' => array(
                        new Assert\Choice(array('choices' => array_keys($sources)))
                    ),
                    'attr' => array('readonly' => $show, 'disabled' => $show, 'class' => 'populate placeholder', 'data-validation' => 'required'),
                    'data' => (empty($data['source']) ? '': $data['source']),
                )
            )
            ->add('platform', 'choice', array(
                    'choices' => $platforms,
                    'required' => TRUE,
                    'attr' => array('readonly' => $show, 'disabled' => $show, 'class' => 'populate placeholder', 'data-validation' => 'required'),
                    'data' => (empty($data['platform']) ? 'stb': $data['platform']),
                )
            )
            ->add('status', 'checkbox', array(
                    'label' => ' ',
                    'required' => FALSE,
                    'label_attr' => array('class'=> 'label-success'),
                    'attr' => array('readonly' => $show, 'disabled' => $show, 'class' => 'form-control'),
                )
            )
            ->add('save', 'submit');
        $block_val = array_combine(range(1, 5), range(1, 5));
        foreach($platforms as $p_key => $p_label){
            $form->add($p_key, 'collection', array(
                'type' => 'choice',
                'options' => array(
                    'required' => TRUE,
                    'label' => $parts_labels[$p_key],
                    'choices'  => $block_val,
                    'label_attr' => array('class' => 'control-label')
                ),
                'required' => TRUE,
                'allow_add' => TRUE,
                'allow_delete' => TRUE,
                'prototype' => FALSE
            ))->add($p_key . "_skip", 'collection', array(
                'type' => 'checkbox',
                'options' => array(
                    'required' => FALSE,
                ),
                'required' => FALSE,
                'allow_add' => TRUE,
                'allow_delete' => TRUE,
                'prototype' => FALSE
            ));
        }
        return $form->getForm();
    }

    private function saveCompanyData(&$form) {
        if (!empty($this->method) && $this->method == 'POST') {
            $form->handleRequest($this->request);
            $data = $form->getData();
            if ($form->isValid()) {
                $skip_field = array();
                if (array_key_exists('form', $this->postData) && !empty($this->postData['form'][$data['platform'] . '_skip'])) {
                    $skip_field = $this->postData['form'][$data['platform'] . '_skip'];
                }
                $get_positions = array();
                foreach( array( 'stb', 'ios', 'android', 'smarttv') as $platform){
                    if (array_key_exists($platform, $data)) {
                        $get_positions += $data[$platform];
                    }
                }

                $get_positions = array_filter($get_positions);

                if (!empty($data['id'])) {
                    $is_positions = $this->db->getAdPositions($data['id']);
                    if (!empty($is_positions)) {
                        $del_position = array();
                        if (!empty($get_positions)) {
                            while(list($num, $row) = each($is_positions)){
                                if (!array_key_exists($row['position_code'], $get_positions) || $get_positions[$row['position_code']] != $row['blocks'] || !array_key_exists($row['position_code'], $skip_field) || $skip_field[$row['position_code']] != $row['skip_after']) {
                                    $del_position[] = $row['position_code'];
                                } else {
                                    unset($get_positions[$row['position_code']]);
                                }
                            }
                        } else {
                            $del_position = $this->getFieldFromArray($is_positions, 'position_code');
                        }
                        if (!empty($del_position)){
                            $this->db->delAdPositions($data['id'], $del_position);
                        }
                    }
                }
                $curr_fields = $this->db->getTableFields('ext_adv_campaigns');
                $curr_fields = $this->getFieldFromArray($curr_fields, 'Field');
                $curr_fields = array_flip($curr_fields);

                $data = array_intersect_key($data, $curr_fields);
                $data['updated'] = 'NOW()';

                if (!empty($data['id'])) {
                    $operation = 'update';
                    $id = $data['id'];
                    unset($data['id']);
                    $params = array($data, $id);
                } else {
                    $operation = 'insert';
                    $data['added'] = 'NOW()';
                    $params = array($data);
                }

                $result = call_user_func_array(array($this->db, $operation.'CompanyData'), $params);

                if (is_numeric($result)) {
                    if (!empty($get_positions)) {
                        $this->db->addAdPositions($operation == 'update' ? $id: $result, $get_positions, $skip_field);
                    }
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    private function getDropdownAttribute(){
        $attribute = array(
            array('name' => 'id',           'title' => $this->setLocalization('ID'),        'checked' => TRUE),
            array('name' => 'name',         'title' => $this->setLocalization('Title'),     'checked' => TRUE),
            array('name' => 'platform',     'title' => $this->setLocalization('Platform'),  'checked' => TRUE),
            array('name' => 'status',       'title' => $this->setLocalization('Status'),    'checked' => TRUE),
            array('name' => 'operations',   'title' => $this->setLocalization('Operations'),'checked' => TRUE)
        );
        return $attribute;
    }

    private function getJoinedCompanyTables(){
        return array(
            'ext_adv_sources as E_A_S' => array('left_key' => 'E_A_C.source', 'right_key' => 'E_A_S.id', 'type' => 'LEFT'),
        );
    }

    private function getCompanyFields(){
        return array(
            "id" => "E_A_C.`id` as `id`",
            "name" => "E_A_C.`name` as `name`",
            "source" => "E_A_C.`source` as `source`",
            "platform" => "E_A_C.`platform` as `platform`",
            "status" => "E_A_C.`status` as `status`"
        );
    }
}