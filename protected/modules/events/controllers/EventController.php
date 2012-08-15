<?php

class EventController extends Controller {
	
	public $defaultAction = 'admin';

	public function __construct($id, $module) {
		parent::__construct($id, $module);
		$this->layout = $module->layout;
	}
	
	public function filters(){
		return array(
			'accessControl',
		);	
	}

	/**
	 * Manages all models.
	 */
	public function actionAdmin()
	{
		$model=new Event('search');
		$model->unsetAttributes();  // clear any default values
		if(isset($_GET['Event'])) {
			$model->attributes=$_GET['Event'];
		}	

		$dataProvider = $model->search();
		$dataProvider->getPagination()->setPageSize(50);
		
		$this->render('admin',array(
			'dataProvider'=>$dataProvider,
			'model'=>$model
		));
	}
	
	public function actionSessions(){
		$dataProvider=new CActiveDataProvider('SessionHistory');
		
		$dataProvider->setSort(array(
					'defaultOrder' => array('created'=>true),
		));
		
		$this->render('sessions',array(
					'dataProvider'=>$dataProvider,
		));
	}
	
}	