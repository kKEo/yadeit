<?php $form = $this->beginWidget('CActiveForm', array(
	'id'=>'compose-form',
	'enableAjaxValidation'=>false,
));?>

<div class="row">
	Nazwa mailingu: <?php echo $form->textField($model,'title',array('size'=>30,'maxlength'=>60)); ?>
</div>

<div class="row">
	Wzorzec: <?php echo $form->textField($model,'pattern',array('size'=>20,'maxlength'=>20, 'value'=>'%krma%')); ?>
</div>

<div class="row">
	Temat: <?php echo $form->textField($model,'subject',array('size'=>60,'maxlength'=>100)); ?>
</div>

<?php
$this->widget('ext.cleditor.ECLEditor', array(
        'model'=>$model,
        'attribute' => 'content',
        'options'=>array(
            'width'=>600,
            'height'=>280,
            'useCSS'=>true,
			'controls'=>'bold italic underline strikethrough | font size style | color highlight removeformat | bullets numbering | outdent indent | alignleft center alignright justify | undo redo | rule image link unlink | source',
        ),
        'value'=>$model->content, 
    ));
?>

<div class="row buttons">
<span id="preview" style="cursor: pointer;">Podgląd</span>
<?php echo CHtml::submitButton('Zakolejkuj', array(
		'id'=>'submitButton',
	)); ?>

<p style="font-size: small; color: #545454;">
	Uwaga: Ta wiadomość zostanie wysłana do wszystkich użytkowników serwisu w postaci wiadomości e-mail!!. Wysyłanie może potrwać nawet parę minut!!
</p>

</div>
<div id="previewWindow" style="background: #ffffff;"></div>
<?php 
Yii::app()->clientScript->registerScript('fancy-links', "
$('#preview').click(function(){
  $('#previewWindow').show();
  var editor = $('#MailingForm_content').cleditor()[0];//.selected();
  $('#previewWindow').html(editor.doc.body.innerHTML);  
  return false;
});
$('#submitButton').click(function(){
	return confirm('Czy na pewno?');
});
");

$this->endWidget(); ?>