<div class="wikiSection textSection" id="section<?php echo $section->id; ?>">

<?php 
//	if ($section->title) {
//		echo '<div class="title">Title:'.$section->title.'</div>';
//	}

echo '<div class="content">';

$this->beginWidget('CMarkdown');

echo $section->content;

$this->endWidget();
echo '</div>';

?>
<div class="sectionFooter">
<?php echo $section->position; ?> - 
<a class="editLink" href="#">Edytuj</a>
</div>
</div>