<h3><?php echo $model->title; ?> </h3>

<div id="article-new-text-dialog" style="display:none;">
	<form id="article-new-text-form">
		<input type="hidden" id="text-section-id" name="Section[id]"/>
		<input type="hidden" id="text-section-articleId" name="Section[articleId]" value="<?php echo $model->id?>"/>
		Title: <input type="text" id="text-section-title" name="Section[title]" size="40"/> <br/>
		<input type="hidden" id="text-section-type" name="Section[contentType]" value="Text"/>
		Content: <br/>
		<textarea id="text-section-content" rows="10" cols="65" name="Section[content]"></textarea><br/>
		Position: <input type="text" id="text-section-position" name="Section[position]" size="4"/> <br/>
		<br/>
		<input id="text-section-submit" type="submit" value="Wyslij"/>
	</form>
</div>

<?php 

foreach ($sections as $section) {
	
	$this->renderPartial('_renderSection'.$section['contentType'], array(
		'section'=>$section,	
	));
	
}

Yii::app()->getClientScript()->registerCoreScript('jquery.ui');
$coreUrl = Yii::app()->getClientScript()->getCoreScriptUrl();
Yii::app()->getClientScript()->registerCssFile($coreUrl.'/jui/css/base/jquery-ui.css');
Yii::app()->getClientScript()->registerCssFile('css/jquery-ui.css');


Yii::app()->clientScript->registerScript('article-new-text', 
'
var articlesActions = $("#article-actions");
var textDialog = $("#article-new-text-dialog");
var articleNewTextForm = $("#article-new-text-form");

var submitFormHandler = function(url){

	$("#text-section-submit").unbind("click").bind("click",function(){
		$.post(url,
			$("#article-new-text-form").serialize(),
			function(data){
				console.log(data);
				textDialog.dialog("close");
			},
			"json"
		);
		return false;
	});
}  

$("<a href=\"#\">Add paragraph</a>").appendTo(articlesActions).bind("click", function(){
	submitFormHandler("'.$this->createUrl('addSection').'");
	showDialog();
	return false;
});

var showDialog = function(id) {

	var isNew = (typeof(id) === "undefined");
	
	if (!isNew) {
		$.getJSON("'.$this->createUrl('getSection').'",
		{"id":id},function(data){
			$("#text-section-id").attr("value", data["id"]);
			$("#text-section-articleId").attr("value", data["articleId"]);
			$("#text-section-title").attr("value", data["title"]);
			$("#text-section-content").attr("value", data["content"]);
			$("#text-section-position").attr("value", data["position"]);
		});
	}
	
	textDialog.dialog({
		title: isNew?"Nowy paragraf":"Edytuj paragraf",
		width: 600, 
		height: 400, 
		modal: true,
	});
	
	submitFormHandler("'.$this->createUrl('updateSection').'");
	
}

var refreshActions = function(){

	$(".sectionFooter").find(".editLink").unbind("click").bind("click", function(){
		var id = $(this).parent().parent().attr("id").match(/\d+/)[0];
		showDialog(id);
		
		return false;
	});
}

refreshActions();

');


?>
<div id="article-actions"></div>