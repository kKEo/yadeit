<div class="article-item <?php echo $index%2?'even':'odd' ?>" >
<div class="article-item-logo">
	<?php 
		if(!$data->avatar){
			$author = $data->author;
			if ($author) {
				echo CHtml::image($author->getAvatar(), $author->displayedName,array('height'=>90));
			}
		} else {
			echo CHtml::image($data->avatar, $data->title, array('height'=>90));
		}
	?>
</div>

<div class="article-item-content">
<div class="article-item-title">
<?php echo CHtml::link($data->title, array('view', 'title'=>$data->seoTitle)); ?>
</div>
<div class="article-item-menu">
	<?php 
		$author = $data->author;
		if($author){
			echo CHtml::link($author->displayedName,array('//user/view', 'username' => $author->username));
		} else {
			echo "<a href=\"#\">Nieznany</a>";
		}
		echo " | ";
		echo date('d-m-Y', $data->created); ?> 
</div>


<div class="article-item-abstract">
<?php echo $data->abstract;?>
</div>

<div style="clear: both"></div>
</div>
<div class="article-item-footer">
	<span> 
	<?php
		if ($this->getModule()->hasComments) {
			$comments = $data->commentsCount;
			echo CHtml::link('komentarze('.$comments.')', array('view', 'id'=>$data->id, '#'=>'komentarze'));
			echo '|';
		}
	?>
	<?php echo CHtml::link('czytaj więcej...', array('view', 'id'=>$data->id));?>
	</span>
</div>
</div>
