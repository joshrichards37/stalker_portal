<?php

namespace Model;

class SettingsModel extends \Model\BaseStalkerModel {
    
    public function __construct() {
        parent::__construct();
    }

    public function getCurrentTheme() {
        return $this->mysqlInstance->from('settings')->get()->first('default_template');
    }
    
    public function setCurrentTheme($theme) {
        return $this->mysqlInstance->update('settings', array('default_template' => $theme))->total_rows();
    }
    
    public function getTotalRowsCommonList($where = array(), $like = array()) {
        $params = array(
            /*'select' => array("*"),*/
            'where' => $where,
            'like' => array(),
            'order' => array()
        );
        if (!empty($like)) {
            $params['like'] = $like;
        }
        return $this->getCommonList($params, TRUE);
    }
    
    public function getCommonList($param, $counter = FALSE) {
        if (!empty($param['select'])) {
            $this->mysqlInstance->select($param['select']);
        }
        $this->mysqlInstance->from("image_update_settings as I_U_S")
                ->join('stb_groups as S_G', "I_U_S.stb_group_id", 'S_G.id', 'LEFT')
                ->where($param['where'])->like($param['like'], ' OR ')
                ->orderby($param['order']);

        if (!empty($param['limit']['limit'])) {
            $this->mysqlInstance->limit($param['limit']['limit'], $param['limit']['offset']);
        }

        return ($counter) ? $this->mysqlInstance->count()->get()->counter() : $this->mysqlInstance->get()->all();
    }

    public function updateCommon($param, $where) {
        $where = (is_array($where) ? $where : array('id' => $where));
        return $this->mysqlInstance->update("image_update_settings", $param, $where)->total_rows();
    }

    public function insertCommon($param) {
        return $this->mysqlInstance->insert("image_update_settings", $param)->insert_id();
    }

    public function deleteCommon($param) {
        return $this->mysqlInstance->delete("image_update_settings", $param)->total_rows();
    }

    public function getLauncherTheme($param, $counter = FALSE){

        if (!empty($param['select'])) {
            $this->mysqlInstance->select($param['select']);
        }

        $this->mysqlInstance->from("launcher_apps as L_A")->where(array('type'=>'theme'));

        if (!empty($param['where'])) {
            $this->mysqlInstance->where($param['where']);
        }
        if (!empty($param['like'])) {
            $this->mysqlInstance->like($param['like'], 'OR');
        }
        if (!empty($param['where'])) {
            $this->mysqlInstance->orderby($param['order']);
        }

        if (!empty($param['limit']['limit'])) {
            $this->mysqlInstance->limit($param['limit']['limit'], $param['limit']['offset']);
        }

        return ($counter) ? $this->mysqlInstance->count()->get()->counter() : $this->mysqlInstance->get()->all();
    }
}