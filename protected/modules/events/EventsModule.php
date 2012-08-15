<?php
class EventsModule extends CWebModule {

	public $defaultController = 'event';

	public $layout = '//layouts/main';
	
	public function init (){
		$this->setImport(array(
			'events.models.*',	
		));
	}
	
	public function log($message){
		Event::log($message);
	}
	
	public function trace($category, $itemId){
		
		if ($this->isSpider() == false){
			SessionHistory::trace($category, $itemId);
		}
		
	}
	
	public function isPresent($userId, $category, $itemId){
		
		$attr = SessionHistory::model()->countByAttributes(array(
					'category'=>$category,
					'userId'=>$userId,
					'itemId'=>$itemId));
		
		$this->log("Size: $attr, $category, $userId, $itemId");
		
		return $attr > 0;

	}
	
	
	function isSpider(){
		$spiders = array( 'Googlebot', 'Mediapartners-Google', 'Googlebot-Mobile', 'Yammybot', 'Openbot', 'Yahoo', 'Slurp', 'msnbot',
			'ia_archiver', 'Lycos', 'Scooter', 'AltaVista', 'Teoma', 'Gigabot',
			 
		);
	
		foreach ($spiders as $spider) {
			if (preg_match($spider, $_SERVER['HTTP_USER_AGENT'])){
				return TRUE;
			}
		}
		
		return FALSE;
	}
}