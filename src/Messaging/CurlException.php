<?php
/**
 * Created by PhpStorm.
 * User: Jakub
 * Date: 23.02.2019
 * Time: 14:19
 */

namespace Irkalla\Messaging;


final class CurlException extends \Exception
{

	/**
	 * CurlException constructor.
	 * @param $curl
	 */
	public function __construct($curl)
	{
		parent::__construct(curl_error($curl));
	}

}