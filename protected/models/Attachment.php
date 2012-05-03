<?php
class Attachment extends CActiveRecord {

	public static function model($className = __CLASS__) {
		return parent::model($className);
	}

	public function tableName(){
		return "attachments";
	}

	public function relations(){
		return array(
			'issue'=>array(CActiveRecord::BELONGS_TO, 'Ticket', 'iid'),
			'author'=>array(CActiveRecord::BELONGS_TO, 'User', 'uid'),
			);
	}

	public function beforeSave() {

		if (parent::beforeSave()) {
			if ($this->isNewRecord) {
				$this->uid = Yii::app()->user->id;
				$this->cdt = time();
			}
			return true;
		}
		return false;
	}

	

}