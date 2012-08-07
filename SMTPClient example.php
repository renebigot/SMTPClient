<?php
include "SMTPClient.php";

$smtpClient = new SMTPClient();
$smtpClient->setServer("smtp.gmail.com", "465", "tls");
$smtpClient->setSender("myemail@gmail.com", "myemail@gmail.com", "mypassword");
$smtpClient->setMail("mybestfriend@example.com, john.smith@example.com",
						"My first email with SMTPClient", 
						"Hi folks,\nif you can read that, it means that my SMTPClient"
						. " is working."
						/*, $contentType = "text/html"*/);
$smtpClient->attachFile("Text.txt", "Text file content example");
$smtpClient->attachFile("Image.PNG", 
							file_get_contents("./brae.png"));
$smtpClient->sendMail();
?>