<?php
class Project extends CActiveRecord {
	
	
	public function tableName() {
		return 'projects';
	}
	
	public function rules(){
		return array(
			array('name,description', 'required'),	
		);
		
	}
	
	public function beforeSave() {
		
		if ($this->isNewRecord){
			$this->created = time();
			$this->authorId = Yii::app()->user->id;
		}
		
		return true;
	}
	
}