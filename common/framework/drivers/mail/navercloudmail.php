<?php

namespace Rhymix\Framework\Drivers\Mail;

/**
 * The Navercloudmail mail driver.
 */
class Navercloudmail extends Base implements \Rhymix\Framework\Drivers\MailInterface
{
	/**
	 * The API URL.
	 */
	protected static $_url = 'https://mail.apigw.ntruss.com/api/v1';
	protected static $_timeout = 10;

	/**
	 * Get the list of configuration fields required by this mail driver.
	 *
	 * @return array
	 */
	public static function getRequiredConfig()
	{
		return array('api_key', 'api_secret');
	}

	/**
	 * Get the SPF hint.
	 *
	 * @return string
	 */
	public static function getSPFHint()
	{
		return 'include:email.ncloud.com';
	}

	/**
	 * Get the DKIM hint.
	 *
	 * @return string
	 */
	public static function getDKIMHint()
	{
		return 'mailer._domainkey';
	}

	/**
	 * Check if the current mail driver is supported on this server.
	 *
	 * This method returns true on success and false on failure.
	 *
	 * @return bool
	 */
	public static function isSupported()
	{
		return function_exists('hash_hmac');
	}

	/**
	 * Create signature for NAVER Cloud gateway server
	 *
	 * @param string $timestamp
	 * @param string $accessKey
	 * @param string $secretKey
	 * This method returns signature of given timestamp, access key and secret key
	 *
	 * @return string
	 */
	private static function _makeSignature($timestamp, $accessKey, $secretKey) {
		$space = " ";  // 공백
		$newLine = "\n";  // 줄바꿈
		$method = "POST";  // HTTP 메소드
		$uri= "/api/v1/mails";  // 도메인을 제외한 "/" 아래 전체 url (쿼리스트링 포함)
		$timestamp = $timestamp;  // 현재 타임스탬프 (epoch, millisecond)
		$accessKey = $accessKey;  // access key id (from portal or sub account)
		$secretKey = $secretKey;  // secret key (from portal or sub account)
	
		$hmac = $method.$space.$uri.$newLine.$timestamp.$newLine.$accessKey;
		$signautue = base64_encode(hash_hmac('sha256', $hmac, $secretKey,true));
	
		return $signautue;
	} 

	/**
	 * Send a message.
	 *
	 * This method returns true on success and false on failure.
	 *
	 * @param object $message
	 * @return bool
	 */
	public function send(\Rhymix\Framework\Mail $message)
	{
		// Prepare data for Requests.
		$data = array(
			'title' => $message->getSubject(),
			'body' => $message->getBody(),
			'senderAddress' => '',
			'senderName' => '',
			'recipients' => array(),
		);

		// Fill the sender info.
		$from = $message->message->getFrom();
		foreach($from as $email => $name)
		{
			$data['senderAddress'] = $email;
			$data['senderName'] = trim($name) ?: substr($email, 0, strpos($email, '@'));
			break;
		}

		// Fill the recipient info.
		if ($to = $message->message->getTo())
		{
			foreach($to as $email => $name)
			{
				$receiver = array();
				$receiver['address'] = $email;
				$receiver['name'] = str_replace(',', '', trim($name) ?: substr($email, 0, strpos($email, '@')));
				$receiver['type'] = "R";

				$data['recipients'][] = $receiver;
			}
		}
		if ($cc = $message->message->getCc())
		{
			foreach($cc as $email => $name)
			{
				$receiver = array();
				$receiver['address'] = $email;
				$receiver['name'] = str_replace(',', '', trim($name) ?: substr($email, 0, strpos($email, '@')));
				$receiver['type'] = "C";

				$data['recipients'][] = $receiver;
			}
		}
		if ($bcc = $message->message->getBcc())
		{
			foreach($bcc as $email => $name)
			{
				$receiver = array();
				$receiver['address'] = $email;
				$receiver['name'] = str_replace(',', '', trim($name) ?: substr($email, 0, strpos($email, '@')));
				$receiver['type'] = "B";

				$data['recipients'][] = $receiver;
			}
		}

		$timestamp = floor(microtime(true) * 1000);

		// Define connection options.
		$headers = array(
			'Content-Type' => 'application/json',
			'x-ncp-apigw-timestamp' => $timestamp,
			'x-ncp-iam-access-key' => $this->_config['api_key'],
			'x-ncp-apigw-signature-v2' => $this::_makeSignature($timestamp, $this->_config['api_key'], $this->_config['api_secret']),
		);

		// Send the API request.
		try
		{
			$request = \Rhymix\Framework\HTTP::post(self::$_url . "/mails", $data, $headers, [], ['timeout' => self::$_timeout]);
			$result = $request->getBody()->getContents();
		}
		catch (\Requests_Exception $e)
		{
			$message->errors[] = 'Navercloudmail: ' . $e->getMessage();
			return false;
		}

		// TODO: proper parsing of body
		debugPrint($result);
		return true;
		/*
		if (!$result)
		{
			$message->errors[] = 'Navercloudmail: Server error: ' . $request->body;
			return false;
		}
		elseif($result->result === 'OK')
		{
			return true;
		}
		else
		{
			if(isset($result->error_msg))
			{
				if(isset(self::$_error_codes[$result->error_msg]))
				{
					$result->error_msg .= ' ' . self::$_error_codes[$result->error_msg];
				}
				$message->errors[] = 'Woorimail: ' . $result->error_msg;
			}
			else
			{
				$message->errors[] = 'Woorimail: Connection error';
			}
			return false;
		}
		*/
	}
}
