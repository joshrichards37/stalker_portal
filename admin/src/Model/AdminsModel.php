<?php

namespace Model;

class AdminsModel extends \Model\BaseStalkerModel {

    public function __construct() {
        parent::__construct();
    }
    
    public function getAdminsTotalRows($where = array(), $like = array()) {
        $params = array(
            'where' => $where,
            'like' => array(),
            'order' => array()
        );
        if (!empty($like)) {
            $params['like'] = $like;
        }
        return $this->getAdminsList($params, TRUE);
    }
    
    public function getAdminsList($param, $counter = FALSE) {
        $where = array();
        if (!empty($this->reseller_id)) {
            $where['A.reseller_id'] = $this->reseller_id;
        }
        if (!empty($param['select'])) {
            $this->mysqlInstance->select($param['select']);
        }
        $this->mysqlInstance->from("administrators as A")
                        ->join("admin_groups as A_G", "A.gid", "A_G.id", "LEFT")
                        ->join("reseller as R", "A.reseller_id", "R.id", "LEFT")
                        ->where($param['where'])
                        ->where($where);
        if (!empty($param['like'])) {
            $this->mysqlInstance->like($param['like'], ' OR ');
        }
        if (!empty($param['order'])) {
            $this->mysqlInstance->orderby($param['order']);
        }
        if (!empty($param['limit']['limit'])) {
            $this->mysqlInstance->limit($param['limit']['limit'], $param['limit']['offset']);
        }
        
        return ($counter) ? $this->mysqlInstance->count()->get()->counter() : $this->mysqlInstance->get()->all();
    }
    
    public function insertAdmin($param){
        if (!empty($this->reseller_id)) {
            $param[0]['reseller_id'] = $this->reseller_id;
        }
        return $this->mysqlInstance->insert('administrators', $param)->insert_id();
    }
    
    public function updateAdmin($param){
        $values = $param[0];
        unset($param[0]);
        return $this->mysqlInstance->update('administrators', $values, $param)->total_rows();
    }
    
    public function deleteAdmin($param){
        return $this->mysqlInstance->delete('administrators', $param)->total_rows();
    }
    
    public function getAdminGropsTotalRows($where = array(), $like = array()) {
        $params = array(
            /*'select' => array("*"),*/
            'where' => $where,
            'like' => array(),
            'order' => array()
        );
        if (!empty($like)) {
            $params['like'] = $like;
        }
        return $this->getAdminGropsList($params, TRUE);
    }
    
    public function getAdminGropsList($param, $counter = FALSE) {
        $where = array();
        if (!empty($param['select'])) {
            $this->mysqlInstance->select($param['select']);
        }
        $this->mysqlInstance->from("admin_groups as A_G")
            ->join("reseller as R", "A_G.reseller_id", "R.id", 'LEFT');

        if (!empty($this->reseller_id)) {
            $where['A_G.reseller_id'] = $this->reseller_id;
        }
        if (!empty($param['where'])) {
            $this->mysqlInstance->where($param['where']);
        }

        $this->mysqlInstance->where($where);

        if (!empty($param['like'])) {
            $this->mysqlInstance->like($param['like'], 'OR');
        }
        if (!empty($param['order'])) {
            $this->mysqlInstance->orderby($param['order']);
        }

        if ($counter === FALSE) {
            $this->mysqlInstance->join("administrators as A", "A_G.id", "A.gid", 'LEFT')->groupby('A_G.id');
        }

        if (!empty($param['limit']['limit'])) {
            $this->mysqlInstance->limit($param['limit']['limit'], $param['limit']['offset']);
        }

 /*       if(!$counter) {
            print_r($this->mysqlInstance->get());exit;
        }*/

        return ($counter) ? $this->mysqlInstance->count()->get()->counter() : $this->mysqlInstance->get()->all();
    }
    
    public function insertAdminsGroup($param){
        if (!empty($this->reseller_id)) {
            $param[0]['reseller_id'] = $this->reseller_id;
        }
        return $this->mysqlInstance->insert('admin_groups', $param)->insert_id();
    }
    
    public function updateAdminsGroup($param){
        return $this->mysqlInstance->update('admin_groups', $param[0], array('id' => $param['id']))->total_rows();
    }
    
    public function deleteAdminsGroup($param){
        return $this->mysqlInstance->delete('admin_groups', $param)->total_rows();
    }
    
    public function getAdminGroupPermissions($gid = NULL) {
        $this->mysqlInstance
            ->from("adm_grp_action_access")
            ->where(array('hidden<>'=>1, 'blocked<>' => 1))
            ->groupby('concat(`controller_name`, `action_name`)')
            ->orderby(array('concat(`controller_name`, `action_name`)'=>'ASC', 'group_id' => 'ASC'));
        if ($gid) {
            $this->mysqlInstance->where(array('group_id = '=>$gid));
        } else {
            $this->mysqlInstance->where(array('ISNULL(`group_id`) OR `group_id` = '=>0));
        }
        return $this->mysqlInstance->get()->all();
    }

    public function setAdminGroupPermissions($param) {
        return $this->mysqlInstance->insert("adm_grp_action_access", $param)->insert_id();
    }
    
    public function deleteAdminGroupPermissions($gid) {
        return $this->mysqlInstance->delete('adm_grp_action_access', array('group_id'=>$gid))->total_rows();
    }

    public function getResellersTotalRows($where = array(), $like = array()) {
        $params = array(
            /*'select' => array("*"),*/
            'where' => $where,
            'like' => array(),
            'order' => array()
        );
        if (!empty($like)) {
            $params['like'] = $like;
        }
        return $this->getResellersList($params, TRUE);
    }

    public function getResellersList($param, $counter = FALSE) {
        if (!empty($param['select'])) {
            $this->mysqlInstance->select($param['select']);
        }
        $this->mysqlInstance->from("reseller as R")
            ->where($param['where']);

        if (!empty($param['like'])) {
            $this->mysqlInstance->like($param['like'], ' OR ');
        }
        if (!empty($param['order'])) {
            $this->mysqlInstance->orderby($param['order']);
        }

        if (!empty($param['limit']['limit'])) {
            $this->mysqlInstance->limit($param['limit']['limit'], $param['limit']['offset']);
        }

        return ($counter) ? $this->mysqlInstance->count()->get()->counter() : $this->mysqlInstance->get()->all();
    }

    public function insertReseller($param){
        $param[0]['created'] = 'NOW()';
        return $this->mysqlInstance->insert('reseller', $param)->insert_id();
    }

    public function updateReseller($param){
        return $this->mysqlInstance->update('reseller', $param[0], array('id' => $param['id']))->total_rows();
    }

    public function deleteReseller($param){
        return $this->mysqlInstance->delete('reseller', $param)->total_rows();
    }

    public function getResellerMember($table_name, $reseller_id){
        if(!empty($reseller_id)){
            $params = array('reseller_id'=>$reseller_id);
        } else {
            $params = array('NOT(`reseller_id`) AND 1' => 1, '`reseller_id`' => NULL);
        }
        return $this->mysqlInstance->from($table_name)->where($params, 'OR ')->count()->get()->counter();
    }

    public function updateResellerMember($table_name, $source_id, $target_id){
        return $this->mysqlInstance->update($table_name, array("reseller_id" => $target_id), array("reseller_id" => $source_id))->total_rows();
    }

    public function updateResellerMemberByID($table_name, $id, $target_id){
        return $this->mysqlInstance->update($table_name, array("reseller_id" => $target_id), array("id" => $id))->total_rows();
    }
}