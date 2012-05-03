<?php
class Filter extends CActiveRecord {
	
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}

	public function tableName(){
		return "filters";
	}	
	
	public function rules(){
		return array(
			array('name,condition,description,orderBy','required'),
		);
	}
	
	public function getCondition(){
		
		$cond = $this->condition;

		$cond = str_replace('UID', Yii::app()->user->id, $cond);
		$cond = str_replace('CLOSED', '0', $cond);
		$cond = str_replace('INPROGRESS', '1', $cond);
		
		return $cond;
	}
}