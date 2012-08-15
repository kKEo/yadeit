<?php

/**
 * This is the model class for table "wiki_section".
 *
 * The followings are the available columns in table 'wiki_section':
 * @property integer $id
 * @property string $title
 * @property string $content
 * @property string $contentType
 * @property integer $position
 * @property integer $after
 * @property integer $articleId
 * @property integer $createdBy
 * @property integer $created
 */
class Section extends CActiveRecord
{
	const DRAFT = 0;
	const PUBLISHED = 1;
	const REMOVED = 2;
	
	/**
	 * Returns the static model of the specified AR class.
	 * @param string $className active record class name.
	 * @return Section the static model class
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
		return 'wiki_section';
	}

	/**
	 * @return array validation rules for model attributes.
	 */
	public function rules()
	{
		// NOTE: you should only define rules for those attributes that
		// will receive user inputs.
		return array(
			array('content, articleId', 'required'),
			array('position, after, articleId, status', 'numerical', 'integerOnly'=>true),
			array('title', 'length', 'max'=>200),
			array('id, title, content, position, after, articleId', 'safe', 'on'=>'search'),
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
	public function attributeLabels(){
		
		return array(
			'id' => 'ID',
			'title' => 'Title',
			'content' => 'Content',
			'contentType' => 'Content Type',
			'position' => 'Position',
			'after' => 'After',
			'articleId' => 'Article',
			'createdBy' => 'Created By',
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
		$criteria->compare('title',$this->title,true);
		$criteria->compare('content',$this->content,true);
		$criteria->compare('contentType',$this->contentType,true);
		$criteria->compare('position',$this->position);
		$criteria->compare('after',$this->after);
		$criteria->compare('articleId',$this->articleId);
		$criteria->compare('createdBy',$this->createdBy);
		$criteria->compare('created',$this->created);

		return new CActiveDataProvider($this, array(
			'criteria'=>$criteria,
		));
	}
	
	public function beforeSave() {
		
		if ($this->isNewRecord) {
			
			$this->createdBy = Yii::app()->user->id;
			$this->created = time();	

			if (empty($this->position)){
				$this->position = 99;
			}
			
		}
	
		return true;
	}
	
	public function hideSection(){
		$this->status = self::DRAFT;
		return $this->update(array('status'));
	}
	
	public function publishSection(){
		$this->status = self::PUBLISHED;
		return $this->update(array('status'));
	}
	
	public function removeSection(){
		$this->status = self::REMOVED;
		return $this->update(array('status'));
	}
	
	public function getStatusLiteral(){
		switch ($this->status) {
			case self::DRAFT: return 'Robocza'; break;
			case self::PUBLISHED: return 'Opublikwana'; break;
			case self::REMOVED: return 'Usunieta'; break;
		}
		return 'Error';
	}
}