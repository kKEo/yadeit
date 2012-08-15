<?php
class TableOfContentWidget extends CWidget {
	
	public $articleId;
	
	public function init(){
		
	}
	
	public function run(){
		
		$article = Article::model()->findByPk($this->articleId);
		
		if (!$article){
			throw new Exception("Article not found.");
		}
		
		$this->render('tableOfContent',array(
			'sections'=>$article->publishedSections,	
		));
		
	}
	
}