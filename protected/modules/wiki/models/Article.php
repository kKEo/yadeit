<?php

/**
 * This is the model class for table "wiki_article".
 *
 * The followings are the available columns in table 'wiki_article':
 * @property integer $id
 * @property string $title
 * @property string $abstract
 * @property string $tags
 * @property integer $createdBy
 * @property integer $created
 */
class Article extends CActiveRecord
{
	/**
	 * Returns the static model of the specified AR class.
	 * @param string $className active record class name.
	 * @return Article the static model class
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
		return 'wiki_article';
	}

	/**
	 * @return array validation rules for model attributes.
	 */
	public function rules()
	{
		// NOTE: you should only define rules for those attributes that
		// will receive user inputs.
		return array(
			array('title', 'required'),
		array('title, tags', 'length', 'max'=>200),
		array('abstract', 'length', 'max'=>1000),
		// The following rule is used by search().
		// Please remove those attributes that should not be searched.
		array('id, title, abstract, tags, createdBy, created', 'safe', 'on'=>'search'),
		);
	}

	/**
	 * @return array relational rules.
	 */
	public function relations()
	{
		// NOTE: you may need to adjust the relation name and the related
		// class name for the relations automatically generated below.
		return array(
			);
	}

	/**
	 * @return array customized attribute labels (name=>label)
	 */
	public function attributeLabels()
	{
		return array(
			'id' => 'ID',
			'title' => 'Title',
			'abstract' => 'Abstract',
			'tags' => 'Tags',
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
		$criteria->compare('abstract',$this->abstract,true);
		$criteria->compare('tags',$this->tags,true);
		$criteria->compare('createdBy',$this->createdBy);
		$criteria->compare('created',$this->created);

		return new CActiveDataProvider($this, array(
			'criteria'=>$criteria,
			));
	}

	protected function beforeSave() {
		
		if (parent::beforeSave()) {
			if ($this->isNewRecord) {
				$this->createdBy = Yii::app()->user->id;
				$this->created = time();
			}

			return true;
		}
		return false;
	}
}