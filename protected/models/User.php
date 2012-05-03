<?php
class User extends CActiveRecord {
	
	public static function model($className = __CLASS__) {
		return parent::model($className);
	}
	
	public function tableName() {
		return 'tbl_user';
	}
	
	public static function getUsers(){
		
		$users = User::model()->findAll();
		
		$array = array();
		
		foreach ( $users as $user ) {
			$array['v'.$user->id]	=  array('name'=>$user->username);
		}
		
		return $array;
	}

	
}