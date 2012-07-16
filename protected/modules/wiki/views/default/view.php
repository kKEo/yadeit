<?php
if ($model) {
	$this->renderPartial('_viewArticle', array(
		'model'=>$model,
		'sections'=>$sections,
	));
} else {
	$this->renderPartial('_createArticle');
}