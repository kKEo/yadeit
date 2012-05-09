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
		
		if (strlen($cond) === 0) {
			return "1 = 1";
		}

		$cond = str_replace('UID', Yii::app()->user->id, $cond);
		$cond = str_replace('READY', '0', $cond);
		$cond = str_replace('INPROGRESS', '1', $cond);
		$cond = str_replace('WAITING', '2', $cond);
		$cond = str_replace('HANGED', '3', $cond);
		$cond = str_replace('CLOSED', '5', $cond);
		
		return $cond;
	}
}