<?php

/**
 * This is the model class for table "tbl_event".
 *
 * The followings are the available columns in table 'tbl_event':
 * @property integer $id
 * @property integer $category
 * @property integer $userId
 * @property string $message
 * @property string $created
 *
 * The followings are the available model relations:
 */
class Event extends CActiveRecord
{
	
	public static function model($className=__CLASS__){
		return parent::model($className);
	}

	public function tableName()
	{
		return 'event';
	}

	public function rules()
	{
		// NOTE: you should only define rules for those attributes that
		// will receive user inputs.
		return array(
			array('category, userId', 'numerical', 'integerOnly'=>true),
			array('message, created', 'safe'),
			// The following rule is used by search().
			// Please remove those attributes that should not be searched.
			array('id, category, userId, message, created', 'safe', 'on'=>'search'),
		);
	}

	/**
	 * @return array relational rules.
	 */
	public function relations(){
		return array(
			'user'=>array(self::BELONGS_TO, 'User', 'userId'),
		);
	}

	/**
	 * @return array customized attribute labels (name=>label)
	 */
	public function attributeLabels()
	{
		return array(
			'id' => 'ID',
			'category' => 'Category',
			'userId' => 'User',
			'message' => 'Message',
			'created' => 'Created',
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
		$criteria->compare('category',$this->category);
		$criteria->compare('userId',$this->userId);
		$criteria->compare('message',$this->message,true);
		$criteria->compare('created',$this->created,true);

		return new CActiveDataProvider(get_class($this), array(
			'criteria'=>$criteria,
		));
	}
	
	public static function log($message){
	
		$entry = new Event();
		$entry->userId = Yii::app()->user->id;
		$entry->message = $message;
		$entry->created = time();
		$entry->category= 0;
		
		$entry->save();
		
	}
	
	public function afterSave() {
		$mailingModule = Yii::app()->getModule('mailing');
		if (!$mailingModule) {
			throw new Exception('Mailing module not found.');
		} else {
			$mailingModule->enqueue(
				'krzysztof.maziarz@gmail.com',
				'yadeit@easywebsite.pl',
				'Yadeit',
				$this->message,
				1	
			);
		}
	}
}