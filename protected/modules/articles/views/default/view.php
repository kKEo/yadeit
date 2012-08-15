<div id="article-view-title">
  <?php 
  	echo $article->title;
  
	if (Yii::app()->user->isGuest === false) {
		echo CHtml::link('(edytuj)', array('update', 'id'=>$article->id));
	}
  ?>
  <div id="article-view-title-desc">
  	Dodano: <?php echo date("d.m.Y h:m", $article->created)?>
  </div>
</div>

<div id="article-view-toc">
<div id="article-view-author">
	<?php 
	$author = $article->author;
	
	if ($author) {
		echo CHtml::link(CHtml::image('/images/avatars/'.$author->photo).'<h3>'.$author->displayedName.'</h3>', array('//user/view', 'username'=>$author->username));
	?>
	<div style="text-align: left; margin-bottom: 10px;">
		Artykułów: 1 <br/>
		Dołączył(a): <?php echo date('Y-m-d', $author->created)?>
	</div>
	
	<?php 
		echo CHtml::link(CHtml::image(Yii::app()->request->baseUrl.'/images/graphic/iconSentPM.png', 'Wyslij PM'), array('/inbox/message/compose', 'receiver'=>$author->id), array('title'=>'Wyślij wiadomość prywatną')); 
			
		$this->widget('favs.components.FavLink', array(
			'targetId'=>$author->id,
			'image'=>Yii::app()->request->baseUrl.'/images/graphic/iconViewProfile.png',
			'text'=>'Dodaj użytkownika do znajomych',
		));
		
	}
	?>
	
</div>

<?php 
//	$this->widget('articles.components.TableOfContentWidget', array(
//		'articleId'=>$article->id,
//	));
?>
</div>

<div id="article-view-content">
<?php

foreach ($sections as $section) {
	$this->renderPartial('_renderSection'.$section['contentType'], array(
		'section'=>$section,	
	));
}

?>

<?php 
//	$this->widget('comments.components.CommentMeWidget', array(
//		'itemId'=>$article->id,
//		'categoryCode' => 11,
//	));
?>
</div>