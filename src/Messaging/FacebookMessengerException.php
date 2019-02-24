<?php
/**
 * Created by PhpStorm.
 * User: Jakub
 * Date: 23.02.2019
 * Time: 14:19
 */

namespace Irkalla\Messaging;


final class FacebookMessengerException extends \Exception
{

	/**
	 * CurlException constructor.
	 * @param array $result
	 */
	public function __construct(array $result)
	{
		parent::__construct($result['error']['message'], $result['error']['code']);
	}

}