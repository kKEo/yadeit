
<?php

echo '<h3>'.$article->title;

if (Yii::app()->user->isGuest === false) {
	echo CHtml::link('(edytuj)', array('update', 'id'=>$article->id));
}

echo '</h3>';

foreach ($sections as $section) {
	
	$this->renderPartial('_renderSection'.$section['contentType'], array(
		'section'=>$section,	
	));
	
}

//$this->widget('wiki.components.TableOfContentWidget', array(
//		'articleId'=>$article->id,
//	));