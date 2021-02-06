<?php

include "./common.php";

use Stalker\Lib\Core\Mysql;

$today_tasks = Mysql::getInstance()->from('video_on_tasks')->where(array('date_on<=' => 'CURDATE()'))->get()->all();

foreach ($today_tasks as $task){
    try{
        Video::switchOnById($task['video_id'], TRUE);
        Mysql::getInstance()->delete('video_on_tasks', array('id' => $task['id']));
    }catch(Exception $e){
        echo $e->getTraceAsString();
    }
}

?>