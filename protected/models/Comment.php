<?php
class Comment extends CActiveRecord {
	
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	
	public function tableName(){
		return 'comments';
	}
	
	public function rules(){
		return array(
			array('itemId,comment','required'),
		);
	}
	
	public function relations(){
		return array(
			'user'=>array(CActiveRecord::BELONGS_TO, 'User', 'userId'),
		);
	}		
	
	public function beforeSave() {
		
		if (parent::beforeSave()) {
			
			if ($this->isNewRecord) {
				$this->created = time();
				$this->userId = Yii::app()->user->id;
			}
			
			return true;
		}
		
		return false;
	}
	
	public function getIterator(){
		$attributes = $this->getAttributes();
		$user = $this->getRelated('user');
		if ($user !== null) {
			$attributes['user'] = $user->getAttributes();
		} else {
			$attributes['user'] = array('username'=>'brak');
		}

		return new CMapIterator($attributes);
	}
	
}