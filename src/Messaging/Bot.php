<?php

namespace Irkalla\Messaging;

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
	 * @param array $request
	 * @throws JsonException
	 * @throws CurlException
	 * @throws FacebookMessengerException
	 */
	public function parseInput(array $request)
	{
		if ((array_key_exists('entry', $request)) and (array_key_exists('object', $request))) {
			if ($this->validateInput($request, self::createMessageConstraint($this->botID))) {
				foreach ($request['entry'] as $entry) {
					$request = ArrayHash::from($entry['messaging'][0]);
					$this->handleQuery($request->message->text, $request->sender->id);
				}
			}
		}

		if (array_key_exists('notification', $request)) {
			if ($this->validateInput($request, self::createNotificationConstraint($this->botID))) {
				$request = ArrayHash::from($request['notification']);
				$attachment = self::createMessageAttachment(
					$request->title,
					$request->text,
					$request->url,
					isset($request->refer) ? $request->refer : NULL
				);

				$exceptions = [];

				foreach ($request->recipients as $recipient) {
					try {
						$this->sendMessage($recipient, ['attachment' => $attachment]);
					} catch (\Exception $exception) {
						$exceptions[] = $exception;
					}
				}

				foreach ($exceptions as $exception) throw $exception;
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
	 * @param Assert\Collection $constraint
	 * @return bool
	 */
	private function validateInput(array $input, Assert\Collection $constraint)
	{
		$validator = Validation::createValidator();
		$violations = $validator->validate($input, $constraint);

		return !boolval(count($violations));
	}

	/**
	 * @param string $botID
	 * @return Assert\Collection
	 */
	private static function createMessageConstraint(string $botID)
	{
		return new Assert\Collection([
			'object' => new Assert\EqualTo('page'),
			'entry' => new Assert\Required([
				new Assert\Type('array'),
				new Assert\Count(['min' => 1]),
				new Assert\All([
					new Assert\Collection([
						'id' => new Assert\Type('string'),
						'time' => new Assert\Type('integer'),
						'messaging' => new Assert\Required([
							new Assert\Type('array'),
							new Assert\Count(['min' => 1]),
							new Assert\All([
								new Assert\Collection([
									'message' => new Assert\Collection([
										'mid' => new Assert\Type('string'),
										'seq' => new Assert\Type('integer'),
										'text' => new Assert\Type('string'),
									]),
									'recipient' => new Assert\Collection([
										'id' => new Assert\EqualTo($botID)
									]),
									'sender' => new Assert\Collection([
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
	}

	/**
	 * @param string $botID
	 * @return Assert\Collection
	 */
	private static function createNotificationConstraint($botID)
	{
		return new Assert\Collection([
			'notification' => new Assert\Collection([
				'botID' => new Assert\EqualTo($botID),
				'title' => new Assert\Type('string'),
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
	}

	/**
	 * @param string $message
	 * @param string $senderId
	 * @return array
	 * @throws JsonException
	 * @throws CurlException
	 * @throws FacebookMessengerException
	 */
	private function handleQuery(string $message, string $senderId)
	{
		$message = Strings::lower($message);
		switch ($message) {
			case 'who':
				$text = (string)$senderId;
				break;
			case 'help':
				$text = '"who" - print print your ID' . "\n" . '"help" - print this message';
				break;
			default:
				$text = 'Dont know what to do yet. Send "help" message to see possible commands.';
				break;
		}

		return $this->sendMessage($senderId, ['text' => $text]);
	}

	/**
	 * @param string $title
	 * @param string $text
	 * @param string $url
	 * @param string|NULL $buttonUrl
	 * @return array
	 */
	private static function createMessageAttachment(string $title, string $text, string $url, string $buttonUrl = NULL)
	{
		$buttons[] = self::createMessageButton($url);
		if ($buttonUrl) $buttons[] = self::createMessageButton($buttonUrl, 'Přejít');

		$attachment = [
			'type' => 'template',
			'payload' => [
				'template_type' => 'generic',
				'elements' => [
					[
						'title' => $title,
						'image_url' => 'https://messenger.vzs-jablonec.eu/img/message.png',
						'subtitle' => $text,
						'default_action' => [
							'type' => 'web_url',
							'url' => $url,
							'webview_height_ratio' => 'tall',
						],
						'buttons' => $buttons,
					],
				],
			],
		];

		return $attachment;
	}

	/**
	 * @param string $url
	 * @param string $title
	 * @return array
	 */
	private static function createMessageButton(string $url, string $title = 'Otevřít')
	{
		return [
			'type' => 'web_url',
			'url' => $url,
			'title' => $title,
		];
	}
}