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

use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Commands\SystemCommand;
//use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

use Tbot\Tbot;

/**
 * Callback query command
 *
 * This command handles all callback queries sent via inline keyboard buttons.
 *
 * @see InlinekeyboardCommand.php
 */
class CallbackqueryCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'callbackquery';

    /**
     * @var string
     */
    protected $description = 'Reply to callback query';

    /**
     * @var string
     */
    protected $version = '1.1.1';

    /**
     * Command execute method
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute()
    {
		$callback_query    = $this->getCallbackQuery();
		$callback_query_id = $callback_query->getId();
		
		$chat_id = $callback_query->getMessage()->getChat()->getId();
		$user_id = $callback_query->getFrom()->getId();		
		$isAdmin = $this->getTelegram()->isAdmin($user_id);

        $tbot = new Tbot($this, $user_id, $chat_id);
		
		$conversation = new Conversation($user_id, $chat_id);
        if ($conversation->exists() && ($commandName = $conversation->getCommand())) {	
			if(method_exists($tbot, $commandName)){
				return $tbot->$commandName($conversation);				 
			}
			else{
				$conversation->cancel();
				Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Текущий диалог был отменен!']);
				return Request::answerCallbackQuery(['callback_query_id' => $callback_query_id]);
			}
		}		
		return $tbot->callbackQuery();
    }
}
