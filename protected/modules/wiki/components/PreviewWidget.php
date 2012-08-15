<?php
class PreviewWidget extends CWidget {
	
	public $itemId;
	public $linkContainerId = "article-actions";
	
	public function init(){
		
		Yii::app()->getClientScript()->registerCoreScript('jquery.ui');
		$coreUrl = Yii::app()->getClientScript()->getCoreScriptUrl();
		Yii::app()->getClientScript()->registerCssFile($coreUrl.'/jui/css/base/jquery-ui.css');
		Yii::app()->getClientScript()->registerCssFile('css/jquery-ui.css');
		
		
	}
	
	public function run(){
		
		$this->render('preview');
		
		Yii::app()->clientScript->registerScript('preview-dialog', '

var previewDialog = $("#preview-dialog");

var loadPreview = function(id){
	
	$("#preview-container").load(
		"'.$this->getController()->createUrl('view').' #content",
		{id:'.$this->itemId.'}
	);

}

$("<a href=\"#\">Podglad</a>").appendTo("#'.$this->linkContainerId.'").bind("click", function(){
	loadPreview('.$this->itemId.');
	previewDialog.dialog({
			//title: isNew?"Nowy paragraf":"Edytuj paragraf",
			width: 600, 
			height: 500, 
			modal: true,
		});
});
		
		
');
	}
	
}