
<div id="article-new-dialog" style="display: none">
	<form id="article-form">
		<input id="article-id" type="hidden" name="Article[id]" /> Tytul: <input
			id="article-title" type="text" name="Article[title]" /> <br />
		Abstract:<br />
		<textarea id="article-abstract" rows="5" cols="40"
			name="Article[abstract]"></textarea>
		<br /> <input id="article-new-submit" type="submit"
			value="Dodaj artykul" />
	</form>
</div>

<?php

Yii::app()->getClientScript()->registerCoreScript('jquery.ui');
$coreUrl = Yii::app()->getClientScript()->getCoreScriptUrl();
Yii::app()->getClientScript()->registerCssFile($coreUrl.'/jui/css/base/jquery-ui.css');
Yii::app()->getClientScript()->registerCssFile('css/jquery-ui.css');

//Yii::app()->clientScript->registerScriptFile('js/cleditor/jquery.cleditor.min.js');
//Yii::app()->clientScript->registerCssFile('js/cleditor/jquery.cleditor.css');

Yii::app()->clientScript->registerScript('new-article-dialog', '
	
	var articleNewDialog = $("#article-new-dialog");
	
	$("<a href=\"#\">(add new)</a>").appendTo("h3").bind("click", function(){
		articleNewDialog.dialog({
			title:"Nowy artykul",
			width: 500, 
			height: 300, 
			modal: true,
		});
		return false;
	});

	$("#article-new-submit").unbind("click").bind("click", function(){
		$.post("'.$this->createUrl('newArticle').'",
		$("#article-form").serialize(),
		function(data){
			console.log(data);
			if (data.status > 0) {
				alert("Save failed.");
			}
			articleNewDialog.dialog("close");
			$.fn.yiiGridView.update("'.$id.'",{});
		},
		"json"
		);
		return false;
	});
	
	
');
