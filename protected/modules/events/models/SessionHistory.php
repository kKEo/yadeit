<?php
class SessionHistory extends CActiveRecord {
	
	public static function model($className = __CLASS__){
		return parent::model($className);
	}
	
	public function tableName(){
		return 'session_history';
	}
	
	public function rules(){
		return array(
			array('category, itemId', 'required'),
		);
	}
	
	public static function trace($category, $itemId){
		$entry = new SessionHistory();
		$entry->category = $category;
		$entry->itemId = $itemId;
		$entry->sessionId = Yii::app()->getSession()->getSessionID();//.'|'.$_SERVER['HTTP_USER_AGENT'];
		$entry->ip = $_SERVER['REMOTE_ADDR'];
		$entry->userId = Yii::app()->getUser()->id;
		if ($entry->userId === null) {
			$entry->userId = 0;
		}
		$entry->created = time();
		
		$entry->save();
	}
	
}