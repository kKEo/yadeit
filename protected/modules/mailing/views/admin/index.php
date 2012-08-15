<?php

echo "Mails in queue: {$count}<br/>";

$this->widget('zii.widgets.grid.CGridView', array(
	'id' => 'data-grid',
	'template' => '{items}{pager}',
	'dataProvider' => $dataProvider,
	'columns'=>array(
		'id',
		array(
			'id'=>'emailAddedTime',
			'header'=>'Data dodania',
			'name'=>'added_time',
			'type'=>'datetime',
		),
		'mail_to', 
		array(
			'id'=>'emailDetails',
			'class'=>'CLinkColumn',
			'header'=>'Tytuł',
			'labelExpression'=>'$data->subject',
			'urlExpression'=>'array("admin/details", "id"=>$data->id)',
		),
		'category',
		'priority',
		array(
			'id'=>'emailSentTime',
			'header'=>'Data wysłania',
			'name'=>'sent_time',
			'type'=>'datetime',
		),
		array(
			'id'=>'emailSendNow',
			'class'=>'CLinkColumn',
			'header'=>'Wyslij',
			'labelExpression'=>'"Wyślij teraz"',
			'urlExpression'=>'array("admin/sendNow", "id"=>$data->id)',
		),
		array(
			'id'=>'emailDelete',
			'class'=>'CLinkColumn',
			'header'=>'Usuń',
			'labelExpression'=>'"Usuń"',
			'urlExpression'=>'array("admin/remove", "id"=>$data->id)',
		),

	),
	
	
));



