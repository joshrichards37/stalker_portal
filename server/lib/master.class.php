<?php

use Stalker\Lib\Core\Mysql;
use Stalker\Lib\Core\Stb;
use Stalker\Lib\Core\Config;
use Stalker\Lib\Core\Cache;

/**
 * Master for storages.
 * 
 * @package stalker_portal
 * @author zhurbitsky@gmail.com
 */

abstract class Master
{
    protected $storages;
    private $moderator_storages;
    protected $clients;
    protected $stb;
    protected $media_id;
    protected $media_name;
    protected $media_path;
    private $from_cache;
    private $cache_expire_h = 365;
    protected $db;
    protected $media_type;
    protected $media_protocol;
    protected $media_params;
    protected $rtsp_url;
    protected $db_table;
    protected $stb_storages;
    protected $is_file = false;

    public function __construct(){
        $this->db = Mysql::getInstance();
        $this->stb = Stb::getInstance();
        $this->storages = $this->getAllActiveStorages();
        $this->moderator_storages = $this->getModeratorStorages();
        $this->clients = $this->getClients();
        $this->cache_expire_h = Config::get('master_cache_expire');
        $this->stb_storages = $this->getStoragesForStb();
    }
    
    /**
     * Trying to create a link of media file in stb home directory
     *
     * @param int $media_id
     * @param int $series_num
     * @param bool $from_cache
     * @param string $forced_storage
     * @param int $file_id
     * @return array contains path to media or error
     */
    public function play($media_id, $series_num = 0, $from_cache = true, $forced_storage = "", $file_id = 0){
        
        $this->initMedia($media_id, $file_id);
        
        $res = array(
            'id'         => 0,
            'cmd'        => '',
            'storage_id' => '',
            'load'       => '',
            'error'      => ''
        );

        if (!empty($this->rtsp_url) && (!$file_id || $this->is_file)){
            
            $res['id']  = $this->media_id;
            $res['cmd'] = $this->rtsp_url;
            
            return $res;
        }

        if (!empty($forced_storage)){
            $from_cache = false;
        }
        
        $good_storages = $this->getAllGoodStoragesForMedia($this->media_id, $file_id, !$from_cache);

        if (!empty($forced_storage)){
            if (array_key_exists($forced_storage, $good_storages)){
                $good_storages = array($forced_storage => $good_storages[$forced_storage]);
            }else{
                $good_storages = array();
            }
        }

        $default_error = 'nothing_to_play';

        foreach ($good_storages as $name => $storage){
            if ($storage['load'] < 1){

                if ($file_id){
                    $file = Video::getFileById($file_id);
                    $file = $file['file_name'];
                }elseif ($series_num > 0){

                    $file = $storage['series_file'][array_search($series_num, $storage['series'])];

                }else{
                    $file = $storage['first_media'];
                }

                preg_match("/([\S\s]+)\.([\S]+)$/", $file, $arr);
                $ext = $arr[2];

                if ($this->storages[$name]['external'] == 0){

                    try {
                        $this->clients[$name]->resource($this->media_type)->create(array('media_name' => $this->getMediaPath($file, $file_id), 'media_id' => $this->media_id, 'proto' => $this->media_protocol));
                    }catch (Exception $exception){
                        $default_error = 'link_fault';
                        $this->parseException($exception);

                        if (($exception instanceof RESTClientException) && !($exception instanceof RESTClientRemoteError)){
                            $storage = new Storage(array('name' => $name));
                            $storage->markAsFailed($exception->getMessage());
                            continue;
                        }

                        if ($this->from_cache){

                            return $this->play($media_id, $series_num, false, '', $file_id);
                        }else{
                            continue;
                        }
                    }

                    if ($this->media_protocol == 'http' || $this->media_type == 'remote_pvr'){
                        if (Config::exist('nfs_proxy')){
                            $base_path = 'http://'.Config::get('nfs_proxy').'/media/'.$name.'/'.RESTClient::$from.'/';
                        }elseif ($this->storages[$name]['stream_server_type'] == 'wowza'){
                            $base_path = 'http://'.$this->storages[$name]['storage_ip'].':'.$this->storages[$name]['stream_server_port'].'/'.$this->storages[$name]['stream_server_app'].'/_definst_/mp4:'.$this->getMediaPath($file, $file_id).'/';
                        }elseif ($this->storages[$name]['stream_server_type'] == 'flussonic'){
                            $base_path = 'http://'.$this->storages[$name]['storage_ip'].':'.$this->storages[$name]['stream_server_port'].'/'.$this->storages[$name]['stream_server_app'].'/'.$this->getMediaPath($file, $file_id).'/';
                        }else{
                            $base_path = 'http://'.$this->storages[$name]['storage_ip'].'/media/'.$name.'/'.RESTClient::$from.'/';
                        }
                    }else{
                        $base_path = '/media/'.$name.'/';
                    }

                    if (strpos($base_path, 'http://') !== false){
                        $res['cmd'] = 'ffmpeg ';
                    }else{
                        $res['cmd'] = 'auto ';
                    }

                    if ($this->storages[$name]['stream_server_type'] == 'wowza'){
                        $res['cmd'] .= $base_path.'playlist.m3u8?token='.self::createTemporaryLink("1");
                    }elseif($this->storages[$name]['stream_server_type'] == 'flussonic'){
                        $res['cmd'] .= $base_path.'index.m3u8?token='.self::createTemporaryLink($this->stb->id);
                    }else{
                        $res['cmd'] .= $base_path.$this->media_id.'.'.$ext;

                        // nginx secure link
                        $secret = Config::get('nginx_secure_link_secret');

                        if(preg_match('/http(s)?:\/\/([^\/]+)\/(.+)$/', $res['cmd'], $match)){
                            $uri = '/'.$match[3];
                        }else{
                            $uri = '';
                        }

                        $remote_addr = $this->stb->ip;
                        $expire = time() + Config::getSafe('vclub_nginx_tmp_link_ttl', 7200);

                        $hash = base64_encode(md5($secret.$uri.$remote_addr.$expire, true));

                        $hash = strtr($hash, '+/', '-_');
                        $hash = str_replace('=', '', $hash);

                        $res['cmd'] .= '?st='.$hash.'&e='.$expire;
                    }

                    $file_info = array_filter($storage['files'], function($info) use ($file){
                        $info_name = explode('/', $info['name']);
                        return end($info_name) == $file;
                    });

                    if (empty($file_info) && !empty($series_num) && !empty($storage['tv_series']) && !empty($file_id)) {
                        $file_rec = Video::getFileById($file_id);
                        if (!empty($file_rec['series_id'])) {
                            $episode = Video::getEpisodeById($file_rec['series_id']);
                            $season = Video::getSeasonById($episode['season_id']);

                            $file_info = array_filter($storage['tv_series']['seasons'][$season['season_number']]['episodes'][$episode['series_number']], function($info) use ($file){
                                $info_name = explode('/', $info['name']);
                                return end($info_name) == $file;
                            });
                        }
                    }

                    $file_info = array_values($file_info);

                    if (!empty($file_info) && !empty($file_info[0]['subtitles'])){
                        $ip = $this->stb->ip;
                        $res['subtitles'] = array_map(function($subtitle) use ($base_path, $file, $ip){

                            $file_base = substr($file, 0, strrpos($file, '.'));

                            $lang = substr($subtitle, strlen($file_base), strrpos($subtitle, '.') - strlen($file_base));

                            if ($lang && ($lang{0} == '_' || $lang{0} == '.')){
                                $lang = substr($lang, 1);
                            }

                            $file = $base_path.$subtitle;

                            // nginx secure link
                            $secret = Config::get('nginx_secure_link_secret');

                            if(preg_match('/http(s)?:\/\/([^\/]+)\/(.+)$/', $file, $match)){
                                $uri = '/'.$match[3];
                            }else{
                                $uri = '';
                            }

                            $remote_addr = $ip;
                            $expire = time() + Config::getSafe('vclub_nginx_tmp_link_ttl', 7200);

                            $hash = base64_encode(md5($secret.$uri.$remote_addr.$expire, true));

                            $hash = strtr($hash, '+/', '-_');
                            $hash = str_replace('=', '', $hash);

                            return array(
                                'file' => $file.'?st='.$hash.'&e='.$expire,
                                'lang' => $lang
                            );
                        }, $file_info[0]['subtitles']);
                    }

                }else{
                    $redirect_url = '/media/'.$this->getMediaPath($file, $file_id);

                    $link_result = self::createTemporaryLink($redirect_url);

                    var_dump($redirect_url, $link_result);

                    if (!$link_result){
                        $default_error = 'link_fault';

                        if ($this->from_cache){

                            return $this->play($media_id, $series_num, false, '', $file_id);
                        }else{
                            continue;
                        }
                    }else{
                        $res['cmd']      = 'ffmpeg http://'.$this->storages[$name]['storage_ip'].'/get/'.$link_result;
                        $res['external'] = 1;
                    }
                }

                //$res['cmd'] = 'auto /media/'.$name.'/'.$this->media_id.'.'.$ext;
                $res['id']   = $this->media_id;
                $res['load'] = $storage['load'];
                $res['storage_id'] = $this->storages[$name]['id'];
                $res['from_cache'] = $this->from_cache;
                return $res;
            }else{
                $this->incrementStorageDeny($name);
                $res['error'] = 'limit';
                return $res;
            }
        }
        
        if ($this->from_cache){
            
            return $this->play($media_id, $series_num, false, '', $file_id);
        }else{
            $res['error'] = $default_error;
            return $res;
        }
    }

    public static function createTemporaryLink($val){

        $key = md5($val.microtime(1).uniqid());

        $cache = Cache::getInstance();

        $result = $cache->set($key, $val, 0, Config::getSafe('vclub_tmp_link_ttl', 5));

        if ($result){
            return $key;
        }else{
            return $result;
        }
    }

    public static function checkTemporaryLink($key){

        return Cache::getInstance()->get($key);
    }
    
    public static function delTemporaryLink($key){

        return Cache::getInstance()->del($key);
    }

    /**
     * Wrapper for storage method, that creates directory for media my name
     *
     * @param string $media_name
     * @param string $extending_name default = ''
     * @throws MasterException
     */
    public function createMediaDir($media_name, $extending_name = ''){
        if (!empty($extending_name)) {
            $media_name .= ("_" . ((string)$extending_name));
        }
        foreach ($this->storages as $name => $storage){
            try {
                $this->clients[$name]->resource($this->media_type)->update(array('media_name' => $media_name));
            }catch (Exception $exception){
                $this->parseException($exception);
                throw new MasterException($exception->getMessage(), $name);
            }
        }
    }
    
    /**
     * Check stb home directory on all active storages
     *
     */
    /*public function checkAllHomeDirs(){
        foreach ($this->storages as $name => $storage){
            $this->checkHomeDir($name);
        }
    }*/
    
    /**
     * Return active storages array
     *
     * @return array active storages
     */
    public function getStoragesForStb(){
        
        //return $this->storages;
        
        $storages = array();
        
        $where = array('status' => 1);
        
        if (!$this->stb->isModerator()){
            $where['for_moderator'] = 0;
        }
        
        $data = $this->db->from('storages')->where($where)->get()->all();
        
        foreach ($data as $idx => $storage){
            $storages[$storage['storage_name']] = $storage;
        }
        return $storages;
        
    }

    public function getStorageList(){
        return $this->storages;
    }
    
    /**
     * Return moderators storages
     *
     * @return array storages names
     */
    public function getModeratorStorages(){
        
        $data = $this->db->from('storages')->where(array('status' => 1, 'for_moderator' => 1))->get()->all();
        
        $storages = array();
        
        foreach ($data as $idx => $storage){
            $storages[$storage['storage_name']] = $storage;
        }
        return $storages;
    }
    
    /**
     * Set media_id and media_name properties
     *
     * @param int $media_id
     * @param int $file_id
     */
    private function initMedia($media_id, $file_id){

        if (empty($this->media_id)){
            $this->media_id = $media_id;
        }
        
        if (empty($this->media_params)){
            $this->media_params = $this->getMediaParams($this->media_id, $file_id);
        }
        
        if (empty($this->media_name)){
            $this->media_name = $this->getMediaName();
        }
    }
    
    /**
     * Get from database all active storages
     *
     * @return array active storages
     */
    protected function getAllActiveStorages(){
        
        $storages = array();
        
        $data = $this->db->from('storages')->where(array('status' => 1, 'for_simple_storage' => 1))->get()->all();
        
        foreach ($data as $idx => $storage){
            $storages[$storage['storage_name']] = $storage;
        }
        return $storages;
    }
    
    /**
     * Wrapper for srorage method, that check stb home directory
     *
     * @param string $storage_name
     */
    /*private function checkHomeDir($storage_name){
        
        try {
            $this->clients[$storage_name]->checkHomeDir($this->stb->mac);
        }catch (Exception $exception){
            $this->parseException($exception);
        }
    }*/
    
    /**
     * Get all good storages for media by id from cache(if they valid), or from network
     *
     * @param int $media_id
     * @param int $file_id
     * @param boolean $force_net
     * @return array good storages, sorted by load
     */
    private function getAllGoodStoragesForMedia($media_id, $file_id, $force_net = false){
        
        $cache = array();
        
        $this->initMedia($media_id, $file_id);
        
        if ($this->stb->isModerator()){
            $good_storages = $this->getAllGoodStoragesForMediaFromNet($this->media_name, $file_id);
            $good_storages = $this->sortByLoad($good_storages);
            return $good_storages;
        }
        
        if (!$force_net){
            $cache = $this->getAllGoodStoragesForMediaFromCache();
        }
        
        if (!empty($cache)){
            $good_storages = $cache;
            $this->from_cache = true;
        }else{
            $good_storages = $this->getAllGoodStoragesForMediaFromNet($this->media_name, $file_id);
            $this->from_cache = false;
        }

        $good_storages = $this->sortByLoad($good_storages);

        if (User::isInitialized()){

            $user_agent = User::getUserAgent();

            $filtered_good_storages = array();

            foreach ($good_storages as $storage_name => $storage){
                if ($this->storages[$storage_name]['user_agent_filter'] == '' || preg_match("/".$this->storages[$storage_name]['user_agent_filter']."/", $user_agent)){
                    $filtered_good_storages[$storage_name] = $storage;
                }
            }

            $good_storages = $filtered_good_storages;
        }
        
        return $good_storages;
    }
    
    /**
     * Get all good for media by id interviewing all good storages
     *
     * @param int $media_id
     * @param int $file_id
     * @param bool $force_moderator default = false
     * @return array good storages from net
     */
    public function getAllGoodStoragesForMediaFromNet($media_id, $file_id, $force_moderator = false){
        
        $this->initMedia($media_id, $file_id);
        
        $good_storages = array();
        
        if ($this->stb->isModerator() || $force_moderator){
            $storages = $this->storages;
        }else{
            $storages = array_diff_key($this->storages, $this->moderator_storages);
        }
        
        foreach ($storages as $name => $storage){
            
            $raw = $this->checkMediaDir($name, $this->media_name);

            if (!$raw){
                continue;
            }

            if (count($raw['files']) > 0){
                
                $raw['first_media'] = $raw['files'][0]['name'];

                if (!$file_id){
                    $this->saveSeries($raw['series']);
                }


                $raw['load'] = $this->getStorageLoad($storage);

                $raw['for_moderator'] = $storage['for_moderator'];

                $good_storages[$name] = $raw;
                
            }elseif(!empty($raw['tv_series'])){

                $raw['load'] = $this->getStorageLoad($storage);

                $raw['for_moderator'] = $storage['for_moderator'];

                $good_storages[$name] = $raw;
            }

            $raw['tv_series'] = isset($storage['tv_series']) ? $storage['tv_series'] : array();

        }
        $this->checkMD5Sum($good_storages);
        
        if (!$this->stb->isModerator()){
            $this->setStorageCache($good_storages);
        }

        if (method_exists($this, 'setStatus')){

            $status = intval($good_storages);

            if ($status == 1 && !array_diff_key($good_storages, $this->moderator_storages)){
                $status = 3;
            }

            $this->setStatus($status, $file_id);
        }
        
        return $good_storages;
    }
    
    /**
     * Start md5sum for media in all storages
     *
     * @param string $media_name
     */
    public function startMD5SumInAllStorages($media_name){
        foreach ($this->storages as $name => $storage){
            try {
                $this->startMD5Sum($name, $media_name);
            }catch (Exception $exception){
                
            }
        }
    }
    
    /**
     * wrapper for srorage method, that start md5sum for media
     *
     * @param string $storage_name
     * @param string $media_name
     */
    public function startMD5Sum($storage_name, $media_name){
        try {
            //$this->clients[$storage_name]->startMD5Sum($media_name);
            $this->clients[$storage_name]->resource($this->media_type.'_md5_checker')->create(array('media_name' => $media_name));
        }catch (Exception $exception){
            $this->parseException($exception);
            throw new Exception($exception->getMessage());
        }
    }
    
    /**
     * Wrapper for srorage method, that abort md5sum for media
     *
     * @param string $storage_name
     * @param string $media_name
     */
    public function stopMD5Sum($storage_name, $media_name){
        try {
            //$this->clients[$storage_name]->stopMD5Sum($media_name);
            $this->clients[$storage_name]->resource($this->media_type.'_md5_checker')->ids($media_name)->delete();
        }catch (Exception $exception){
            $this->parseException($exception);
        }
    }
    
    /**
     * Compare md5sum from net and from cache. If they don't equal - add record to master log
     *
     * @param array $storages_from_net
     */
    private function checkMD5Sum($storages_from_net){
        $storages_from_cache = $this->getAllGoodStoragesForMediaFromCache();
        foreach ($storages_from_net as $name => $storage){
            if (array_key_exists($name, $storages_from_cache)){
                foreach ($storages_from_net[$name]['files'] as $net_file){
                    foreach ($storages_from_cache[$name]['files'] as $cache_file){
                        if (($cache_file['name'] == $net_file['name']) && ($cache_file['md5'] != $net_file['md5']) && !empty($net_file['md5']) && !empty($cache_file['md5'])){
                            $this->addToLog('File '.$cache_file['name'].' in '.$this->media_name.' on '.$name.' changed '.$cache_file['md5'].' => '.$net_file['md5']);
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Add record to master log
     *
     * @param string $txt
     */
    private function addToLog($txt){

        $this->db->insert('master_log',
                          array(
                              'log_txt' => trim($txt),
                              'added'   => 'NOW()'
                          ));
        
    }
    
    /**
     * Get good storages from cache
     *
     * @return array good storages from cache
     */
    private function getAllGoodStoragesForMediaFromCache(){
        
        $cache = array();
        
        foreach ($this->getAllCacheKeys() as $key){
        
            $storage_cache = $this->db->from('storage_cache')
                                      ->where(array(
                                          'cache_key' => $key,
                                          'status'    => 1,
                                          'changed>'  => date(Mysql::DATETIME_FORMAT, time() - $this->cache_expire_h*3600)
                                      ))
                                      ->get()
                                      ->all();
            
            if(!empty($storage_cache)){
                $storage_cache = $storage_cache[0];
                $storage_data = unserialize($storage_cache['storage_data']);
                if (is_array($storage_data) && !empty($storage_data) && !empty($this->stb_storages[$storage_cache['storage_name']])){
                    $cache[$storage_cache['storage_name']] = $storage_data;
                    $cache[$storage_cache['storage_name']]['load'] = $this->getStorageLoad($this->storages[$storage_cache['storage_name']]);
                }
            }
            
        }
        return $cache;
    }
    
    /**
     * Set storage cache
     *
     * @param array $storages
     */
    private function setStorageCache($storages){
        
        $this->db->update('storage_cache',
                                  array(
                                      'status'  => 0,
                                      'changed' => '0000-00-00 00:00:00',
                                  ),
                                  array(
                                      'media_id'   => $this->media_id,
                                      'media_type' => $this->media_type,
                                  ));
        
        if (!empty($storages)){
            
            foreach ($storages as $name => $data){
                
                $storage_data = serialize($data);
                
                $cache_key = $this->getCacheKey($name);
                
                $record = $this->db->from('storage_cache')
                                   ->where(array('cache_key' => $cache_key))
                                   ->get()
                                   ->first();

                if (empty($record)){
                    
                    $this->db->insert('storage_cache',
                                      array(
                                          'cache_key'    => $cache_key,
                                          'media_type'   => $this->media_type,
                                          'media_id'     => $this->media_id,
                                          'storage_name' => $name,
                                          'storage_data' => $storage_data,
                                          'status'       => 1,
                                          'changed'      => 'NOW()'
                                      ));
                }else{
                    
                    $this->db->update('storage_cache',
                                      array(
                                          'storage_data' => $storage_data,
                                          'status'       => 1,
                                          'changed'      => 'NOW()',
                                      ),
                                      array('cache_key' => $cache_key));
                    
                }
            }
        }
    }
    
    /**
     * Return unique key for cache record by storage name, media type and media id
     *
     * @param string $storage_name
     * @return string unique key for cache record
     */
    private function getCacheKey($storage_name){
        return $storage_name.'_'.$this->media_type.'_'.$this->media_id;
    }
    
    /**
     * Return all keys for cache records for media
     *
     * @return array all cache keys for media
     */
    private function getAllCacheKeys(){
        $keys = array();
        foreach ($this->storages as $name => $storage){
            $keys[] = $this->getCacheKey($name);
        }
        return $keys;
    }
    
    /**
     * wrapper for srorage method, that check media directory
     *
     * @param string $storage_name
     * @param string $media_name
     * @return array content of media directory
     */
    protected function checkMediaDir($storage_name, $media_name){
        try {
            //return $this->clients[$storage_name]->checkDir($media_name, $this->media_type);
            return $this->clients[$storage_name]->resource($this->media_type)->ids($media_name)->get();
        }catch (Exception $exception){
            $this->parseException($exception);

            /*if ($exception instanceof RESTClientException){
                $storage = new Storage(array('name' => $storage_name));
                $storage->markAsFailed($exception->getMessage());
            }*/
            return false;
        }
    }
    
    /**
     * Calculates storage load
     *
     * @param array $storage_name
     * @return int storage load
     */
    protected function getStorageLoad($storage){
        if ($storage['max_online'] > 0){
            return $this->getStorageOnline($storage['storage_name']) / $storage['max_online'];
        }
        return 1;
    }

    public static function getStorageByName($name){

        return Mysql::getInstance()->from('storages')->where(array('storage_name' => $name))->get()->first();
    }

    public static function getStorageById($id){

        return Mysql::getInstance()->from('storages')->where(array('id' => $id))->get()->first();
    }
    
    /**
     * Return online sessions on storage
     *
     * @param string $storage_name
     * @return int sessions online
     */
    protected function getStorageOnline($storage_name){
        
        $vclub_sd_sessions = $this->db->select('count(*) as sd_online')
                              ->from('users')
                              ->where(
                                  array(
                                      'now_playing_type' => 2,
                                      'hd_content'       => 0,
                                      'storage_name'     => $storage_name,
                                      'keep_alive>'      => date(Mysql::DATETIME_FORMAT, time() - Config::get('watchdog_timeout')*2)
                                  ))
                              ->get()
                              ->first('sd_online');

        $vclub_hd_sessions = $this->db->select('count(*) as hd_online')
                              ->from('users')
                              ->where(
                                  array(
                                      'now_playing_type' => 2,
                                      'hd_content'       => 1,
                                      'storage_name'     => $storage_name,
                                      'keep_alive>'      => date(Mysql::DATETIME_FORMAT, time() - Config::get('watchdog_timeout')*2)
                                  ))
                              ->get()
                              ->first('hd_online');

        $pvr_rec_sessions = Mysql::getInstance()->from('rec_files')->where(array('storage_name' => $storage_name, 'ended' => 0))->get()->count();

        $archive_rec_sessions = Mysql::getInstance()->from('tv_archive')->where(array('storage_name' => $storage_name))->get()->count();

        $archive_sessions = $this->db->select('count(*) as archive_sessions')
                                ->from('users')
                                    ->where(
                                    array(
                                        'now_playing_type' => 11,
                                        'hd_content'       => 0,
                                        'storage_name'     => $storage_name,
                                        'keep_alive>'      => date(Mysql::DATETIME_FORMAT, time() - Config::get('watchdog_timeout')*2)
                                    ))
                                    ->get()
                                    ->first('archive_sessions');

        return $vclub_sd_sessions + 3*$vclub_hd_sessions + $pvr_rec_sessions + $archive_rec_sessions + $archive_sessions;
    }
    
    /**
     * Return media name
     *
     * @return string
     */
    abstract protected function getMediaName();

    /**
     * Return media path
     *
     * @param string $file_name
     * @return string
     */
    protected function getMediaPath($file_name, $file_id){
        return $this->media_name;
    }
    
    /**
     * Return media params from db
     *
     * @param int $media_id
     * @param int $file_id
     * @return array
     */
    protected function getMediaParams($media_id, $file_id){
        
        $media_params = $this->db->from($this->db_table)
                                  ->where(array('id' => $media_id))
                                  ->get()
                                  ->first();

        $file = Video::getFileById($file_id);

        if (!empty($file)){
            if (!empty($file['url']) && $file['protocol'] != 'http'){
                $this->rtsp_url = $file['url'];
            }

            if (!empty($file['protocol'])){
                $this->media_protocol = $file['protocol'];
            }

            $this->is_file = true;
        }else{
            if (!empty($media_params['rtsp_url'])){
                $this->rtsp_url = $media_params['rtsp_url'];
            }

            if (!empty($media_params['protocol'])){
                $this->media_protocol = $media_params['protocol'];
            }
        }
        
        return $media_params;
    }
    
    /**
     * Increment counter of storage deny
     *
     * @param string $storage_name
     */
    protected function incrementStorageDeny($storage_name){
        
        $storage = $this->db->from('storage_deny')->where(array('name' => $storage_name))->get()->first();
        
        if (empty($storage)){
            $this->db->insert('storage_deny',
                              array(
                                  'name'    => $storage_name,
                                  'counter' => 1,
                                  'updated' => 'NOW()'
                              ));
        }else{
            $this->db->update('storage_deny',
                              array(
                                  'counter' => $storage['counter'] + 1,
                                  'updated' => 'NOW()'
                              ),
                              array('name' => $storage_name));
        }
        
    }
    
    /**
     * Get soap clients for all good storages
     *
     * @return array RESTClient for all good storages
     */
    protected function getClients(){
        $clients = array();

        $user = User::getInstance();

        $uid = $user->getId();
        $mac = $user->getMac();

        if ($mac){
            RESTClient::$from = $mac;
        }elseif ($uid){
            RESTClient::$from = $uid;
        }else{
            RESTClient::$from = $this->stb->mac;
        }

        RESTClient::setAccessToken($this->createAccessToken());

        foreach ($this->storages as $name => $storage){
            $clients[$name] = new RESTClient('http://'.$storage['storage_ip'].'/stalker_portal/storage/rest.php?q=');
        }
        return $clients;
    }
    
    /**
     * Sort array of good storages by load
     *
     * @param array $storages
     * @return array good storages sorted by load
     */
    protected function sortByLoad($storages){
        
        if (!empty($storages)){
        
            foreach ($storages as $name => $storage) {
                $load[$name] = $storage['load'];
            }
            
            array_multisort($load, SORT_ASC, SORT_NUMERIC, $storages);
        }
        
        return $storages;
    }
    
    /**
     * Save in database information about series for media
     *
     * @param array $series_arr
     */
    protected function saveSeries($series_arr){
        return true;
    }
    
    /**
     * Parse exception, add exception message to output and to master log
     *
     * @param Exception $exception
     */
    protected function parseException($exception){
        //trigger_error($exception->getMessage()."\n".$exception->getTraceAsString(), E_USER_ERROR);
        echo $exception->getMessage()."\n".$exception->getTraceAsString();
        $this->addToLog($exception->getMessage());
    }

    private function createAccessToken(){

        $key = md5(microtime(1).uniqid());

        $cache = Cache::getInstance();

        $result = $cache->set($key, 'storage', 0, 120);

        return $key;
    }

    public static function checkAccessToken($token){

        if (!$token){
            return false;
        }

        $val = Cache::getInstance()->get($token);
        return $val === 'storage';
    }
}

class MasterException extends Exception{

    protected $storage_name;

    public function __construct($message, $storage_name){
        $this->message      = $message;
        $this->storage_name = $storage_name;
    }

    public function getStorageName(){
        return $this->storage_name;
    }

}

class StorageSessionLimitException extends MasterException{

    public $message = 'Session limit';

    public function __construct($storage_name){
        $this->storage_name = $storage_name;
    }

}
?>