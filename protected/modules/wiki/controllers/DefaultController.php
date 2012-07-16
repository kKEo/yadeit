<?php
class DefaultController extends Controller {

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
	
	public function actionIndex() {
		
		$dataProvider = new CActiveDataProvider('Article');
		
		$this->render('index', array(
			'dataProvider'=>$dataProvider,	
		));
	}
	
	public function actionView() {
		
		$id = Yii::app()->request->getQuery("id");
		
		$model = null;
		
		if ($id !== null) {
			$model = Article::model()->findByPk($id);
		}
		
		$cri = new CDbCriteria();
		$cri->select = array('id', 'title', 'contentType', 'content', 'position');
		$cri->condition = ' articleId = '.$id;
		$cri->order = 'position, after';
		
		$sections = Section::model()->findAll($cri);
		
		$this->render('view', array(
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
				
				if ($model->update(array('title', 'content', 'position'))) {
					$ret['status'] = 0;
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
	
	public function actionNewArticle() {
		
		$model = new Article();
		$ret = array();
		
		if (isset($_POST['Article'])) {
			$model->attributes = $_POST['Article'];	
			if ($model->save()){
				$ret['status'] = 0;
				$ret['message'] = 'Saved successfully';
			} else {
				$ret['status'] = 1;
				$ret['errors'] = $model->getErrors();
			}
		}
		
		echo CJSON::encode($ret);
	}
	
	public function actionNewSection() {
		
	}
}