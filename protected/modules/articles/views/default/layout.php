<?php 
$this->beginContent('//layouts/main'); 
?>

<div id="admin-module-menu">
<b>Akcje:</b> 
<?php echo CHtml::link('Edycja artykułów', array('admin')); ?>
<?php echo CHtml::link('Podgląd listy artykułów', array('index')); ?>
<?php //echo CHtml::link('Zdjęcia', array('photos')); ?>
</div>

<div id="content">
	<?php echo $content; ?>
</div>	
	
<?php $this->endContent(); ?>