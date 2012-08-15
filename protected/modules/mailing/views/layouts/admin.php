<?php $this->beginContent('//layouts/admin'); ?>
<div>
	<?php echo CHtml::link('Napisz do wszystkich', array('broadcast'))?> |
	<?php //echo CHtml::link('Statystyki', array('statistics')).' | '?> 
	<?php echo CHtml::link('Kolejka', array('index'))?>
</div>
<div id="content">
	<?php echo $content; ?>
</div><!-- content -->
<?php $this->endContent(); ?>