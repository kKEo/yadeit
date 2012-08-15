<?php
 $this->widget('zii.widgets.grid.CGridView', array(
	'id'=>'sessions-grid',
	'dataProvider'=>$dataProvider,
// 	'filter'=>$model,
	'columns'=>array(
		'sessionId',
		'ip',
		'userId',
		'category',
		'itemId',
		array(
			'name'=>'created',
			'value'=>'date("y.m.d-H:i:s",$data->created)',
		),
	),
)); ?>