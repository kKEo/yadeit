<div id="table-of-content" style="position:absolute; right: 20px; top:20px;">
<h4>Spis treści</h4>
<ul> 
	<?php foreach($sections as $section) {
		echo '<li>'.CHtml::link($section->title,'#id'.$section->id).'</li>';
	} ?>
</ul>
</div>