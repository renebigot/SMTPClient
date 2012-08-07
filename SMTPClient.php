<?php

/** SMTPClient class allow you to connect to an SMPT server to send an email without any
    sendmail command.
    You have to use your own SMTP account to connect to a server.
    To send an email, you'll have to :
    setServer -> setSender -> setMail -> attachFile (called once per file) -> sendMail 
    
    Usage :
	    $smtpClient = new SMTPClient();
		$smtpClient->setServer("smtp.gmail.com", "465", true);
		$smtpClient->setSender("myemailaddressexample@gmail.com", 
								"myemailaddressexample@gmail.com",
								"mypassword");
		$smtpClient->setMail("destinationaddress@example.com, otheraddress@example.com",
								"My test email subject",
								"If you read this in a mail client, everything is OK");
		$smtpClient->attachFile("Texte.txt", file_get_contents("./myTextFile.txt");
		$smtpClient->attachFile("Image.PNG", file_get_contents("./myImageFile.png"));
		$smtpClient->sendMail();
    */
class SMTPClient {
	//If _debug is true, mail won't be send and commands will be written in _traceFile
	private $_debug = false;
	private $_traceFile = "./raw_commands.txt";
	
	/* Server settings */
	protected $smtpHost = "";
	protected $smtpPort = 25;
	protected $security = false;
	
	/* Sender settings */
	protected $from = "";
	protected $user = "";
	private $_password = "";	//write only from outside (via setter)

	/* Mail settings */
	protected $to = null;
	protected $message = "";
	protected $contentType = "";
	protected $charset = "";
	private $_attachedFiles = null;

	/** Set the server host, port and ask for security (SSL, TLS) if necessary.
	    */
	public function setServer($smtpHost, $smtpPort = 25, $security = false) {
		$this->smtpPort = $smtpPort;
		$this->smtpHost = $smtpHost;
		$this->security = $security;
	}

	/** Set the sender email address, a valid user name and password for the smtp server
	    (see setServer)
	    */
	public function setSender($from, $user, $password) {
		$this->from = $from;
		$this->user = $user;
		$this->_password = $password;
	}
	
	/** Set the mail content : recipients, subject message and content type and charset.
	    $to can be an array or a list of addresses separated by commas.
	    Change content-type if you wants to send an HTML page.
	    Change charset has needed.
		*/ 
	public function setMail($to, $subject, $message,
		$contentType = "text/plain", $charset="iso-8859-1") {
		if (is_array($to)) {
			$this->to = $to;
		} else {
			//str_replace is called twice to delete spaces next to ','
			$this->to = explode(
								",",
								str_replace(
											", ",
											",",
											str_replace(" ,", ",", $to)
											)
								);
		}
		
		$this->subject = $subject;
		$this->message = $message;
		$this->contentType = $contentType;
		$this->charset = $charset;
	}
	
	/** Attach a file to your email. Call it once per file you have to add.
	    The filename is the file without the path information.
	    File raw content can be given by file_get_content.
	    MIME Type "application/octet-stream" seems to work for many file type.
		Change it to the real value if needed. (image/gif, image/png, etc.)
		*/ 
	public function attachFile($filename, $fileRawContent, 
						$mimeType = "application/octet-stream") {
		if (!isset($this->_attachedFiles)) $this->_attachedFiles = array();
		$this->_attachedFiles[] = array(
									"Filename" => $filename,
									"Content" => base64_encode($fileRawContent),
									"Content-Type" => $mimeType);
	}
	
	/** Don't send $filename as an attachment
	    */
	public function detachFile($filename) {
		unset($this->_attachedFiles[$filename]);
	}
	
	/** Don't send any file as attachment
	    */
	public function detachFiles() {
		$this->_attachedFiles = null;
	}
	
	/** Connect to the SMTP server and send the email.
	    If content type is not text/plain, it is sent as Multipart MIME and HTML
		tags are stripped in the plain text version.
	    */
	public function sendMail() {
		$socket = null;
		try {
			//Connection
			if ($this->_debug) {
				$socket = fopen($this->_traceFile, "w");
			} else {
				if (!($socket = fsockopen(($this->security ? $this->security . "://" : "") 
					. $this->smtpHost, $this->smtpPort, $errno, $errstr, 15)))
					throw new Exception("Could not connect to SMTP host ".
										"'$smtp_host' ($errno) ($errstr)\n");
			}
			
			$this->waitForPositiveCompletionReply($socket);
			
			fwrite($socket, "EHLO " . gethostname() . "\r\n");
			$this->waitForPositiveCompletionReply($socket);
			
			//Auth
			if ($this->user != "" && $this->_password != "") {
				fwrite($socket, "AUTH LOGIN"."\r\n");
				$this->waitForPositiveIntermediateReply($socket);
			
				fwrite($socket, base64_encode($this->user)."\r\n");
				$this->waitForPositiveIntermediateReply($socket);
			
				fwrite($socket, base64_encode($this->_password)."\r\n");
				$this->waitForPositiveCompletionReply($socket);
			}
			
			//From
			fwrite($socket, "MAIL FROM: <" . $this->from . ">"."\r\n");
			$this->waitForPositiveCompletionReply($socket);
			
			//To
			foreach ($this->to as $email) {
				fwrite($socket, "RCPT TO: <" . $email . ">" . "\r\n");
				$this->waitForPositiveCompletionReply($socket);
			}

			//Mail content
			fwrite($socket, "DATA"."\r\n");
			$this->waitForPositiveIntermediateReply($socket);
			
			$multiPartMessage = "";
			$mimeBoundary="__NextPart_" . md5(time());

			//Multipart MIME header
			$multiPartMessage .= "MIME-Version: 1.0" . "\r\n";
			$multiPartMessage .= "Content-Type: multipart/mixed;";
			$multiPartMessage .= " boundary=" . $mimeBoundary ."" . "\r\n";
			$multiPartMessage .= "\r\n";
			$multiPartMessage .= "This is a multi-part message in MIME format." 
								. "\r\n";
			$multiPartMessage .= "\r\n";
			
			//Plain Text mail version
			if ($this->contentType != "text/plain") {
				$multiPartMessage .= "--" . $mimeBoundary . "\r\n";
				$multiPartMessage .= "Content-Type: text/plain; charset=\"" 
									. $this->charset . "\"" . "\r\n";
				$multiPartMessage .= "Content-Transfer-Encoding: " 
									. "quoted-printable" . "\r\n";
				$multiPartMessage .= "\r\n";
				$multiPartMessage .= quoted_printable_encode(
										strip_tags($this->message)) . "\r\n";
				$multiPartMessage .= "\r\n";
			}

			//Raw text mail version
			$multiPartMessage .= "--" . $mimeBoundary . "\r\n";
			$multiPartMessage .= "Content-Type: " . $this->contentType 
								. "; charset=\"" 
								. $this->charset . "\"" . "\r\n";
			$multiPartMessage .= "Content-Transfer-Encoding: quoted-printable" 
								. "\r\n";
			$multiPartMessage .= "\r\n";
			$multiPartMessage .= quoted_printable_encode($this->message) 
								. "\r\n";
			$multiPartMessage .= "\r\n";

			//Attached Files
			if ($this->_attachedFiles) {
				foreach($this->_attachedFiles as $attachedFile) {
					$multiPartMessage .= "--" . $mimeBoundary . "\r\n";
					$multiPartMessage .= "Content-Type: " 
										. $attachedFile["Content-Type"] 
										. ";" . "\r\n";
					$multiPartMessage .= "	name=\"" . $attachedFile["Filename"]
										. "\"" . "\r\n";
					$multiPartMessage .= "Content-Transfer-Encoding: base64" 
										. "\r\n";
					$multiPartMessage .= "Content-Description: " 
										. $attachedFile["Filename"] . "\r\n";
					$multiPartMessage .= "Content-Disposition: attachment;" 
										. "\r\n";
					$multiPartMessage .= "	filename=\"" 
										. $attachedFile["Filename"] . "\"" 
										. "\r\n";
					$multiPartMessage .= "\r\n";
					$multiPartMessage .= $attachedFile["Content"] . "\r\n";
					$multiPartMessage .= "\r\n";
				}
			}
				
			$multiPartMessage .= "--" . $mimeBoundary . "--" . "\r\n";

			//Write content on socket
			fwrite($socket, "Subject: " . $this->subject . "\r\n");
			fwrite($socket, "To: <" 
								. implode(">, <", $this->to) . ">" . "\r\n");
			fwrite($socket, "From: <" . $this->from . ">\r\n");
			fwrite($socket, $multiPartMessage . "\r\n");

			//Mail end
			fwrite($socket, "."."\r\n");
			$this->waitForPositiveCompletionReply($socket);

			//Close connection
			fwrite($socket, "QUIT"."\r\n");
			fclose($socket);
		} catch (Exception $e) {
			echo "Error while sending email. Reason : \n" . $e->getMessage();
			return false;
		}

		return true;
	}
	
	/** Verify if server responds with a positive preliminary (1xx) status code
	    */
	protected function waitForPositivePreliminaryReply($socket) {
		try {
			$this->_serverRespondedAsExpected($socket, 1);
		} catch (Exception $e) {
			throw $e;
		}
	}	

	/** Verify if server responds with a positive completion (2xx) status code
	    */
	protected function waitForPositiveCompletionReply($socket) {
		try {
			$this->_serverRespondedAsExpected($socket, 2);
		} catch (Exception $e) {
			throw $e;
		}
	}	

	/** Verify if server responds with a positive intermediate (3xx) status code
	    */
	protected function waitForPositiveIntermediateReply($socket) {
		try {
			$this->_serverRespondedAsExpected($socket, 3);
		} catch (Exception $e) {
			throw $e;
		}
	}
	
	/** Verify if server responds with a transient negative completion (4xx) 
	    status code
	    */
	protected function waitForTransientNegativeCompletionReply($socket) {
		try {
			$this->_serverRespondedAsExpected($socket, 4);
		} catch (Exception $e) {
			throw $e;
		}
	}
	
	/** Verify if server responds with a permanent negative completion (5xx) 
	    status code
	    */
	protected function waitForPermanentNegativeCompletionReply($socket) {
		try {
			$this->_serverRespondedAsExpected($socket, 5);
		} catch (Exception $e) {
			throw $e;
		}
	}

	/** Check if the received response is the expected one.
	    Should not be called directly, use thes waitFor...() methods instead
	    */
	private function _serverRespondedAsExpected($socket,
													$expectedStatusCode) {
		if ($this->_debug) return;
		$serverResponse = "";
		
		//SMTP server can send multiple response.
		//For example several 250 status code after EHLO
		while (substr($serverResponse, 3, 1) != " ") {
			$serverResponse = fgets($socket, 256);
//			echo $serverResponse;
			if (!($serverResponse))
 				throw new Exception("Couldn\'t get mail server response codes."
										. " Please contact an administrator.");
		}

		$statusCode = substr($serverResponse, 0, 3);
		$statusMessage = substr($serverResponse, 4);
		if (!(is_numeric($statusCode) 
				&& (int)($statusCode / 100) == $expectedStatusCode)) {
			throw new Exception($statusMessage);
		}
	}
	
	
	/* Server settings setters */
	/** Set the SMTP host (ip or fqdn)
	    */
	public function setSmtpHost($smtpHost) {
		$this->smtpHost = $smtpHost;
	}
	
	/** Set the SMTP port (25, 465, etc.)
	    */
	public function setSmtpPort($smtpPort) {
		$this->smtpPort = $smtpPort;
	}

	/** Set the connection as secured or not (SSL, TLS)
	    */
	public function setSecurity($security = false) {
		$this->security = $security;
	}
	
	/* Sender settings setters */
	/** Set the sender email
	    */
	public function setFrom($from) {
		$this->from = $from;
	}
	
	/** Set the sender username (for server connection)
	    */
	public function setUser($user) {
		$this->user = $user;
	}
	
	/** Set the sender password (for server connection)
	    */
	public function setPassword($password) {
		$this->_password = $password;
	}
	
	
	/* Mail settings setters */
	/** Set the receiver email
	    */
	public function setTo($to) {
		$this->to = $to;
	}
	
	/** Set the email subject
	    */
	public function setSubject($subject) {
		$this->subject = $subject;
	}
	
	/** Set the email content (message)
	    */
	public function setMessage($message) {
		$this->message = $message;
	}
	
	/** Set the email content type
	    */
	public function setContentType($contentType) {
		$this->contentType = $contentType;
	}

	/** Set the sender email charset
	    */
	public function setCharset($charset) {
		$this->charset = $charset;
	}
}
?>