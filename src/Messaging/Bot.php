<?php

namespace Irkalla\Messaging;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Nette\Utils\ArrayHash;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;
use Tracy\Debugger;

/**
 * Class Bot
 * @package Irkalla\Messaging
 */
final class Bot
{
	const FACEBOOK_URL = 'https://graph.facebook.com/v3.2/me/messages';

	/**
	 * @var int
	 */
	private $curl;

	/**
	 * @var string
	 */
	private $botID;

	/**
	 * Bot constructor.
	 * @param string $accessToken
	 * @param string $botID
	 */
	public function __construct(string $accessToken, string $botID)
	{
		$curl = curl_init(self::FACEBOOK_URL . '?access_token=' . $accessToken);
		curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);

		$this->curl = $curl;
		$this->botID = $botID;
	}

	/**
	 * @param string $input
	 * @throws JsonException
	 */
	public function parseInput(string $input){
		$request = Json::decode($input, Json::FORCE_ARRAY);

		if ((array_key_exists('entry', $request))and(array_key_exists('object', $request))){
			if ($this->validateInputMessage($request)){
				foreach ($request['entry'] as $entry){
					$request = ArrayHash::from($entry['messaging'][0]);
					$this->handleQuery($request->message->text, $request->sender->id);
				}
			}
		}

		if (array_key_exists('notification', $request)) {
			if ($this->validateInputNotification($request)){
				$request = ArrayHash::from($request['notification']);
				$message = self::createMessageAttachment(
					$request->title,
					$request->text,
					$request->url,
					$request->refer
				);

//				foreach ($request->recipients as $recipient){
//					$this->sendMessage($recipient, $message);
//				}

				$requests = function($recipients) use ($message) {
					foreach($recipients as $recipient) {
						yield $recipient => function() use ($recipient, $message) {
							return $this->sendMessage($recipient, $message);
						};
					}
				};

				$pool = new Pool($this->client, $requests($request->recipients));
				$promise = $pool->promise();
				$promise->wait();
			}
		}
	}

	/**
	 * @param string $recipientId
	 * @param array $message
	 * @return array
	 * @throws JsonException
	 * @throws CurlException
	 * @throws FacebookMessengerException
	 */
	private function sendMessage(string $recipientId, array $message)
	{
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, Json::encode([
			'recipient' => ['id' => $recipientId],
			'message' => $message,
		]));

		$result = curl_exec($this->curl);

		if ($result === FALSE) throw new CurlException($this->curl);

		$result = Json::decode($result, Json::FORCE_ARRAY);
		if (array_key_exists('error', $result)) throw new FacebookMessengerException($result);

		return $result;
	}

	/**
	 * @param array $input
	 * @return bool
	 */
	private function validateInputMessage(array $input){
		$constraint = new Assert\Collection([
			'object' => new Assert\EqualTo('page'),
			'entry' =>  new Assert\Required([
				new Assert\Type('array'),
				new Assert\Count(['min' => 1]),
				new Assert\All([
					new Assert\Collection([
						'id' =>  new Assert\Type('string'),
						'time' => new Assert\Type('integer'),
						'messaging' => new Assert\Required([
							new Assert\Type('array'),
							new Assert\Count(['min' => 1]),
							new Assert\All([
								new Assert\Collection([
									'message' => new Assert\Collection([
										'mid' => new Assert\Type('string'),
										'seq' =>  new Assert\Type('integer'),
										'text' =>  new Assert\Type('string'),
									]),
									'recipient' => new Assert\Collection([
										'id' => new Assert\EqualTo($this->botID)
									]),
									'sender'  => new Assert\Collection([
										'id' => new Assert\Type('string')
									]),
									'timestamp' => new Assert\Type('integer')
								]),
							]),
						]),
					]),
				]),
			]),
		]);

		$validator = Validation::createValidator();
		$violations = $validator->validate($input, $constraint);

		return !boolval(count($violations));
	}

	/**
	 * @param array $input
	 * @return bool
	 */
	private function validateInputNotification(array $input){
		$constraint = new Assert\Collection([
			'notification' =>  new Assert\Collection([
				'botID' => new Assert\EqualTo($this->botID),
				'title' =>  new Assert\Type('string'),
				'text' => new Assert\Type('string'),
				'url' => new Assert\Url(),
				'recipients' => new Assert\Required([
					new Assert\Type('array'),
					new Assert\Count(['min' => 1]),
					new Assert\All([
						new Assert\Type('string')
					]),
				]),
				'refer' => new Assert\Optional([
					new Assert\Url()
				])
			]),
		]);

		$validator = Validation::createValidator();
		$violations = $validator->validate($input, $constraint);

		return !boolval(count($violations));
	}

	/**
	 * @param string $message
	 * @param string $senderId
	 * @return Response
	 */
	public function handleQuery(string $message, string $senderId)
	{
		$message = Strings::lower($message);
		switch ($message) {
			case 'who':
				$text = (string) $senderId;
				break;
			case 'help':
				$text = '"who" - print print your ID' . "\n" . '"help" - print this message'; 
				break;
			default:
				$text = 'Dont know what to do yet. Send "help" message to see possible commands.';  
				break;
		}

		$promise = $this->sendMessage($senderId, ['text' => $text]);
		return $promise->wait();
	}


	/**
	 * @param string $title
	 * @param string $text
	 * @param string $url
	 * @param string|NULL $buttonUrl
	 * @return array
	 */
	private static function createMessageAttachment(string $title, string $text, string $url, string $buttonUrl = NULL): array
	{
		return [
			'type' => 'template',
			'payload' => [
				'template_type' => 'generic',
				'elements' => [
			        'title' => $title,
		            'image_url' => 'https://messenger.vzs-jablonec.cz/img/message.png',
		            'subtitle' => $text,
		            'default_action' => [
						'type' => 'web_url',
						'url' => $url,
						'webview_height_ratio' => 'tall',
		            ],
		            'buttons' => $buttonUrl ? [self::createMessageButton($buttonUrl)] : NULL,
				],
			],
		];
	}

	/**
	 * @param string $url
	 * @return array
	 */
	private static function createMessageButton(string $url): array
	{
		return [
	        'type' => 'web_url',
			'url' => $url,
			'title' => 'Přejít',
		];
	}
}