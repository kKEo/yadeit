<?php
class WikiModule extends CWebModule {
	
	public $baseScriptUrl;
	
	public function init (){
		$this->setImport(array(
			'wiki.models.*',	
		));
		
		$assets = Yii::getPathOfAlias('wiki.assets');
			
		if (YII_DEBUG) {
			$baseUrl = Yii::app()->assetManager->publish($assets, false, -1, true);
		} else {
			$baseUrl = Yii::app()->assetManager->publish($assets);
		}
			
		$this->baseScriptUrl = $baseUrl;
		
		Yii::app()->clientScript->registerCssFile($baseUrl.'/wiki.css');
		
	}
	
}
