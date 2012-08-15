<?php
class ArticlesModule extends CWebModule {
	
	public $baseScriptUrl;
	
	public $hasComments = false;
	
	public function init (){
		$this->setImport(array(
			'articles.models.*',	
		));
		
		$assets = Yii::getPathOfAlias('articles.assets');
			
//		if (YII_DEBUG) {
			$baseUrl = Yii::app()->assetManager->publish($assets, false, -1, true);
//		} else {
//			$baseUrl = Yii::app()->assetManager->publish($assets);
//		}
			
		$this->baseScriptUrl = $baseUrl;
		
		Yii::app()->clientScript->registerCssFile($baseUrl.'/articles.css');
		
	}
	
}
