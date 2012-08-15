<h4>Komentarze:</h4>

<?php

$this->widget('zii.widgets.grid.CGridView', array(
	'id'=>'wiki-grid',
	'dataProvider' => $dataProvider,
	'columns'=>array(
		'id','itemId', 'userId', 'content', 'created', 'status',
		array(
			'class'=>'CLinkColumn',
			'label'=>'Usuń',
			'urlExpression'=>'$this->grid->getOwner()->createUrl("deleteComment", array("id"=>$data->id))'
		),
	),
));	
