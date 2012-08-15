<div id="articles-list-content">
<?php
$this->widget('zii.widgets.CListView', array(
	'ajaxUpdate'=>false,
    'dataProvider'=>$dataProvider,
    'itemView'=>'_indexItem',   // refers to the partial view named '_post'
    'sortableAttributes'=>array(
        'title' => 'Tytuł',
		'created'=>'Data dodania',
    ),
    'template'=>"{sorter}<div style=\"border-top: 2px solid #eaeaea;\"></div>{items}\n{pager}",
));


if (Yii::app()->user->isAdmin()) {
	echo '<div style="padding: 10px; margin: 10px; border: 2px dotted #aeaeae;"> Admin: ';
	echo CHtml::link('Zarządzanie artykułami', array('admin'));
	echo '</div>';
	
}
?>
</div>