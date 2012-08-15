<div id="article-view-title">
  <?php echo $model->title;?>
  <div id="article-view-title-desc">
  	Dodano: <?php echo date("d.m.Y h:m", $model->created)?>
  </div>
</div>

<?php
if ($model) {
	$this->renderPartial('_updateArticle', array(
		'model'=>$model,
		'sections'=>$sections,
	));
} else {
	$this->renderPartial('_createArticle');
}