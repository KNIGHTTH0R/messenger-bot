<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/constants.php';

use Irkalla\Messaging\Bot;
use Nette\Utils\Json;
use Tracy\Debugger;

Debugger::enable(Debugger::DETECT, __DIR__ . '/log');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	if ((array_key_exists('hub_challenge', $_GET))and(array_key_exists('hub_verify_token', $_GET))) {
		$challenge = $_GET['hub_challenge'];
		$hub_verify_token = $_GET['hub_verify_token'];

		if ($hub_verify_token === VERIFY_TOKEN) echo $challenge;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$input = file_get_contents('php://input');
	//$input = file_put_contents(__DIR__ . '/tmp/message.json');

	if ($input){
		$request = Json::decode($input, Json::FORCE_ARRAY);

		$bot = new Bot(ACCESS_TOKEN, BOT_ID);
		$bot->parseInput($request);
	}
}
