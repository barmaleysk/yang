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
use Longman\TelegramBot\Conversation;

use Tbot\Tbot;

/**
 * Generic command
 *
 * Gets executed for generic commands, when no other appropriate one is found.
 */
class GenericCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'generic';

    /**
     * @var string
     */
    protected $description = 'Handles generic commands or is executed by default when a command is not found';

    /**
     * @var string
     */
    protected $version = '1.1.0';

    /**
     * Command execute method
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute()
    {
        $update_type = $this->getUpdate()->getUpdateType();
		if(!in_array($update_type, ['message', 'edited_message'])) return Request::emptyResponse();
		
		if($update_type == 'edited_message') $message = $this->getEditedMessage();
		else $message = $this->getMessage();

        //You can use $command as param
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
		$isAdmin = $this->telegram->isAdmin($user_id);
        $command = $message->getCommand();

        $conversation = new Conversation($user_id, $chat_id);
        if ($conversation->exists() && ($commandName = $conversation->getCommand())) {
			if(method_exists('Tbot\Tbot', $commandName)){
				$tbot = new Tbot($this, $user_id, $chat_id);
				
				return $tbot->$commandName($conversation);
				//return Request::emptyResponse();
			}
			elseif(!isset($conversation->notes['_generic_call'])){
				$conversation->notes['_generic_call'] = 1;
				$conversation->update();
				return $this->telegram->executeCommand($commandName);
			}
        }		
		elseif($isAdmin){
			$data = [
				'chat_id' => $chat_id,
				'text'    => 'Command /' . $command . ' not found.. :(',
			];

			return Request::sendMessage($data);
		}
		return Request::emptyResponse();
    }
}
