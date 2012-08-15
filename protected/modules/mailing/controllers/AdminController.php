<?php

class AdminController extends Controller {

	public $layout = '';
	
	public function filters(){
		return array(
			'accessControl',
		);
	}
	
	public function __construct($id, $module) {
		parent::__construct($id, $module);
		$this->layout = $module->layout;
	}
	
	public function accessRules(){
		return array(
			array('allow',
				'users'=>array('@'),
				'expression'=>'Yii::app()->user->isAdmin()',
			),
			array('deny',
				'users'=>array('*'),
			),
		);
	}
	
	public function actionBroadcast(){
		
		$model = new MailingForm();
		
		if(isset($_POST['MailingForm'])) {
			$model->attributes=$_POST['MailingForm'];

			$pattern = $model->pattern;
			
			$criteria = new CDbCriteria;
			$criteria->condition = 'status = 0 and username like "'.$pattern.'"';
			$criteria->select = 'id,username,registerEmail';
			//$criteria->limit = 1;
			
			$users = User::model()->findAll($criteria);
			
			foreach ($users as $user){
				$this->getModule()->enqueue(
					$user->registerEmail,
					'redakcja@centrumrekodziela.pl',
					'Zespół CentrumRekodziela.pl',
					$model->subject,
					$model->content,
					122
				);	
			}
			
			$count = User::model()->count($criteria);
			
			$this->getModule()->enqueue(
				'krzysztof.maziarz@gmail.com',
				'redakcja@centrumrekodziela.pl',
				'System',
				'Potwierdzenie mailingu',
				"Zakończono pomyślnie wysłkę {$count} wiadomości w ramach mailingu: \"{$model->title}\"",
				99	
			);
			
		}
		
		$this->render('broadcast', array(
			'model'=>$model,	
		));
		
	}
	
	public function actionSendNow($id){
		
		$mail = EmailQueue::model()->findByPk($id);
		
		if (!$mail){
			throw new CHttpException(404, "Mail not found.");
		}
		
		if ($mail->sent_time !== null){
			throw new CHttpException(404, "Mail is already sent.");
		}
		if ($mail->category > 100) {
			$wasSuccess = $this->getModule()->getMailer()->sendHtmlMail($mail->mail_to, $mail->mail_from, $mail->subject, "To jest wiadomość typu HTML. Użyj klienta który potrafi obsługiwać takie wiadomości.", $mail->message);
		} else {
			$wasSuccess = $this->getModule()->getMailer()->sendMail($mail->mail_to, $mail->mail_from, $mail->subject, $mail->message);
		}
		if ($wasSuccess){
			$mail->sent_time = time();
			$mail->update(array('sent_time'));
		} 
	}
	
	public function actionRemove($id){
		$mail = $this->getMailById($id);
		
		if ($mail->delete()){
			$this->redirect(array('index'));
		}
	}
	
	private function getMailById($id) {
		$mail = EmailQueue::model()->findByPk($id);
		if (!$mail){
			throw new CHttpException(404, "Mail not found.");
		}
		return $mail;
	}
	
	public function actionDetails($id){
		
		$mail = $this->getMailById($id);
		
		$this->render('details', array(
			'mail'=>$mail,
		));
	}
	
	public function actionIndex(){
		
		$model = EmailQueue::model();

		$dataProvider = new CActiveDataProvider($model, array(
			'sort'=>array(
				'defaultOrder'=>array('id'=>true),
			),
		));

		$criteria = null;
		if (isset($_GET['waiting'])){
			$criteria = new CDbCriteria(array(
				'condition'=>'sent_time is null',
			));
		} else if(isset($_GET['sent'])){
			$criteria = new CDbCriteria(array(
				'condition'=>'sent_time is not null',
			));
		} else {
			$criteria = new CDbCriteria(array());
		}  
	
		$dataProvider->setCriteria($criteria);
		
		$dataProvider->setPagination(array('pageSize'=>16));

		$toBeSentCount = EmailQueue::model()->count('sent_time is null');
		
		$this->render('index',array(
			'dataProvider'=>$dataProvider,
			'count'=>$toBeSentCount,
		));
	}

}
