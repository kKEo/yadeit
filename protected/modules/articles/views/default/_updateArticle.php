<div id="article-update-container">

<div id="article-new-text-dialog" style="display:none;">
	<form id="article-new-text-form">
		<input type="hidden" id="text-section-id" name="Section[id]"/>
		<input type="hidden" id="text-section-articleId" name="Section[articleId]" value="<?php echo $model->id?>"/>
		Title: <input type="text" id="text-section-title" name="Section[title]" size="40"/> <br/>
		<input type="hidden" id="text-section-type" name="Section[contentType]" value="Text"/>
		
		<div style="clear: both;">
		Treść: 
			<a id="text-section-preview" href="#">Podgląd</a>
		</div>
		<textarea id="text-section-content" rows="22" cols="84" name="Section[content]"></textarea>
		<div id="text-section-content-preview" class="wikiSection"></div>
		<div>
		Position: <input type="text" id="text-section-position" name="Section[position]" size="4"/>
		Status: <?php echo CHtml::dropDownList('Section[status]', '0', array(Section::DRAFT => 'Robocze', Section::PUBLISHED => 'Opublikowane'), array('id'=>'text-section-status'))?>
		</div>
		<input id="text-section-submit" type="submit" value="Wyslij"/>
	</form>
</div>

<div id="article-view-content">
<?php 

foreach ($sections as $section) {
	
	$this->renderPartial('_renderSection'.$section['contentType'], array(
		'section'=>$section,
		'isUpdate'=>true,	
	));
	
}?>
</div>
<?php 

//$this->widget('articles.components.PreviewWidget', array(
//	'itemId'=>$model->id
//	));

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
				textDialog.dialog("close");
				
				window.location.href="";
			},
			"json"
		);
		return false;
	});
}  

var previewHandler = function() {

	$("#text-section-preview").unbind("click").bind("click", function() {
		var $content = $("#text-section-content");
		var $preview = $("#text-section-content-preview");
		var previewLink = $(this);
		
		if ($content.css("display") !== "none") {
			var url = "'.$this->createUrl('previewSection').'";
			$.post(url,{"c":$content.val()}, function(data){
				$preview.html(data).show();
				$content.hide();
				previewLink.text("Powrót");
			});
		} else {
			$preview.hide();	
			$content.show();
			previewLink.text("Podgląd");
		}
		return false;
	});
	
}

$("<a href=\"#\">Dodaj sekcje</a>").appendTo(articlesActions).bind("click", function(){
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
			$("#text-section-status").attr("value", data["status"]);
		});
	}
	
	textDialog.dialog({
		title: isNew?"Nowy paragraf":"Edytuj paragraf",
		width: 820, 
		height: 600, 
		modal: true,
	});
	
	submitFormHandler("'.$this->createUrl('updateSection').'");
	
	previewHandler();
}

var refreshActions = function(){

	$(".sectionFooter").find(".editLink").unbind("click").bind("click", function(){
		var id = $(this).parent().attr("id").match(/\d+/)[0];
		showDialog(id);
		
		return false;
	});
}

refreshActions();

');


?>
<div id="article-actions">
	<?php echo CHtml::link('Zarządzanie artykułami',array('admin'))?>
	<?php echo CHtml::link('Artykuł',array('view', 'id'=>$model->id))?>
</div>
</div>