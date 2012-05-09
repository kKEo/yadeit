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
	
}