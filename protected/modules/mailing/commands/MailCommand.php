<?php
class MailCommand extends CConsoleCommand {

	/**
	 * Returns the held message
	 *
	 * @return void
	 */
	public function getHelp()
	{
		$output = <<<EOD
USAGE
    mail <mail-limit>
 
DESCRIPTION
    Sends arbitrary number of email from email queue.
 
ARGS
	mail-limit - specifies how many mails can be send at once
    
EOD;
	}

	/**
	 * Execute the action.
	 *
	 * @param array $args command line parameters specific for this command
	 *
	 * @return void
	 */
	public function run($args) {
		
		$mailLimit = 25;
		if (isset($args[0])){
			$mailLimit = $args[0];
		}
		
		Yii::import('application.modules.mailing.models.EmailQueue');
		
		$model = EmailQueue::model();
		
		$mails = $model->findAll(array(
			'condition'=>'sent_time is null',
			'limit'=>$mailLimit,
		));
		
		$mailer = Yii::app()->getModule('mailing')->getMailer();
		
		foreach ($mails as $mail){
			Yii::trace($mail->id.' - '.$mail->added_time);
			
			$wasSuccess = false;
			if ($mail->category > 100){
				$wasSuccess = $mailer->sendHtmlMail($mail->mail_to, $mail->mail_from, $mail->subject, "To jest wiadomość typu HTML. Użyj klienta który potrafi obsługiwać takie wiadomości.", $mail->message);
			} else {
				$wasSuccess = $mailer->sendMail($mail->mail_to, $mail->mail_from, $mail->subject, $mail->message);
			}
			
			if ($wasSuccess){
				$mail->sent_time = time();
				$mail->update(array('sent_time'));
			}
			
		}
	}
}

?>