<?php
class DefaultController extends Controller {

	public $defaultAction = 'Admin';
	
	public function filters()
	{
		return array(
			'accessControl',
		);
	}
	
	public function accessRules() {
		return array(
			array('allow', 
				'users'=>array('@'),
			),
			array('deny',
				'users'=>array('*'),
			),
		);
	}
	
	public function actionPreviewSection(){
		$content = Yii::app()->request->getParam('c');		
		$this->beginWidget('CMarkdown');
		echo $content;
		$this->endWidget();
	}
	
	public function actionAdmin() {
		
		$dataProvider = new CActiveDataProvider('Article');
		
		$cri = $dataProvider->getCriteria();
		$cri->condition = ' status < '.Article::REMOVED;
		
		$this->render('admin', array(
			'dataProvider'=>$dataProvider,	
		));
	}
	
	public function actionView() {
		
		$id = Yii::app()->request->getParam("id");
		
		$article = Article::model()->findByPk($id);
		
		if ($article && !$article->isAllowed()){
			throw new Exception('Nie masz uprawnien do tego artykulu.');
		}
		
		$cri = new CDbCriteria();
		$cri->select = array('id', 'title', 'contentType', 'content', 'position', 'status');
		$cri->condition = ' articleId = '.$id.' and status = '.Article::PUBLISHED;
		$cri->order = 'position, after';
		
		$sections = Section::model()->findAll($cri);
		
		$this->render('view', array(
			'article'=>$article,
			'sections'=>$sections,
		));
		
	}
	
	public function actionUpdate() {
		
		$id = Yii::app()->request->getQuery("id");
		
		$model = null;
		
		if ($id !== null) {
			$model = Article::model()->findByPk($id);
			
			if (!$model->isAllowed()){
				throw new Exception('Nie masz uprawnien do tego artykulu.');
			}
		}
		
		$cri = new CDbCriteria();
		$cri->select = array('id', 'title', 'contentType', 'content', 'position', 'status');
		$cri->condition = ' articleId = '.$id;
		$cri->order = 'position, after';
		
		$sections = Section::model()->findAll($cri);
		
		$this->render('update', array(
			'model'=>$model,
			'sections'=>$sections,
		));
		
		
	}
	
	public function actionGetSection($id){
		echo CJSON::encode(Section::model()->findByPk($id));
	}
	
	public function actionUpdateSection() {
		
		$ret = array();
		
		if (isset($_POST['Section'])) {
			
			$model = Section::model()->findByPk($_POST['Section']['id']);
			
			if (!$model) {

				$type = Yii::app()->request->getPost('Section');
	
				$model = null;
				switch ($type['contentType']) {
					
					case 'Text':
						$model = new TextSection();		 
						break;
					
					default:
						throw new Exception('Type not found: '.$type['contentType']);
				}

				$model->attributes = $_POST['Section'];
				
				if ($model->save()) {
					$ret['status'] = 0;
					$ret['message'] = 'Successfully added.';
				} else {
					$ret['status'] = 1;
					$ret['errors'] = $this->getErrors();
				}
				
			} else {
				$model->attributes = $_POST['Section'];
				
				if ($model->update(array('title', 'content', 'position', 'status'))) {
					$ret['status'] = 0;
					$ret['attributes'] = $model->attributes;
					$ret['message'] = 'Updated successfully';
				} else {
					$ret['status'] = 1;
					$ret['errors'] = $model->getErrors();
				}
			}
		}
		
		echo CJSON::encode($ret);
	}
	
	
	public function actionAddSection() {

		$type = Yii::app()->request->getPost('Section');
		
		$model = null;
		switch ($type['contentType']) {
			
			case 'Text':
				$model = new TextSection();		 
				break;
			
			default:
				throw new Exception('Type not found: '.$type['contentType']);
		}
		
		$ret = array();
		
		if (isset($_POST['Section'])) {
			$model->attributes = $_POST['Section'];
			
			if ($model->save()) {
				$ret['status'] = 0;
				$ret['message'] = 'Successfully added.';
			} else {
				$ret['status'] = 1;
				$ret['errors'] = $this->getErrors();
			}
		}
		
		echo CJSON::encode($ret);
		
	}
	
	public function actionArticle($id){
		$model = Article::model()->findByPk($id);
		echo CJSON::encode($model->attributes);
	}
	
	public function actionNewArticle() {
		
		$ret = array();
		
		if (isset($_POST['Article'])) {
			if (isset($_POST['Article']['id'])){
				$id = CHtml::encode($_POST['Article']['id']);
				$model = Article::model()->findByPk($id);
				
				if (!$model){
					$model = new Article();
					$model->attributes = $_POST['Article'];	
					$ret['error']='Article not found.';
					if ($model->save()){
						$ret['status'] = 0;
						$ret['message'] = 'Saved successfully';
					} else {
						$ret['status'] = 1;
						$ret['errors'] = $model->getErrors();
					}
				} else {
					if ($model->update('title', 'abstract', 'status')) {
						$ret['status'] = 0;
						$ret['message'] = 'Updated successfully';
					} else {
						$ret['status'] = 1;
						$ret['errors'] = $model->getErrors();
					}
					
				}
			}
		} else {
			$ret['status'] = 501;
			$ret['message'] = 'Wrong request';
		}
		
		echo CJSON::encode($ret);
	}
	
	public function actionDelete($id) {
		
		$article = Article::model()->findByPk($id);
		
		$sections = $article->section;
		
		foreach ($sections as $section) {
			$section->removeSection();
		}
		
		$article->removeArticle();
		
	}
	
	public function actionNewSection() {
		
	}
}