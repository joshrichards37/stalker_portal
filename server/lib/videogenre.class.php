<?php

use Stalker\Lib\Core\Mysql;
use Stalker\Lib\Core\Stb;

class VideoGenre
{
    private $language;
    private $range = array();
    private $order = array();

    /**
     * @deprecated
     */
    public function setLocale($language){
        $this->language = $language;

        Stb::getInstance()->initLocale($this->language);
    }

    public function setRange($range = array()){
        if (is_numeric($range)) {
            $this->range = array($range);
        } else if (is_array($range)) {
            $this->range = $range;
        }
    }

    public function setOrder($field = 'title', $dir = 'ASC'){
        $this->order[$field] = $dir;
    }

    public function getAll($pretty_id = false, $group = true, $include_internal_id = false){

        $genres = Mysql::getInstance()->select('*')->from('cat_genre');

        if ($group){
            $genres->select('GROUP_CONCAT(category_alias) as categories, GROUP_CONCAT(id) as ids')->groupby('title');
        }else{
            $genres->select('category_alias as categories');
        }

        if (!empty($this->range)) {
            $genres->in('id', $this->range);
        }

        if (!empty($this->order)) {
            $genres->orderby($this->order);
        }

        $genres = $genres->get()->all();

        $genres = array_map(
            function($item) use ($pretty_id, $include_internal_id){

                if ($include_internal_id){
                    $item['_id'] = $item['id'];
                }

                if ($pretty_id){
                    $item['id'] = preg_replace(array("/\s/i", "/[^a-z0-9-]/i"), array("-", ""), $item['title']);
                }

                $item['original_title'] = $item['title'];
                $item['title']          = _($item['title']);
                $item['categories']     = array_map(function($id) {
                        return intval($id);
                    }, Mysql::getInstance()->getInstance()
                             ->from('media_category')
                             ->in('category_alias', explode(',', $item['categories']))
                             ->get()
                             ->all('id')
                    );

                return $item;
            }, $genres);

        return $genres;
    }

    public function getIdMap(){

        $genres = $this->getAll(true, false, true);

        $map = array();

        foreach ($genres as $genre){
            $map[$genre['_id']] = $genre['id'];
        }

        return $map;
    }

    public function getById($id, $pretty_id = false){

        if ($pretty_id){
            $genres = $this->getAll($pretty_id);

            $genres = array_filter($genres, function($genre) use ($id){
                return $id == $genre['id'];
            });

            if (empty($genres)){
                return null;
            }

            $titles = array_map(function($genre){
                return $genre['original_title'];
            }, array_values($genres));

            return Mysql::getInstance()->from('cat_genre')->in('title', $titles)->get()->all();
        }else{
            return Mysql::getInstance()->from('cat_genre')->where(array('id' => intval($id)))->get()->first();
        }
    }

    public function getByIdAndCategory($id, $category_id, $pretty_id = false){

        $category = new VideoCategory();
        $category = $category->getById($category_id, $pretty_id);

        if (empty($category)){
            return null;
        }

        if ($pretty_id){
            $genres = $this->getAll($pretty_id, false, true);

            $genres = array_filter($genres, function($genre) use ($id, $category){
                return $id == $genre['id'] && $genre['category_alias'] == $category['category_alias'];
            });

            if (empty($genres)){
                return null;
            }

            $genres = array_values($genres);

            return Mysql::getInstance()->from('cat_genre')->where(array('id' => $genres[0]['_id']))->get()->first();
        }else{
            return Mysql::getInstance()->from('cat_genre')->where(array('id' => intval($id), 'category_alias' => $category['category_alias']))->get()->first();
        }
    }

    public function getByCategoryId($category_id, $pretty_id = false){

        $category = new VideoCategory();

        $category = $category->getById($category_id, $pretty_id);

        if (empty($category)){
            return array();
        }

        $genres = Mysql::getInstance()->from('cat_genre')->where(array('category_alias' => $category['category_alias']))->get()->all();

        if ($pretty_id){
            $genres = array_map(function($genre){
                $genre['_id']   = $genre['id'];
                $genre['id']    = preg_replace(array("/\s/i", "/[^a-z0-9-]/i"), array("-", ""), $genre['title']);
                $genre['title'] = _($genre['title']);
                return $genre;
            },$genres);
        }

        return $genres;
    }
}