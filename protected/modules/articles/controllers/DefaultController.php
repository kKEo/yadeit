<?php
class DefaultController extends Controller {

	public $defaultAction = 'Admin';
	
	public $layout = '/default/layout';
	
	public function filters() {
		return array(
			'accessControl',
		);
	}
	
	public function accessRules() {
		return array(
			array('allow',
				'actions'=>array('index','view'),
				'users'=>array('*'),
			),
			array('allow',
				'users'=>array('@'),
				'expression'=>'$user->isAdmin()',
			),
			array('deny',
				'users'=>array('*'),
			),
		);
	}
	
	public function actions(){
		return array(
			'comments'=>array(
				'class'=>'comments.components.CommentsAction',
				'category'=>11,
			),
			'deleteComment'=>array(
				'class'=>'comments.components.DeleteCommentAction',
			),
		);
	}
	
	
	public function actionPreviewSection(){
		$content = Yii::app()->request->getParam('c');		
		$this->beginWidget('CMarkdown');
		echo $content;
		$this->endWidget();
	}
	
	public function actionPhotos($id) {
		
		$files = array();
		
		if ($handle = opendir('images/articles/'.$id)) {
		    while (false !== ($entry = readdir($handle))) {
		        if ($entry != "." && $entry != "..") {
		            $files[] = 'images/articles/'.$id.'/'.$entry;
		        }
		    }
		    closedir($handle);
		}
		
		$this->render('photos', array(
			'files'=>$files,
		));
		
	}
	
	public function actionAdmin() {
		
		$dataProvider = new CActiveDataProvider('Article');
		
		$cri = $dataProvider->getCriteria();
		$cri->condition = ' status < '.Article::REMOVED;
		
		$dataProvider->setSort(array(
			'defaultOrder' => array('status'=>true,'pinned'=>true, 'created'=>true),
			'attributes' => array(
				'title' => array(
					'default'=>'asc'),
				'created' => array(
					'default'=>'desc'),'id',
				'status' => array(
					'default'=>'desc',
				),
				'displays' => array(
					'default'=>'desc'),
				'pinned' => array(
					'default'=>'desc'),
			),
			'params'=>array(),
		));
		
		$this->render('admin', array(
			'dataProvider'=>$dataProvider,	
		));
	}
	
//	public function actionComments($id){
//		$article = Article::model()->findByPk($id);
//		
//		$this->render('comments',array(
//			'article'=>$article,	
//		));
//		
//	}
	
	public function actionView() {
		
		//$this->layout = '//layouts/main';
		
		$id = Yii::app()->request->getParam("id");
		
		
		$article = null;
		
		if($id != null) {
			$article = Article::model()->findByPk($id);
		} else {
			$seoTitle = Yii::app()->request->getQuery('title');
			$article = Article::model()->findByAttributes(array('seoTitle'=>$seoTitle));
			$id = $article->id;
		}
		
		if ($id !== null && isset($_POST['Comment'])){

			Yii::import('comments.models.Comment');
			
			$comment = new Comment;
			$comment->attributes = $_POST['Comment'];
			$comment->itemId = $id;
			$comment->category = Comment::CAT_ARTICLE;
			$comment->status = Comment::STATUS_PUBLISHED;
			
			if (!$comment->save()){
				throw new Exception('Cannot save comment: '.CJSON::encode($comment->getErrors()));
			}
		}

		if ($article && !$article->isAllowed()){
			throw new Exception('Nie masz uprawnien do tego artykułu.');
		}
		
		$this->pageTitle = $article->title;
		
		
		$cri = new CDbCriteria();
		$cri->select = array('id', 'title', 'contentType', 'content', 'position', 'status');
		$cri->condition = ' articleId = '.$id.' and status = '.Article::PUBLISHED;
		$cri->order = 'position, after';
		
		$sections = Section::model()->findAll($cri);
		
		Article::model()->updateCounters(array('displays'=>1),'id = '.$id);
		
		$this->render('view', array(
			'article'=>$article,
			'sections'=>$sections,
		));
		
	}
	
	public function actionUpdate() {
		
//		$this->layout = '//layouts/column2f';
		
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
	
	
	public function actionIndex(){
		
//		$this->layout = '//layouts/column2f';
		
		$articlesModel = Article::model()->published()->mostRecent();
		
		$dataProvider=new CActiveDataProvider($articlesModel);
		
		$dataProvider->setSort(array(
			'defaultOrder' => array('created'=>true),
			'attributes' => array(
				'title' => array(
					'default'=>'asc'),
				'created',
				'displayed' => array(
					'default'=>'desc'),
				'like' => array(
					'default'=>'desc'),
			),
			'params'=>array(),
		));
		
		
		$this->render('index', array(
			'dataProvider'=>$dataProvider,	
		));
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
						$ret['object'] = $model->attributes;
						$ret['message'] = 'Saved successfully';
						
						Yii::app()->getModule('events')->log('Nowy artykuł został dodany.');
						
						
					} else {
						$ret['status'] = 1;
						$ret['errors'] = $model->getErrors();
					}
				} else {
					$model->attributes = $_POST['Article'];	
					if ($model->update('title', 'seoTitle', 'authorId', 'abstract', 'status', 'avatar')) {
						$ret['status'] = 0;
						$ret['object'] = $model->attributes;
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