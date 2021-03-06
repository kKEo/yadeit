
<div id="article-new-dialog" style="display: none">
	<form id="article-form">
		<input id="article-id" type="hidden" name="Article[id]" /> 
		Tytuł: <input
			id="article-title" type="text" name="Article[title]" size="45"/> <br />
		Tytuł-Seo: <input
			id="article-seoTitle" type="text" name="Article[seoTitle]" size="40"/> <br />
		Avatar: <input id="article-avatar" type="text" name="Article[avatar]" size="40"/> <br/>	
		Abstract:<br />
		<textarea id="article-abstract" rows="5" cols="50"
			name="Article[abstract]"></textarea>
		<br/>
		AuthorId: <input
			id="article-authorId" type="text" name="Article[authorId]" size="5"/> 
		Status: <?php echo CHtml::dropDownList('Article[status]', '0', array(Section::DRAFT => 'Robocze', Section::PUBLISHED => 'Opublikowane'), array('id'=>'article-status'))?>
		Kolejność: <input id="article-pinned" type="text" name="Article[pinned]" size="5"/>
		<br /> <input id="article-new-submit" type="submit"
			value="Zapisz" />
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
	
	var showArticleDialog = function (isNew) {
	
		articleNewDialog.dialog({
			title: (isNew)?"Nowy artykuł":"Zapisz zmiany",
			width: 520, 
			height: 330, 
			modal: true,
		});
	}
	
	var overrideArticleSubmit = function() {
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
	}
	
		var initializeArticleForm = function(id){
			$.getJSON("'.$this->createUrl('article').'", {"id":id}, function(data){
				if (typeof data !== "undefined") {
					$("#article-form > #article-id").attr("value",data.id);
					$("#article-form > #article-title").attr("value",data.title);
					$("#article-form > #article-seoTitle").attr("value",data.seoTitle);
					$("#article-form > #article-avatar").attr("value",data.avatar);
					$("#article-form > #article-abstract").attr("value",data.abstract);
					$("#article-form > #article-status").attr("value",data.status);
					$("#article-form > #article-authorId").attr("value",data.authorId);
					$("#article-form > #article-pinned").attr("value",data.pinned);
					showArticleDialog(false);
				} else {
					console.log(data);
				}
			});
		}

	var overrideArticleUpdateButton = function() {
		$("#wiki-grid").find("a.updateArticle").unbind("click").bind("click", function(){
			var id = this.href.match(/\d+$/)[0];
			initializeArticleForm(id);
			return false;
		});
	}
	
	$("<a href=\"#\">Nowy artykuł</a>").appendTo("#'.$linkContainerId.'").bind("click", function(){
		$("#article-form > #article-id").attr("value","");
		$("#article-form > #article-title").attr("value","");
		$("#article-form > #article-seoTitle").attr("value","");
		$("#article-form > #article-avatar").attr("value","");
		$("#article-form > #article-abstract").attr("value","");
		$("#article-form > #article-status").attr("value","0");
		$("#article-form > #article-authorId").attr("value","");
		$("#article-form > #article-pinned").attr("value","0");
	
		showArticleDialog(true);
		return false;
	});

	overrideArticleSubmit();
	overrideArticleUpdateButton();
	
');
