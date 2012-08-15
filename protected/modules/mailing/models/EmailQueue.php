<?php

/**
 * This is the model class for table "{{email_queue}}".
 *
 * The followings are the available columns in table '{{email_queue}}':
 * @property integer $id
 * @property integer $userId
 * @property string $mail_to
 * @property string $mail_from
 * @property string $from_name
 * @property string $subject
 * @property string $message
 * @property integer $category
 * @property integer $priority
 * @property integer $added_time
 * @property integer $sent_time
 */
class EmailQueue extends CActiveRecord
{
	/**
	 * Returns the static model of the specified AR class.
	 * @return EmailQueue the static model class
	 */
	public static function model($className=__CLASS__)
	{
		return parent::model($className);
	}

	/**
	 * @return string the associated database table name
	 */
	public function tableName()
	{
		return 'email_queue';
	}

	/**
	 * @return array validation rules for model attributes.
	 */
	public function rules()
	{
		// NOTE: you should only define rules for those attributes that
		// will receive user inputs.
		return array(
			array('mail_to, mail_from, from_name, subject, message', 'required'),
		array('category, priority', 'numerical', 'integerOnly'=>true),
		array('mail_to, mail_from, from_name', 'length', 'max'=>200),
		array('subject', 'length', 'max'=>500),
		// The following rule is used by search().
		// Please remove those attributes that should not be searched.
		array('id, mail_to, mail_from, from_name, subject, message, category, priority, added_time, sent_time', 'safe', 'on'=>'search'),
		);
	}

	/**
	 * @return array relational rules.
	 */
	public function relations(){
		return array();
	}

	/**
	 * @return array customized attribute labels (name=>label)
	 */
	public function attributeLabels()
	{
		return array(
			'id' => 'ID',
			'mail_to' => 'Mail To',
			'mail_from' => 'Mail From',
			'from_name' => 'From Name',
			'subject' => 'Subject',
			'message' => 'Message',
			'category' => 'Category',
			'priority' => 'Priority',
			'added_time' => 'Added Time',
			'sent_time' => 'Sent Time',
			);
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 * @return CActiveDataProvider the data provider that can return the models based on the search/filter conditions.
	 */
	public function search()
	{
		// Warning: Please modify the following code to remove attributes that
		// should not be searched.

		$criteria=new CDbCriteria;

		$criteria->compare('id',$this->id);
		$criteria->compare('mail_to',$this->mail_to,true);
		$criteria->compare('mail_from',$this->mail_from,true);
		$criteria->compare('from_name',$this->from_name,true);
		$criteria->compare('subject',$this->subject,true);
		$criteria->compare('message',$this->message,true);
		$criteria->compare('category',$this->category);
		$criteria->compare('priority',$this->priority);
		$criteria->compare('added_time',$this->added_time);
		$criteria->compare('sent_time',$this->sent_time);

		return new CActiveDataProvider($this, array(
			'criteria'=>$criteria,
			));
	}

	public function beforeSave() {

		if (parent::beforeSave()){
			if ($this->isNewRecord){
				$this->added_time = time();

				if (Yii::app()->hasComponent('user')){
					$this->userId = Yii::app()->user->id;
				}

				if (!$this->userId) {
					$this->userId = -1;
				}
			}
			return true;
		}

		return false;
	}
}