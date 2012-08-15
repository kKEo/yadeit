<?php
$this->breadcrumbs=array(
	'Events'=>array('index'),
	'Manage',
);

$this->menu=array(
);
?>

<?php 
$this->widget('zii.widgets.grid.CGridView', array(
	'id'=>'event-grid',
	'dataProvider'=>$dataProvider,
	'filter'=>$model,
	'columns'=>array(
		'id',
		//'category',
		'userId',
		'user.username',
		'message',
		array(
			'name'=>'created',
			'value'=>'date("y.m.d-H:i:s",$data->created)',
		),
//		array(
//			'class'=>'CButtonColumn',
//		),
	),
)); ?>
