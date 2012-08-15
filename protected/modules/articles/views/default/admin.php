<?php

$this->renderPartial('_newArticleDialog', array(
	'id' => 'wiki-grid',
	'linkContainerId'=>'admin-module-menu',
));

$this->widget('zii.widgets.grid.CGridView', array(
	'id'=>'wiki-grid',
	'dataProvider' => $dataProvider,
	'columns'=>array(
		array(
			'name'=>'status',
			'value'=>'($data->status)?"Opublikowane":"Robocze"',
		),
		'pinned',
		'id',
		'title',
		'displays',
		'user.username',
		'author.username',
		array(
			'name'=>'created',
			'value'=>'date("d.m.Y-H:i:s",$data->created)',
		),
		array(
			'class'=>'CLinkColumn',
			'label'=>'Zdjęcia',
			'urlExpression'=>'$this->grid->getOwner()->createUrl("photos", array("id"=>$data->id))'
		),
//		'commentsCount',
//		array(
//			'class'=>'CLinkColumn',
//			'label'=>'Komentarze',
//			'urlExpression'=>'$this->grid->getOwner()->createUrl("comments", array("id"=>$data->id))'
//		),
		array(
			'class'=>'CButtonColumn',
			'header'=>'Treść',
			'template'=>'{view}{update}'
		),
		array(
			'class'=>'CButtonColumn',
			'header'=>'Artykuł',
			'template'=>'{update}{delete}',
			'updateButtonOptions'=>array('class'=>'updateArticle'),
			'afterDelete'=>'function(link,success,data){ overrideArticleUpdateButton(); }',
		),
	),
));

