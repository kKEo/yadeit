<h3>Wiki list</h3>

<?php
$this->widget('zii.widgets.grid.CGridView', array(
	'id'=>'wiki-grid',
	'dataProvider' => $dataProvider,
	'columns'=>array(
		'id',
		'title',
		'abstract',
		array(
			'name'=>'created',
			'value'=>'date("d.m.Y-H:i:s",$data->created)',
		),
		array(
			'class'=>'CButtonColumn',
		),
	),
));

$this->renderPartial('_newArticleDialog', array(
	'id' => 'wiki-grid',
	
));
