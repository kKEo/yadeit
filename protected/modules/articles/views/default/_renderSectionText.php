<div class="wikiSection textSection" id="section<?php echo $section->id; ?>">

<?php 
if ($section->title) {
	echo '<a name="id'.$section->id.'"></a>';
}
if (isset($isUpdate)) {
	$this->renderPartial('_renderSectionFooter', array(
		'section'=>$section,
	));
}

echo '<div class="content">';

$this->beginWidget('CMarkdown');

if ($section->title) {
	echo '#'.$section->title;
	echo "\n";
}
echo $section->content;

$this->endWidget();
echo '</div>';

?>

</div>