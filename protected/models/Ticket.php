<?php
class Ticket extends CActiveRecord {

	public static function model($className = __CLASS__) {
		return parent::model($className);
	}

	public function tableName(){
		return "tickets";
	}

	public function rules(){
		return array(
			array('subject,description','required'),
		);
	}

	public function relations(){
		return array(
			'project'=>array(CActiveRecord::BELONGS_TO, 'Project', 'projectId'),
			'author'=>array(CActiveRecord::BELONGS_TO, 'User', 'authorId'),
			'updatedBy'=>array(CActiveRecord::BELONGS_TO, 'User', 'updatedBy'),
			'assignee'=>array(CActiveRecord::BELONGS_TO, 'User', 'assignedTo'),
			);
	}

	public function beforeSave(){
		if (parent::beforeSave()){
				
			if ($this->isNewRecord){
				$this->projectId = 4;
				$this->categoryId = 0;
				$this->authorId = Yii::app()->user->id;
				$this->created = $this->updated = time();
			}
				
			return true;
		}
		return false;
	}

	public static function getStatuses(){
		return array(
			'v0'=>array('name'=>'zrobiony', 'icon'=>'edit'),
			'v1'=>array('name'=>'realizowany', 'icon'=>'edit'),
			'v2'=>array('name'=>'oczekujący', 'icon'=>'edit'),
			'v3'=>array('name'=>'zawieszony', 'icon'=>'edit'),	
			);
	}

	public function getStatus(){
		$statuses = Ticket::getStatuses();
		return $statuses['v'.$this->status]['name'];
	}

	public static function getPriorities() {
		return array(
			'v0'=>array('name'=>'niski'),
			'v1'=>array('name'=>'normalny'),
			'v2'=>array('name'=>'wysoki'),
			'v3'=>array('name'=>'b.wysoki'),
			);
	}

	public function getPriority() {
		$p = Ticket::getPriorities();
		return $p['v'.$this->priority]['name'];
	}

	public function getIterator(){
		$attributes=$this->getAttributes();
		$attributes['project'] = $this->getRelated('project')->getAttributes();

		return new CMapIterator($attributes);
	}

	public function getHistory($id){
		$dataProvider = new CSqlDataProvider(
			'select t.id, updated, username 
			   from tickets t 
		       join tbl_user u 
		         on t.updatedBy = u.id 	
		      where t.pid = '.$id);
		return $dataProvider->getData();
	}
}