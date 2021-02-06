<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Imagine\Image\Box;

class AudioClubController extends \Controller\BaseStalkerController {

    public function __construct(Application $app) {
        parent::__construct($app, __CLASS__);
    }

    // ------------------- action method ---------------------------------------

    public function index() {
        if (empty($this->app['action_alias'])) {
            return $this->app->redirect($this->app['controller_alias'] . '/audio-albums');
        }
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function audio_albums() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $allGenre = $this->db->getAllFromTable('audio_genres');
        $allGenre = $this->getUCArray($this->setLocalization($allGenre, 'name'), 'name');
        $this->app['allAudioGenres'] = $allGenre;
        $this->app['allAudioYears'] = $this->setLocalization($this->db->getAllFromTable('audio_years'), 'name');

        $locale = substr($this->app["language"], 0, 2);
        $this->app['allCountries'] = ($locale != 'ru' ? array_map(function($row) use ($locale){
            $row['name'] = $row['name_en'];
            return $row;
        }, $this->db->getAllFromTable('countries')): $this->db->getAllFromTable('countries'));

        $this->app['allLanguages'] = $this->db->getAllFromTable('audio_languages');
        $this->app['allStatus'] = array(
            array('id' => 1, 'title' => $this->setLocalization('Unpublished')),
            array('id' => 2, 'title' => $this->setLocalization('Published'))
        );
        
        $attribute = $this->getDropdownAttributeAudioClub();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $like_filter = array();
        $this->getAudioFilters($like_filter);

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function add_audio_albums() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getDropdownAttributeAudioComposition();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->app['step'] = !empty($this->data['step']) ? (int)$this->data['step']: (!empty($this->postData['form']['step']) ? (int)$this->postData['form']['step']: 1);
        $this->app['curr_cover_dir'] = '';

        $form_data = array();

        if (!empty($this->postData['form'])) {
            $form_data = $this->postData['form'];
        } elseif (!empty($this->data['id'])) {
            $this->app['audioAlbumID'] = $this->data['id'];
            $form_data = $this->db->getAudioAlbum($this->data['id']);
            $form_data['genre_ids'] = $this->db->getGenreForAlbum($this->data['id'], 'genre_id');
        }

        $this->app['audioAlbumID'] = (!empty($form_data['id'])?$form_data['id']:'');
        $this->app['albumName'] = (!empty($form_data['name'])?$form_data['name']:'');

        $form = $this->buildAudioAlbumForm($form_data);
        $form->handleRequest($this->request);

        if ($form->isSubmitted() && $this->saveAudioAlbumData($form, FALSE)) {
            $step = (int)$this->app['step'];
            $step += 1;
            $this->app['step'] = $step;
            $this->postData['form']['step'] = $step;
            return $this->app->redirect('add-audio-albums?id=' . $this->postData['form']['id'] . '&step=' . $this->postData['form']['step']);
        }
        $this->app['form'] = $form->createView();
        $this->app['audioAlbumEdit'] = FALSE;

        $this->app['allLanguages'] = $this->db->getAllFromTable('audio_languages');

        $this->app['breadcrumbs']->addItem($this->setLocalization('Albums'), $this->app['controller_alias'] . '/audio-albums');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Add audio album'));
        return $this->app['twig']->render($this->getTemplateName(__METHOD__, '_step_' . $this->app['step']));
    }
    
    public function edit_audio_albums() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $id = NULL;

        if ($this->method == 'POST' && !empty($this->postData['form']['id'])) {
            $id = $this->postData['form']['id'];
            $this->app['step'] = !empty($this->postData['form']['step']) ? $this->postData['form']['step'] : 1;
        } elseif ($this->method == 'GET' && !empty($this->data['id'])) {
            $id = $this->data['id'];
            $this->app['step'] = !empty($this->data['step']) ? $this->data['step'] : 1;
        } else {
            return $this->app->redirect('add-audio-albums');
        }

        $attribute = $this->getDropdownAttributeAudioComposition();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->app['audioAlbumID'] = $id;

        $this->app['audioAlbumEdit'] = (str_replace('-', '_', $this->app['action_alias']) == __FUNCTION__);

        $audio_album = $this->db->getAudioAlbum($id);
        $audio_album = (is_array($audio_album) && count($audio_album) > 0) ? $audio_album : array();
        $audio_album['genre_ids'] = $this->db->getGenreForAlbum($id, 'genre_id');

        $form = $this->buildAudioAlbumForm($audio_album);
        $form->handleRequest($this->request);

        $this->app['curr_cover_dir'] = (!empty($audio_album['cover']) && strpos($audio_album['cover'], 'new') === FALSE) ? 'misc/audio_covers/' . ceil($audio_album['id'] / 100) . '/' : 'misc/audio_covers/new/';

        if (($this->app['audioAlbumEdit'] || $this->app['step'] > 2) && $this->saveAudioAlbumData($form, $this->app['audioAlbumEdit'])) {
            if ($this->app['step'] == 1) {
                $url = $this->workURL . '/' . $this->app['controller_alias'] . '/' . $this->app['action_alias'] . '?step=2&id=' . $audio_album['id'];
            } else {
                $url = $this->workURL . '/' . $this->app['controller_alias'] . '/audio-albums';
            }
            return $this->app->redirect($url);
        }

        $this->app['form'] = $form->createView();
        $this->app['allLanguages'] = $this->db->getAllFromTable('audio_languages');

        $this->app['albumName'] = $audio_album['name'];

        $this->app['breadcrumbs']->addItem($this->setLocalization('Albums'), $this->app['controller_alias'] . '/audio-albums');
        $this->app['breadcrumbs']->addItem(($this->app['audioAlbumEdit'] ? $this->setLocalization('Edit audio album') . ": " : '')  . ($this->app['performerName'] ? $this->app['performerName'] . ' - ': '') . $audio_album['name']);
        return $this->app['twig']->render($this->getTemplateName("AudioClub::add_audio_albums", '_step_' . $this->app['step']));
    }
    
    public function audio_artists() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getShortDropdownAttribute(array(
            array('name'=>'albums_count',     'title'=>$this->setLocalization('Albums count'),   'checked' => TRUE)
        ));

        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function audio_genres() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getShortDropdownAttribute(array(
            array('name'=>'albums_count',     'title'=>$this->setLocalization('Albums count'),   'checked' => TRUE)
        ));

        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
       
    public function audio_languages() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getShortDropdownAttribute(array(
            array('name'=>'track_count',     'title'=>$this->setLocalization('Tracks count'),   'checked' => TRUE)
        ));

        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function audio_years() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getShortDropdownAttribute(array(
            array('name'=>'albums_count',     'title'=>$this->setLocalization('Albums count'),   'checked' => TRUE)
        ));

        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    //----------------------- ajax method --------------------------------------

    public function audio_albums_list_json($local_uses = FALSE) {

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
                
        $filds_for_select = array(
            "id" => "`audio_albums`.`id` as `id`",
            "name" => "CONCAT_WS(' - ', `audio_performers`.`name`, `audio_albums`.`name`) as `name`",
            "tracks_count" => "(SELECT COUNT(*) FROM `audio_compositions` WHERE `album_id` = `audio_albums`.`id`) as `tracks_count`",
            "ganre_name" => "'' as `ganre_name`",
            "year" => "`audio_years`.`name` as `year`",
            "country" => "`countries`.`name" . (substr($this->app["language"], 0, 2) != 'ru' ? "_en": "" ) . "` as `country`",
            "language" => "0 as `language`",
            "status" => "`audio_albums`.`status` as `status`"
        );
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }
        
        $like_filter = array();
        $filter = $this->getAudioFilters($like_filter);
        
        if (empty($query_param['like']) && !empty($like_filter)) {
            $query_param['like'] = $like_filter;
        } elseif (!empty($query_param['like']) && !empty($like_filter)) {
            $query_param['like'] = array_merge($query_param['like'], $like_filter);
        }
        
        $query_param['where'] = array_merge($query_param['where'], $filter);

        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        } else {
            $query_param['select'][] = 'audio_albums.id as id';
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        if (!empty($param['id'])) {
            $query_param['where']['audio_albums.id'] = $param['id'];
        }
        
        $response['recordsTotal'] = $this->db->getTotalRowsAudioAlbumsList();
        $response["recordsFiltered"] = $this->db->getTotalRowsAudioAlbumsList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $response['data'] = $this->db->getAudioAlbumsList($query_param);
        $response['data'] = $this->setLocalization($response['data'], 'year');
        reset($response['data']);
        while (list($key, $row) = each($response['data'])){
            $response['data'][$key]['RowOrder'] = "dTRow_" . $row['id'];
        }

        $this->getAlbumsGenreNames($response['data']);
        $this->getAlbumsLanguages($response['data']);
        
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
    }
    
    public function remove_audio_albums() {

        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['albumsid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['albumsid'];
        $error = $this->setLocalization('Failed');
        if ($album_count = $this->db->deleteAudioAlbum(array('id' => $this->postData['albumsid']))) {
            $genre_count = $this->db->deleteAudioGenre(array('album_id' => $this->postData['albumsid']));
            $compositions_count = $this->db->deleteAudioCompositions(array('album_id' => $this->postData['albumsid']));
            $data['msg'] = $this->setLocalization('{albm} album and his all {cmps} compositions in {gnr} genres has been deleted', '', $album_count, array('{albm}' => $album_count, '{cmps}' => $compositions_count, '{gnr}' => $genre_count));
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, '');

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function audio_genres_list_json($local_uses = FALSE){

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
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        } elseif (array_key_exists('name', $query_param['where'])) {
            $query_param['where']['audio_genres.name'] = $query_param['where']['name'];
            unset($query_param['where']['name']);
        }

        if (!isset($query_param['like'])) {
            $query_param['like'] = array();
        } elseif (array_key_exists('name', $query_param['like'])) {
            $query_param['like']['audio_genres.name'] = $query_param['like']['name'];
            unset($query_param['like']['name']);
        }

        if (!isset($query_param['order'])) {
            $query_param['order'] = array();
        } elseif (array_key_exists('name', $query_param['order'])) {
            $query_param['order']['audio_genres.name'] = $query_param['order']['name'];
            unset($query_param['order']['name']);
        }

        if (!empty($param['id'])) {
            $query_param['where']['audio_genres.id'] = $param['id'];
            unset($query_param['where']['id']);
        }
        
        $response['recordsTotal'] = $this->db->getTotalRowsAudioGenresList();
        $response["recordsFiltered"] = $this->db->getTotalRowsAudioGenresList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }
        
        if (!empty($query_param['select']) || !in_array('id', $query_param['select'])) {
           $query_param['select'][] = 'id';
        }

        if (!in_array('name', $query_param['select'])) {
            $query_param['select'][] = 'name';
        }
        if (!in_array('albums_count', $query_param['select'])) {
            $query_param['select'][] = 'albums_count';
        }
        
        $response['data'] =  array_map(function($row){
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getAudioGenresList($query_param));
                
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
        
    }
    
    public function add_audio_genres() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'addAudioGenre';
        $error = $this->setLocalization('Failed');
        $this->postData['name'] = trim($this->postData['name']);
        $check = $this->db->getAudioGenresList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $result = $this->db->insertAudioGenres(array('name' => $this->postData['name']));
            if ($result) {
                $error = '';
                $data['msg'] = $this->setLocalization( 'inserted');
                $data['id'] = $result;
                $data['name'] = $this->postData['name'];
            } else {
                $data['msg'] = $error = $this->setLocalization('Nothing to do');
            }
        } else {
            $data['msg'] = $error = $this->setLocalization('Name already used');
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function edit_audio_genres() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '' || empty($this->postData['id'])) {
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
        $this->postData['name'] = trim($this->postData['name']);
        $check = $this->db->getAudioGenresList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $result = $this->db->updateAudioGenres(array('name' => $this->postData['name']), array('id' => $this->postData['id']));
            if (is_numeric($result)) {
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                    $data['msg'] = $this->setLocalization('Nothing to do');
                } else {
                    $data = array_merge_recursive($data, $this->audio_genres_list_json(TRUE));
                    $data['msg'] = $this->setLocalization('updated');
                }
            }
        } else {
            $data['msg'] = $error =  $this->setLocalization('Name already used');
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function remove_audio_genres() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['genresid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['genresid'];
        $error = $this->setLocalization('Failed');

        $search = array(
            'select' => array('albums_count'),
            'where' => array('genre_id' => $this->postData['genresid'])
        );

        $albums_count = $this->db->getAudioGenresList($search);

        if (empty($albums_count)) {
            $result = $this->db->deleteAudioGenres(array('id' => $this->postData['genresid']));
            if ($result){
                $data['msg'] = $this->setLocalization('Deleted');
            } else {
                $data['msg'] = $error = $this->setLocalization('Nothing to do');
            }
            $error = '';
        } else {
            $error = $data['msg'] = $this->setLocalization('Found albums of this genre. Deleting not possible');
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function check_audio_genres_name() {
        if (!$this->isAjax || $this->method != 'POST' || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'audio_genres_name';
        $error = $this->setLocalization('Name already used');
        if ($this->db->getAudioGenresList(array('where' => array('name' => trim($this->postData['name']), 'id<>' => !empty($this->postData['id']) ? $this->postData['id']: ''), 'order' => array('name' => 'ASC')))) {
            $data['chk_rezult'] = $this->setLocalization('Name already used');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Name is available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function audio_artists_list_json($local_uses = FALSE) {

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
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);;

        $query_param = $this->prepareDataTableParams($param, array('operations', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        } elseif (array_key_exists('name', $query_param['where'])) {
            $query_param['where']['audio_performers.name'] = $query_param['where']['name'];
            unset($query_param['where']['name']);
        }

        if (!isset($query_param['like'])) {
            $query_param['like'] = array();
        } elseif (array_key_exists('name', $query_param['like'])) {
            $query_param['like']['audio_performers.name'] = $query_param['like']['name'];
            unset($query_param['like']['name']);
        }

        if (!isset($query_param['order'])) {
            $query_param['order'] = array();
        } elseif (array_key_exists('name', $query_param['order'])) {
            $query_param['order']['audio_performers.name'] = $query_param['order']['name'];
            unset($query_param['order']['name']);
        }

        if (!empty($param['id'])) {
            $query_param['where']['audio_performers.id'] = $param['id'];
            unset($query_param['where']['id']);
        }

        $response['recordsTotal'] = $this->db->getTotalRowsAudioArtistList();
        $response["recordsFiltered"] = $this->db->getTotalRowsAudioArtistList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }
        
        if (!empty($query_param['select']) || !in_array('id', $query_param['select'])) {
           $query_param['select'][] = 'id';
        }
        if (!in_array('name', $query_param['select'])) {
            $query_param['select'][] = 'name';
        }
        if (!in_array('albums_count', $query_param['select'])) {
            $query_param['select'][] = 'albums_count';
        }

        $response['data'] = array_map(function($row){
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getAudioArtistList($query_param));
                
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
        
    }
    
    public function add_audio_artists() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'addAudioArtist';
        $error = $this->setLocalization('Failed');
        $this->postData['name'] = trim($this->postData['name']);
        $check = $this->db->getAudioArtistList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $data['id'] = $this->db->insertAudioArtist(array('name' => $this->postData['name']));
            $data['name'] = $this->postData['name'];
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function edit_audio_artists() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '' || empty($this->postData['id'])) {
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

        $this->postData['name'] = trim($this->postData['name']);

        $check = $this->db->getAudioArtistList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $result = $this->db->updateAudioArtist(array('name' => $this->postData['name']), array('id' => $this->postData['id']));
            if (is_numeric($result)) {
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                }
                $data = array_merge_recursive($data, $this->audio_artists_list_json(TRUE));
            }
        } else {
            $data['nothing_to_do'] = TRUE;
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function remove_audio_artists() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['artistsid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['artistsid'];
        $error = $this->setLocalization('Failed');
        if ( $this->db->deleteAudioArtist(array('id' => $this->postData['artistsid']))){
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function check_audio_artists_name() {
        if (!$this->isAjax || $this->method != 'POST' || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'audio_artists_name';
        $error = $this->setLocalization('Name already used');
        if ($this->db->getAudioArtistList(array('where' => array('name' => trim($this->postData['name']), 'id<>' => !empty($this->postData['id']) ? $this->postData['id']: '' ), 'order' => array('name' => 'ASC')))) {
            $data['chk_rezult'] = $this->setLocalization('Name already used');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Name is available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function audio_languages_list_json($local_uses = FALSE){

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
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        } elseif (array_key_exists('name', $query_param['where'])) {
            $query_param['where']['audio_languages.name'] = $query_param['where']['name'];
            unset($query_param['where']['name']);
        }

        if (!isset($query_param['like'])) {
            $query_param['like'] = array();
        } elseif (array_key_exists('name', $query_param['like'])) {
            $query_param['like']['audio_languages.name'] = $query_param['like']['name'];
            unset($query_param['like']['name']);
        }

        if (!isset($query_param['order'])) {
            $query_param['order'] = array();
        } elseif (array_key_exists('name', $query_param['order'])) {
            $query_param['order']['audio_languages.name'] = $query_param['order']['name'];
            unset($query_param['order']['name']);
        }

        if (!empty($param['id'])) {
            $query_param['where']['audio_languages.id'] = $param['id'];
            unset($query_param['where']['id']);
        }
        
        $response['recordsTotal'] = $this->db->getTotalRowsAudioLanguageList();
        $response["recordsFiltered"] = $this->db->getTotalRowsAudioLanguageList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        if (!empty($query_param['select']) || !in_array('id', $query_param['select'])) {
           $query_param['select'][] = 'id';
        }

        if (!in_array('name', $query_param['select'])) {
            $query_param['select'][] = 'name';
        }
        if (!in_array('track_count', $query_param['select'])) {
            $query_param['select'][] = 'track_count';
        }

        $response['data'] = array_map(function($row){
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getAudioLanguageList($query_param));
                
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
        
    }
    
    public function add_audio_languages() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'addAudioLanguage';
        $error = $this->setLocalization('Failed');
        $this->postData['name'] = trim($this->postData['name']);
        $check = $this->db->getAudioLanguageList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $data['id'] = $this->db->insertAudioLanguage(array('name' => $this->postData['name']));
            $data['name'] = $this->postData['name'];
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function edit_audio_languages() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['id'];
        $data['data'] = array();

        $this->postData['name'] = trim($this->postData['name']);

        $error = $this->setLocalization('Failed');
        $check = $this->db->getAudioLanguageList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $result = $this->db->updateAudioLanguage(array('name' => $this->postData['name']), array('id' => $this->postData['id']));
            if (is_numeric($result)) {
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                }
                $data = array_merge_recursive($data, $this->audio_languages_list_json(TRUE));
            }
        } else {
            $data['nothing_to_do'] = TRUE;
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function remove_audio_languages() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['languagesid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['languagesid'];
        $error = $this->setLocalization('Failed');
        if ($this->db->deleteAudioLanguage(array('id' => $this->postData['languagesid']))){
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function check_audio_languages_name() {
        if (!$this->isAjax || $this->method != 'POST' || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'audio_languages_name';

        $error = $this->setLocalization('Name already used');
        if ($this->db->getAudioLanguageList(array('where' => array('name' => trim($this->postData['name']), 'id<>' => !empty($this->postData['id']) ? $this->postData['id']: '' ), 'order' => array('name' => 'ASC')))) {
            $data['chk_rezult'] = $this->setLocalization('Name already used');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Name is available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function audio_years_list_json($local_uses = FALSE){

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
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        } elseif (array_key_exists('name', $query_param['where'])) {
            $query_param['where']['audio_years.name'] = $query_param['where']['name'];
            unset($query_param['where']['name']);
        }

        if (!isset($query_param['like'])) {
            $query_param['like'] = array();
        } elseif (array_key_exists('name', $query_param['like'])) {
            $query_param['like']['audio_years.name'] = $query_param['like']['name'];
            unset($query_param['like']['name']);
        }

        if (!isset($query_param['order'])) {
            $query_param['order'] = array();
        } elseif (array_key_exists('name', $query_param['order'])) {
            $query_param['order']['audio_years.name'] = $query_param['order']['name'];
            unset($query_param['order']['name']);
        }

        if (!empty($param['id'])) {
            $query_param['where']['audio_years.id'] = $param['id'];
            unset($query_param['where']['id']);
        }

        $response['recordsTotal'] = $this->db->getTotalRowsAudioYearList();
        $response["recordsFiltered"] = $this->db->getTotalRowsAudioYearList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }
        
        if (!empty($query_param['select']) || !in_array('id', $query_param['select'])) {
           $query_param['select'][] = 'id';
        }
        if (!in_array('name', $query_param['select'])) {
            $query_param['select'][] = 'name';
        }
        if (!in_array('albums_count', $query_param['select'])) {
            $query_param['select'][] = 'albums_count';
        }

        $response['data'] = array_map(function($row){
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getAudioYearList($query_param));
                
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        
        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
        } else {
            return $response;
        }
        
    }
    
    public function add_audio_years() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'addAudioYear';
        $error = $this->setLocalization('Failed');
        $this->postData['name'] = trim($this->postData['name']);
        $check = $this->db->getAudioYearList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $data['id'] = $this->db->insertAudioYear(array('name' => $this->postData['name']));
            $data['name'] = $this->postData['name'];
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function edit_audio_years() {
        if (!$this->isAjax || $this->method != 'POST'  || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['id'];
        $data['data'] = array();

        $this->postData['name'] = trim($this->postData['name']);

        $check = $this->db->getAudioYearList(array('where' => array('name' => $this->postData['name']), 'order' => array('name' => 'ASC')));
        if (empty($check)) {
            $result = $this->db->updateAudioYear(array('name' => $this->postData['name']), array('id' => $this->postData['id']));
            if (is_numeric($result)) {
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                }
                $data = array_merge_recursive($data, $this->audio_years_list_json(TRUE));
            }
        } else {
            $data['nothing_to_do'] = TRUE;
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function remove_audio_years() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['yearsid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['yearsid'];
        $error = $this->setLocalization('Failed');
        if ($this->db->deleteAudioYear(array('id' => $this->postData['yearsid']))){
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function check_audio_years_name() {
        if (!$this->isAjax || $this->method != 'POST' || !array_key_exists('name', $this->postData) || trim($this->postData['name']) == '') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'audio_years_name';

        $error = $this->setLocalization('Name already used');
        if ($this->db->getAudioYearList(array('where' => array('name' =>  trim($this->postData['name']), 'id<>' => !empty($this->postData['id']) ? $this->postData['id']: ''), 'order' => array('name' => 'ASC')))) {
            $data['chk_rezult'] = $this->setLocalization('Name already used');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Name available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function edit_audio_cover() {
        if (!$this->isAjax || $this->method != 'POST') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $error = $this->setLocalization('No data');
        
        if (!empty($_FILES)) {
            reset($_FILES);
            list($key, $tmp) = each($_FILES);

            $path = realpath(PROJECT_PATH . "/../misc/audio_covers/");
            if (!empty($this->postData['old_cover'])) {
                $filename = $this->postData['old_cover'];
                $tmp = array_reverse(explode('.', $filename));
                $tmp_path = "/" . ceil($tmp[count($tmp) >= 2 ? 1: 0 ] / 100) . "/";
                @unlink($path.$tmp_path.$filename);
            }

            if( !empty($this->postData['audio_id'])) {
                $filename = $this->postData['audio_id'] . "." . $this->request->files->get($key)->getClientOriginalExtension();
                $path .= "/" . ceil($this->postData['audio_id'] / 100) . "/";
            } else {
                $filename = 'new' . rand(0, 100000) . "." . $this->request->files->get($key)->getClientOriginalExtension();
                $path .= '/new/';
            }

            if (!is_dir($path)) {
                mkdir($path, 0755);
            }

            try{
                $uploaded = $this->request->files->get($key)->getPathname();

                $this->app['imagine']->open($uploaded)->resize(new Box(240, 320))->save($path . $filename);

                $data['name'] = $filename;
                $error = '';
            } catch (\Exception $e){
                $error = $e->getMessage();
            }
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function delete_cover() {
        if (!$this->isAjax || $this->method != 'POST' || (empty($this->postData['cover_id']) && empty($this->postData['file_name']))) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteCover';
        $error = $this->setLocalization('Failed');
        $album = array();
        if (!empty($this->postData['cover_id'])) {
            $album = $this->db->getAudioAlbumsList(array(
                'select' => array('audio_albums.id as id', 'audio_albums.cover as cover'),
                'where' => array( 'audio_albums.id'=> $this->postData['cover_id']),
                'order' => array('audio_albums.id'=>'ASC')
            ));
        }

        $file_name = (count($album) != 0 && !empty($album[0]['cover']) ? $album[0]['cover']: (!empty($this->postData['file_name']) ? $this->postData['file_name']: ''));

        if (count($album) != 0 && !empty($album[0]['cover'])){
            $path = realpath(PROJECT_PATH . "/../misc/audio_covers/").'/' . ceil($album[0]['id']/100).'/';
        } else {
            $path = realpath(PROJECT_PATH . "/../misc/audio_covers/").'/new/';
        }

        if (!empty($file_name)) {
            if (!empty($this->postData['cover_id'])) {
                $this->db->updateCover($this->postData['cover_id'], '');
            }
            try{
                unlink($path . $file_name);
                $data['msg'] = $this->setLocalization('Deleted');
                $error = '';
            } catch (\Exception $e){
                $error = $this->setLocalization('image file has not been deleted') . ', ';
                $error .= $this->setLocalization('image name') . ' - "' . $file_name . '", ';
                $error .= $this->setLocalization('file can be deleted manually from screenshot directory');
                $data['msg'] = $error;
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function audio_albums_composition_list_json($local_uses = FALSE) {

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

        if (!$local_uses) {
            $response['action'] = 'setCompositionModal';
        }

        $filds_for_select = array(
            "id" => 'audio_compositions.id as `id`', 
            "number" => 'audio_compositions.number as `number`', 
            "name" => 'audio_compositions.name as `name`', 
            "url" => 'audio_compositions.url as `url`', 
            "language" => 'audio_languages.name as `language`', 
            "duration" => '`duration`',
            "tasks" => '0 as `tasks`', 
            "complaints" => '0 as `complaints`',
            "status" => 'audio_compositions.status as `status`',
            "language_id" => 'audio_languages.id as `language_id`'
        );
        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        if(!empty($param['album_id'])) {
            $query_param['where']['album_id'] = $param['album_id'];
        } else {
            $query_param['where']['album_id'] = -1;
        }
        
        if(!empty($param['trackid'])) {
            $query_param['where']['audio_compositions.id'] = $param['trackid'];
        }
        
        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        } else {
            $query_param['select'][] = 'audio_compositions.id as id';
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);
        
        $response['recordsTotal'] = $this->db->getTotalRowsAlbumsCompositionList(array('album_id'=>$query_param['where']['album_id']));
        $response["recordsFiltered"] = $this->db->getTotalRowsAlbumsCompositionList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }
        $query_param['order']['number'] = 'ASC';
        $response['data'] = $this->db->getAlbumsCompositionList($query_param);

        while (list($key, $row) = each($response['data'])){
            $response['data'][$key]['RowOrder'] = "dTRow_" . $row['id'];
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
    
    public function audio_track_reorder() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $matches = array();
        $data = array();
        $error = 'error';
        if (preg_match("/(\d+)/i", $this->postData['id'], $matches)){
            $params = array(
                'select' => array(
                    "id" => 'audio_compositions.id as `id`', 
                    "number" => 'audio_compositions.number as `number`', 
                    "album_id" => 'audio_compositions.album_id as `album_id`', 
                    ),
                'where' => array(),
                'like' => array(),
                'order' => array()
            );
            $id = $matches[0];
            $curr_pos = $this->postData['fromPosition'];
            $new_pos = $this->postData['toPosition'];
            
            $params['where']['audio_compositions.id'] = $id;
            $curr_track = $this->db->getAlbumsCompositionList($params);
            
            $params['where'] = array();
            $params['where']['number'] = $new_pos;
            $params['where']['album_id'] = $curr_track[0]['album_id'];
            
            $target_track = $this->db->getAlbumsCompositionList($params);
            
            $curr_track[0]['number'] = $new_pos;
            $target_track[0]['number'] = $curr_pos;
            
            if ($this->db->updateAlbumsComposition($curr_track[0], $curr_track[0]['id']) && $this->db->updateAlbumsComposition($target_track[0], $target_track[0]['id'])) {
                $error = '';
            }
            
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function audio_tracks_manage() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $data = array();
        $data['action'] = 'updateTableData';
        $track = array($this->postData);
        $data['data'] = array();
        $error = $this->setLocalization('error');
        if (empty($this->postData['id'])) {
            $params = array(
                    'select' => array(
                        "max" => 'if(max(audio_compositions.`number`), max(audio_compositions.`number`) + 1, 1) as `number`'
                        ),
                    'where' => array(
                        "audio_compositions.`album_id`" => $track[0]['album_id']
                        ),
                    'like' => array(),
                    'order' => array()
                );
            $max_num = $this->db->getAlbumsCompositionList($params);
            $operation = 'insertAlbumsComposition';
            $track[0]['added'] = 'NOW()';
            $track[0]['number'] = (!empty($max_num[0]['number']) ? $max_num[0]['number']: 1);
        } else {
            $operation = 'updateAlbumsComposition';
            $data['id'] = $track['id'] = $this->postData['id'];
            $data['action'] = 'updateTableRow';
        }
        unset($track[0]['id']);

        if (!empty($this->postData['url']) && preg_match('/^(\w+\s)?\w+\:\/\/.*$/i', $this->postData['url'])) {

            $result = call_user_func_array(array($this->db, $operation), $track);

            if (is_numeric($result)) {
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                }
                if ($operation == 'updateAlbumsComposition') {
                    $this->postData['trackid'] = $this->postData['id'];
                    $data = array_merge_recursive($data, $this->audio_albums_composition_list_json(TRUE));
                }
                $data['additional'] = array(
                        'msg' => '',
                        'action' => 'JSSuccessModalBox'
                    );
            }

        } else {
            $data['msg'] = $this->setLocalization('Invalid format links');
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function remove_audio_album_track() {
        
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['trackid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['trackid'];
        $error = $this->setLocalization('Failed');
        if ($this->db->deleteAudioCompositions(array('id' => $this->postData['trackid']))){
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function toggle_audio_album_track(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['trackid']) || !array_key_exists('trackstatus', $this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['trackid'];
        $data['data'] = array();
        $result = $this->db->updateAlbumsComposition(array('status' => (int)(!((bool) $this->postData['trackstatus']))), $this->postData['trackid']);

        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $this->postData['id'] = $this->postData['trackid'];
            $data = array_merge_recursive($data, $this->audio_albums_composition_list_json(TRUE));
        }

        $response = $this->generateAjaxResponse($data, '');

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }
    
    public function toggle_audio_albums(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['albumsid']) || !array_key_exists('albumsstatus', $this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['albumsid'];
        $data['data'] = array();
        $error = $this->setLocalization('Failed');
        $result = $this->db->updateAudioAlbum(array('status' => (int)(!((bool) $this->postData['albumsstatus']))), $this->postData['albumsid']);

        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $this->postData['id'] = $this->postData['albumsid'];
            $data = array_merge_recursive($data, $this->audio_albums_list_json(TRUE));
        }


        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));
    }

    public function get_media_info_json(){
        if (!$this->isAjax || $this->method != 'POST') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'setMediaInfo',
            'data' => array()
        );

        $error = '';
        $url = '';
        $probe = FALSE;

        if (!empty($this->postData['url'])) {
            $url = $this->postData['url'];
        } elseif (!empty($this->postData['id'])) {
            $id = intval($this->postData['id']);
            $composition = $this->db->getAlbumsCompositionList(array('where' => array('audio_compositions.id' => $id)));
            if (!empty($composition)) {
                reset($composition);
                list($num, $row) = each($composition);
                $url = $row['url'];
            }
        }

        if (!empty($url)) {
            $url = explode(' ', trim($url));
            $url = end($url);

            if (!empty($url)) {
                try{
                    $probe = \FFMpeg\FFProbe::create();
                    $audio = $probe->streams($url)->audios()->first();
                    $data['data']['duration'] = round(@floatval($audio->get('duration')));
                    $data['data']['bit rate'] = round(@floatval($audio->get('bit_rate')) / 1000 ) . 'kbps';
                    $data['data']['codec name'] = $audio->get('codec_long_name');
                } catch(\Exception $e){
                    if (class_exists('\FFMpeg\FFProbe') && !empty($probe)) {
                        $error = $this->setLocalization('Failed') . '. ' . $e->getMessage();
                    } else {
                        $error = $this->setLocalization('Failed') . '. ' . $this->setLocalization('Unable to load FFProbe library. Please install "ffmpeg" or other package with this library(eg "libav-tools")');
                    }
                }

            }
        } else {
            $error = $this->setLocalization('Empty URL');
        }

        $data['msg'] = $error;
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500), array('Content-Type' => 'application/json; charset=UTF-8'));

    }
    
    //------------------------ service method ----------------------------------

    private function getAudioFilters(&$like_filter) {
        $return = array();

        if (!empty($this->data['filters'])){
            if (array_key_exists('status_id', $this->data['filters']) && $this->data['filters']['status_id'] != 0) {
                $return['status'] = $this->data['filters']['status_id'] - 1;
            }
                       
            if (array_key_exists('year', $this->data['filters']) && (!is_numeric($this->data['filters']['year']) || $this->data['filters']['year'] != 0)) {
                $return['year'] = $this->data['filters']['year'];
            }
            if (array_key_exists('ganre_name', $this->data['filters']) && !is_numeric($this->data['filters']['ganre_name'])) {
                $like_filter['ganre_name'] = "%" . $this->data['filters']['ganre_name'] . "%";
            }
            
            if (array_key_exists('country', $this->data['filters']) && !is_numeric($this->data['filters']['country'])) {
                $like_filter['country'] = "%" . $this->data['filters']['country'] . "%";
            }
            
            if (array_key_exists('language', $this->data['filters']) && !is_numeric($this->data['filters']['language'])) {
                $like_filter['language'] = "%" . $this->data['filters']['language'] . "%";
            }
            $this->app['filters'] = $this->data['filters'];
        } else {
            $this->app['filters'] = array();
        }
        return $return;
    }
    
    private function getDropdownAttributeAudioClub(){
        return array(
            array('name'=>'name',           'title'=>$this->setLocalization('Title'),   'checked' => TRUE),
            array('name'=>'tracks_count',   'title'=>$this->setLocalization('Tracks'),  'checked' => TRUE),
            array('name'=>'ganre_name',     'title'=>$this->setLocalization('Genre'),   'checked' => TRUE),
            array('name'=>'year',           'title'=>$this->setLocalization('Year'),    'checked' => TRUE),
            array('name'=>'country',        'title'=>$this->setLocalization('Country'), 'checked' => TRUE),
            array('name'=>'language',       'title'=>$this->setLocalization('Language'),'checked' => TRUE),
            array('name'=>'status',         'title'=>$this->setLocalization('Status'),  'checked' => TRUE),
            array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),'checked' => TRUE)
        );
    }
    
    private function getAlbumsGenreNames(&$data) {
        reset($data);
        while(list($key, $row) = each($data)){
            $tmp = $this->setLocalization($this->db->getGenreForAlbum($row['id'], 'name'));
            $data[$key]['ganre_name'] = !empty($tmp) && is_array($tmp) ? implode(', ', $tmp) : '';
        }
    }
    
    private function getAlbumsLanguages(&$data) {
        reset($data);
        while(list($key, $row) = each($data)){
            $data[$key]['language'] = implode(', ', $this->db->getLanguagesForAlbum($row['id'], 'name'));
        }
    }
    
    private function buildAudioAlbumForm($data = array(), $edit = FALSE) {

        $tmp = $this->db->getAllFromTable('audio_performers');
        $all_performers = array(''=>'');
        if (!empty($tmp)) {
            $all_performers = array_replace($all_performers, array_combine($this->getFieldFromArray($tmp, 'id'), $this->getFieldFromArray($tmp, 'name')));
        }

        $this->app['performerName'] = (!empty($data['performer_id']) && !empty($all_performers[$data['performer_id']])) ? $all_performers[$data['performer_id']]: '';

        $tmp = $this->db->getAllFromTable('audio_genres');
        $all_genres = array(''=>'');
        if (!empty($tmp)) {
            $tmp = $this->getUCArray($tmp, 'name');
            $all_genres = array_replace($all_genres, array_combine($this->getFieldFromArray($tmp, 'id'), $this->getFieldFromArray($tmp, 'name')));
        }
        
        $tmp = $this->db->getAllFromTable('audio_years');
        $all_years = array(''=>'');
        if (!empty($tmp)) {
            $all_years = array_replace($all_years, array_combine($this->getFieldFromArray($tmp, 'id'), $this->getFieldFromArray($tmp, 'name')));
        }

        $language = $this->app->offsetExists('language') ? $this->app->offsetGet('language'): 'en';

        $field = !empty($language) && substr($language, 0, 2) == 'ru'? 'name': 'name_en';

        $tmp = $this->db->getAllFromTable('countries', $field);
        $all_countries = array(''=>'');
        if (!empty($tmp)) {
            $all_countries = array_replace($all_countries, array_combine($this->getFieldFromArray($tmp, 'id'), $this->getFieldFromArray($tmp, $field)));
        }
        $builder = $this->app['form.factory'];

        $data['step'] = $this->app['step'];

        if ($data['step'] == 2) {
            $store_data_keys = array('performer_id', 'name', 'year_id', 'country_id', 'genre_ids');
            $data['store_data'] = array_intersect_key($data, array_fill_keys($store_data_keys,''));
            if (!empty($data['store_data']['genre_ids']) && is_array($data['store_data']['genre_ids'])) {
                $data['store_data']['genre_ids'] = implode('', $data['store_data']['genre_ids']);
            }
            $data['store_data'] = str_replace(' ', '', implode('', $data['store_data']));
        }

        $form = $builder->createBuilder('form', $data)
            ->add('step', 'hidden')
            ->add('id', 'hidden');
        if ($data['step'] == 1) {
            $form->add('performer_id', 'choice', array(
                'choices' => $all_performers,
                'constraints' => array(new Assert\Choice(array('choices' => array_keys($all_performers)))),
                'choice_translation_domain' => FALSE
            ))
                ->add('name', 'text', array('constraints' => array(new Assert\NotBlank())))
                ->add('genre_ids', 'choice', array(
                    'choices' => $all_genres,
                    'constraints' => array(new Assert\Choice(array('choices' => array_keys($all_genres), 'multiple' => TRUE))),
                    'multiple' => TRUE,
                    'choice_translation_domain' => FALSE
                ))
                ->add('year_id', 'choice', array(
                    'choices' => $all_years,
                    'constraints' => array(new Assert\Choice(array('choices' => array_keys($all_years)))),
                    'choice_translation_domain' => FALSE
                ))
                ->add('cover', 'hidden')
                ->add('country_id', 'choice', array(
                    'choices' => $all_countries,
                    'constraints' => array(new Assert\Choice(array('choices' => array_keys($all_countries)))),
                    'choice_translation_domain' => FALSE
                ));
        }

        $form->add('store_data', 'hidden');

        return $form->getForm();
    }

    private function saveAudioAlbumData(&$form, $edit = FALSE) {
        
        if (!empty($this->method) && $this->method == 'POST') {

            if (!$form->isSubmitted()) {
                $form->handleRequest($this->request);
            }
            $data = $form->getData();

            $genre_ids = !empty($data['genre_ids']) && is_array($data['genre_ids'])? $data['genre_ids']: array();
            $db_data = $data = array_intersect_key($data, array_flip($this->getFieldFromArray($this->db->getTableFields('audio_albums'), 'Field')));

            if (!$edit && empty($data['id'])) {
                $action = 'insertAudioAlbum';
            } else {
                $action =  'updateAudioAlbum';
                $db_data['id<>'] = $db_data['id'];
            }

            unset($db_data['id']);
            unset($db_data['cover']);
            unset($db_data['status']);
            unset($db_data['added']);

            $audio_album = $this->db->getAudioAlbum($db_data);

            if ($form->isValid()) {
                if (empty($audio_album)) {
                    if ($action == 'insertAudioAlbum') {
                        $data['added'] = 'NOW()';
                    }

                    $param = array();
                    $param[] = $data;
                    $old_data = array();
                    if ($edit && !empty($data['id'])) {
                        $param[] = $data['id'];
                        $old_data = $this->db->getAudioAlbum($data['id']);
                    }
                    unset($param[0]['id']);
                    $return_val = call_user_func_array(array($this->db, $action), $param);
                    if ($return_val !== FALSE) {
                        if ($action == 'updateAudioAlbum') {
                            $album_id = $data['id'];
                        } else {
                            $album_id = $this->postData['form']['id'] = $return_val;
                        }
                        $path = realpath(PROJECT_PATH . "/../misc/audio_covers/");
                        $folder = "/" . ceil($album_id / 100) . "/";
                        if (!empty($data['cover'])) {
                            $filename = explode('.', $data['cover']);
                            $old_path = $path . (strpos($data['cover'], 'new') !== FALSE ? '/new/': $folder);

                            if (!is_dir($path)) {
                                mkdir($path, 0755);
                            }

                            if (rename($old_path . $data['cover'], $path . $folder . "$album_id.$filename[1]")){
                                $this->db->updateAudioAlbum(array('cover' => "$album_id.$filename[1]"), $album_id);
                            }
                        } elseif (!empty($old_data['cover'])){
                            unlink($path . $folder . $old_data['cover']);
                        }

                        if (!empty($genre_ids)) {
                            $genres_data = array();
                            $this->db->deleteAudioGenre(array('album_id' => $data['id']));
                            foreach ($genre_ids as $genre_id){
                                $genres_data[] = array(
                                    'album_id' => $album_id,
                                    'genre_id' => $genre_id
                                );
                            }
                            if (!empty($genres_data)){
                                $this->db->insertAudioGenre($genres_data);
                            }
                        }

                    }
                    return TRUE;
                } elseif (empty($db_data) || $this->app['step'] > 2) {
                    return TRUE;
                }
                $form->addError(new FormError($this->setLocalization('The album with such parameters for this performer already exists')));
            }
        }
        return FALSE;
    }

    private function getDropdownAttributeAudioComposition(){
        return array(
            array('name'=>'number',         'title'=>$this->setLocalization('Order'),   'checked' => TRUE),
            array('name'=>'name',           'title'=>$this->setLocalization('Title'),   'checked' => TRUE),
            array('name'=>'url',            'title'=>$this->setLocalization('URL'),     'checked' => TRUE),
            array('name'=>'duration',       'title'=>$this->setLocalization('Duration'),     'checked' => TRUE),
            array('name'=>'language',       'title'=>$this->setLocalization('Language'),'checked' => TRUE),
            array('name'=>'status',         'title'=>$this->setLocalization('Status'),  'checked' => TRUE),
            array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),'checked' => TRUE)
        );
    }

    private function getShortDropdownAttribute($adding_fields = array()){
        $return =  array(
            array('name'=>'name',           'title'=>$this->setLocalization('Title'),       'checked' => TRUE)
        );
        if (!empty($adding_fields)) {
            $return = array_merge($return, $adding_fields);
        }
        $return[] = array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),   'checked' => TRUE);
        return $return;
    }
}