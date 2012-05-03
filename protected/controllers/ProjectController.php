<?php
class ProjectController extends Controller {
	
	public function actionIndex() {
		$viewData = array();
		$viewData['provider'] = new CActiveDataProvider('Project');
		
		$viewData['provider']->getSort()->defaultOrder = 'id desc';
		
		$this->render('index', $viewData);
	}
	
	public function actionCreate() {
		
		$model = new Project;
		$res = array('status'=>1);
		
		if (isset($_POST['Project'])) {
			$model->attributes = $_POST['Project'];
			
			if ($model->save()) {
				$res['status'] = 0;
			} else {
				$res['errors'] = $model->getErrors();
			}
		}
		
		echo CJSON::encode($res);

	}
	
	
	
}