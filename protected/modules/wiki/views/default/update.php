<?php
if ($model) {
	$this->renderPartial('_updateArticle', array(
		'model'=>$model,
		'sections'=>$sections,
	));
} else {
	$this->renderPartial('_createArticle');
}