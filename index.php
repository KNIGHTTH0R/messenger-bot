<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/constants.php';

use Irkalla\Messaging\Bot;
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
	//$input = file_get_contents(__DIR__ . '/message.json');
	//Debugger::log($input);
	if ($input){
		$bot = new Bot(ACCESS_TOKEN, BOT_ID);
		$bot->parseInput($input);
	}
}
