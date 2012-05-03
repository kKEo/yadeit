

<?php
	Yii::app()->getClientScript()->registerCoreScript( 'jquery.ui' );
	Yii::app()->getClientScript()->registerCssFile('http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.17/themes/base/jquery-ui.css');
	Yii::app()->getClientScript()->registerCssFile('jquery-ui.css');
	
	Yii::app()->clientScript->registerScript('ticket-list',
'
$("#add-link").bind("click", function(){
	$("#add-dialog").dialog({
		modal: true,
		minWidth: 340,
		minHeight: 200,
		buttons: {
			Dodaj: function() {
			
				$.post("'.$this->createUrl('//project/create').'", 
					$("#add-form").serialize(), 
					function(data) {
						console.log(data);
					}, "json");
			
					
				$(this).dialog("close");
				
				},
			Anuluj: function() {$(this).dialog("close");},
		}	
	});
	return false;
});
');

$this->renderPartial('create');

$this->widget('zii.widgets.grid.CGridView', array(
	
	'dataProvider' => $provider,
	'columns'=>array(
		'id',
		'name',
		'description',
		array(
			'class'=>'CButtonColumn',
		),
	)	
));
