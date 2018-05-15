<?php
/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Request;
use Tbot\Tbot;

/**
 * Start command
 *
 * Gets executed when a user first starts using the bot.
 */
class StartCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'start';

    /**
     * @var string
     */
    protected $description = 'Start command';

    /**
     * @var string
     */
    protected $usage = '/start';

    /**
     * @var string
     */
    protected $version = '1.1.0';

    /**
     * @var bool
     */
    protected $private_only = true;

	public function preExecute(){
		
		if($this->isPrivateOnly() && $this->getMessage()->getChat()->getType() != 'private'){
			return Request::emptyResponse();
		}
		return $this->execute();
	}	
	
    /**
     * Command execute method
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute()
    {
		$message = $this->getMessage();		
		$user_id = $message->getFrom()->getId();            
		$chat_id = $message->getChat()->getId();
		
		$tbot = new Tbot($this, $user_id, $chat_id);
		
		return $tbot->start();
		//return Request::emptyResponse();
    }
}
