<?php
$this->breadcrumbs=array(
	'Events'=>array('index'),
	$model->id,
);

$this->menu=array(
	array('label'=>'Delete Event', 'url'=>'#', 'linkOptions'=>array('submit'=>array('delete','id'=>$model->id),'confirm'=>'Are you sure you want to delete this item?')),
	array('label'=>'Manage Event', 'url'=>array('admin')),
);
?>

<h1>View Event #<?php echo $model->id; ?></h1>

<?php $this->widget('zii.widgets.CDetailView', array(
	'data'=>$model,
	'attributes'=>array(
		'id',
		'category',
		'userId',
		'message',
		'created',
		array(
			'label'=>'Creation time',
			'value'=>date('d.m.Y h:i',$model->created),
		),
	),
)); ?>
