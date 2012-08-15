<h3>Wiki list</h3>

<?php

$this->renderPartial('_newArticleDialog', array(
	'id' => 'wiki-grid',
));

$this->widget('zii.widgets.grid.CGridView', array(
	'id'=>'wiki-grid',
	'dataProvider' => $dataProvider,
	'columns'=>array(
		'id',
		'title',
		'abstract',
		'tags',
		'user.username',
		'status',
		array(
			'name'=>'created',
			'value'=>'date("d.m.Y-H:i:s",$data->created)',
		),
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

