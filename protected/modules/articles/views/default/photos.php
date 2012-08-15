<h3>Dostępne zdjęcia</h3>
<?php

foreach ($files as $file) {
	
	$url =  Yii::app()->baseUrl.'/'.$file;
	$image = CHtml::image($url,$url,array(
		'width' => 160,
	));
	
	echo '<div style="padding: 10px; margin: 5px; border: 2px dotted #dadada;">';
	echo '<input type=text value="'.$url.'" size="50"></input><br/>';
	echo $image.'</div>';
}
?>