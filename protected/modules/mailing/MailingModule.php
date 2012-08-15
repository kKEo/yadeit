<?php
class MailingModule extends CWebModule {
	
	public $type = 'mail';
	
	public $smtpHost;
	public $smtpLogin;
	public $smtpPassword;
	public $smtpPort;
	
	public $defaultController = 'admin';
	
	private $baseScriptUrl;
	
	public $ipFilters=array('127.0.0.1','::1');
	
	public $layout = '//layouts/main';
	
	public function init(){
		$this->setImport(array(
			'mailing.models.*',
		));
	}
	
	public function getBaseScriptUrl(){
		return $this->baseScriptUrl;
	}
	
// 	public function beforeControllerAction($controller, $action){
// 		if(parent::beforeControllerAction($controller, $action)){
// 			$assets = dirname(__FILE__).'/assets';
// 			if (YII_DEBUG){
// 				$this->baseScriptUrl = Yii::app()->assetManager->publish($assets, false, -1, true);
// 			} else {
// 				$this->baseScriptUrl = Yii::app()->assetManager->publish($assets);
// 			}
// 			Yii::app()->clientScript->registerCssFile($this->baseScriptUrl.'/styles.css');
// 			return true;
// 		}
// 		else
// 		return false;
// 	}
	
	public function enqueue($to, $from, $fromName, $subject, $body, $category = 0){
		
		$queueEntry = new EmailQueue();
		$queueEntry->mail_from = $from;
		$queueEntry->from_name = $fromName;
		$queueEntry->mail_to = $to;
		$queueEntry->subject = $subject;
		$queueEntry->message = $body;
		$queueEntry->category = $category;
		
		if ($category == 0){
			$this->getMailer()->sendMail($to, $from, $subject, $body);
			$queueEntry->sent_time = time();
		}
		
		if (!$queueEntry->save()){
			throw new CHttpException(500, 'Error: '.CVarDumper::dumpAsString($queueEntry->getErrors()));
		}
		
	}

	/**
	 * 
	 * @return EasyMail
	 */
	public function getMailer(){
		
		Yii::import('mailing.extensions.EasyMail');
		
		$em = new EasyMail();
		$em->smtpHost = $this->smtpHost;
		$em->smtpPort = $this->smtpPort;
		$em->smtpLogin = $this->smtpLogin;
		$em->smtpPassword = $this->smtpPassword;
		
		return $em;
	}
	
	public function beforeControllerAction($controller, $action)
	{
		if(parent::beforeControllerAction($controller, $action))
		{
			//if(!$this->allowIp(Yii::app()->request->userHostAddress))
			//throw new CHttpException(403,"You are not allowed to access this page.");
	
			return true;
		}
		return false;
	}
	
	/**
	* Checks to see if the user IP is allowed by {@link ipFilters}.
	* @param string the user IP
	* @return boolean whether the user IP is allowed by {@link ipFilters}.
	*/
	protected function allowIp($ip)
	{
		if(empty($this->ipFilters)) {
			return true;
		}
		foreach($this->ipFilters as $filter)
		{
			if($filter==='*' || $filter===$ip || (($pos=strpos($filter,'*'))!==false && !strncmp($ip,$filter,$pos)))
			return true;
		}
		return false;
	}
	
}