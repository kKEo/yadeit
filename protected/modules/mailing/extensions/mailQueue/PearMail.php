<?php
/**
 * Controller for the members
 *
 * PHP Version 5.1
 *
 * @category Vencidi
 * @package  Mail
 * @author   Loren
 * @license  http://www.opensource.org/licenses/mit-license.php MIT
 */
 
require_once 'Mail.php'; // PEAR Mail
require_once 'Mail/mime.php'; // PEAR Mail_mime
require_once 'Mail/Queue.php'; // PEAR Mail_queue
require_once 'Mail/Queue/Container/mdb2.php'; // PEAR Mail_queue mdb2
 
/**
 * Controller for the members
 *
 * PHP Version 5.1
 *
 * @category Vencidi
 * @package  Mail
 * @author   Loren
 * @license  http://www.opensource.org/licenses/mit-license.php MIT
 * @link     http://www.vencidi.com/ Vencidi
 * @since    3.0
 */
class PearMail extends CComponent
{
    /**
     * @var string the default layout for the views.
     */
    public $layout = 'main';
 
    /**
     * @var array Database options for Mail_Queue
     */
    public $db_options = array(
        'type'      => 'mdb2',
        'dsn'       => '', // Set in init
        'mail_table'=> 'mail_queue',
    );
 
    /**
     * @var array Mail options for Mail
     */
    public $mail_options = array(
        'driver'    => 'smtp',
        'host'      => 'ssl://mail.clemson.edu', // or omit ssl:// for non ssl
        'port'      => 465, // or 25 for non-ssl
        'auth'      => true, // or false if you don't need it
        'username'  => '',
        'password'  => ''
    );
 
    /**
     * @var String From PearMail for PearMails
     */
    public $from = ""; // could omit this and change setting below
 
    /**
     * @var int Max PearMails to send at a time
     */
    public $max_PearMails = 30;
 
    /**
     * Component Init Function
     *
     * @return void
     */
    public function init()
    {
        PearMail::dbSetup();
    }
 
    /**
     * Sets up the database connection information from Yii
     *
     * @return void
     */
    public function dbSetup()
    {
        $connection = Yii::app()->db;
        $cstring = $connection->connectionString;
        $match = array();
        preg_match("@mysql:host=([^;]+);dbname=([^/]+)@", $cstring, $match);
        $this->db_options['dsn'] = "mysql://".$connection->username.":".
            $connection->password."@{$match[1]}/{$match[2]}";
    }
 
    /**
     * Add an EMail to the Mail Queue
     *
     * @param String $replyto    Reply To Email Address
     * @param String $to         To Email Address
     * @param String $subject    Email Subject
     * @param String $text       Email Text Body
     * @param String $html       Email HTML Body
     * @param Array  $attachment Attachments associative array('file'=>name or contents, $a['cType']=>content type (eg text/plain), $a['name']=>file name for the attachment (eg test.txt), $a['isFile']=>true or false if 'file' is a file (true) or is the contents (false));
     *
     * @return void
     */
    public function queueMail($replyto,$to,$subject,$text,$html,$attachment=array())
    {
        $mail_queue =& new Mail_Queue(
            $this->db_options,
            $this->mail_options
        );
 
        $crlf = "\n";
 
        $headers = array(
            'From'          => $this->from, // If you omit from above, change this to $replyto
            'To'            => $to,
            'Reply-To'      => $replyto,
            'Return-Path'   => $replyto,
            'Subject'       => $subject,
        );
        $mime = new Mail_mime($crlf);
 
        // Set Up PearMail
        $mime->setTXTBody($text);
        $mime->setHTMLBody($html);
 
        // Attachment
        foreach ($attachment as $a) {
            $mime->addAttachment($a['file'], $a['cType'], $a['name'], $a['isFile']);
        }
 
        // Set body and headers ready for base mail class
        $body = $mime->get();
        $headers = $mime->headers($headers, true);
 
        $mail_queue->put($replyto, $to, $headers, $body);
    }
 
    /**
     * Send EMail(s) from the Mail Queue
     *
     * @return void
     */
    public function sendMail()
    {
        $mail_queue =& new Mail_Queue(
            $this->db_options,
            $this->mail_options
        );
 
        $mail_queue->sendMailsInQueue($this->max_PearMails);
    }
}
?>