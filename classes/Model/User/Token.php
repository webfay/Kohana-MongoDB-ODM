<?php defined('SYSPATH') OR die('No direct script access.');
/**
 * Default auth user toke
 *
 * @package    ODM/Auth
 */
class Model_User_Token extends ODM {

	protected $_schema = array(
		'user' => 'string',
		'user_agent' => 'string',
		'salt' => 'string',
		'token' => 'string'
	);

	public function save(Validation $external_validation = NULL)
	{
		$this->_document['token'] = sha1(uniqid(Text::random('alnum', 32), TRUE));

		return parent::save($external_validation);
	}

}
