<?php
class EasyMail extends CApplicationComponent {

	// TODO: implement Mail-Queue for sending multiple emails
	
	public $type = 'mail';
	
	public $smtpHost;
	public $smtpLogin;
	public $smtpPassword;
	public $smtpPort;
	
	public $from = "System <system@centrumrekodziela.pl>";
	
	public function sendMail($to, $from, $subject, $body){

		if ($this->type == 'smtp') {
		
			require_once "Mail.php";
	
			$headers = array(
				'From' => $from,
				'To' => $to,
				'Subject' => $subject);
			
			$smtp = Mail::factory('smtp', array(
				'host' => $this->smtpHost, 
				'port'=> $this->smtpPort, 
				'auth' => true,
				'username' => $this->smtpLogin,
				'password' => $this->smtpPassword)
			);
	
			$mail = $smtp->send($to, $headers, $body);
	
			if (PEAR::isError($mail)) {
				echo("<p>" . $mail->getMessage() . "</p>");
				return false;
			} else {
				return true;
			}
			
		} else {
			$additionalHeader = 'From: '.$from."\r\n".
			'Reply-To: '.$from."\r\n" .
			'Content-type: text/plain; charset=utf-8' . "\r\n".
    		'X-Mailer: PHP/'.phpversion();
			
			return mail($to, $subject, $body, $additionalHeader);
		} 
	}
	
	public function sendHtmlMail($to, $from, $subject, $bodyPlain, $bodyHtml){
		
	    $headers = "From: {$from}\r\nReply-To: {$from}\r\n";
	    $headers .= "MIME-Version: 1.0\r\n"; 
	    
	    $boundary = uniqid("HTMLEMAIL"); 
	    
	    $headers .= "Content-Type: multipart/alternative;boundary = $boundary\r\n\r\n"; 
	    
	    //$headers .= "This is a MIME encoded message.\r\n\r\n"; 
	    // plain text
	    $message = "--$boundary\r\n".
	                "Content-Type: text/plain; charset=utf-8\r\n".
	                "Content-Transfer-Encoding: base64\r\n\r\n"; 
	    $message .= chunk_split(base64_encode(strip_tags($bodyPlain))); 
		// html text
	    $message .= "--$boundary\r\n".
	                "Content-Type: text/html; charset=utf-8\r\n".
	                "Content-Transfer-Encoding: base64\r\n\r\n"; 
	    $message .= chunk_split(base64_encode($bodyHtml));
	    
	    $message .= "--$boundary--";
	
	    return mail($to, $subject, $message, $headers);
	
	}
	
	private function getParam($param){
		$value = Yii::app()->params[$param];
		if ($value === null){
			throw new CException("Parameter not found (see config/main.php): ".$param, 0);
		}
		return $value;
	}
	

}