<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;

use Tbot\Tbot;

class AddproductCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'addProduct';

    /**
     * @var string
     */
    protected $description = 'Пошаговое добавление товара в раздел';

    /**
     * @var string
     */
    protected $usage = '/addProduct <section_id>';

    /**
     * @var string
     */
    protected $version = '0.1.0';
	
	protected $private_only = true;

	/**
	 * {@inheritDoc}
	 * @see \Longman\TelegramBot\Commands\Command::preExecute()
	 */
	public function preExecute(){
		$update_type = $this->getUpdate()->getUpdateType();
		// Реагируем только на апдейты заданного типа
		if(!in_array($update_type, ['message', 'edited_message'])) return Request::emptyResponse();		

		$message = ($update_type == 'edited_message') ? $this->getEditedMessage() : $this->getMessage();
	    $user_id = $message->getFrom()->getId();
	    $isAdmin = $this->telegram->isAdmin($user_id);
	    
		if(!$isAdmin || $message->getChat()->getType() != 'private'){
			// Не реагируем на неавторизованные апдейты 
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
		$update_type = $this->getUpdate()->getUpdateType();
		$message = ($update_type == 'edited_message') ? $this->getEditedMessage() : $this->getMessage();
        $chat_id = $message->getChat()->getId();
		$user_id = $message->getFrom()->getId();
		$config = $this->telegram->getCommandConfig('dummyCommand');
		$text = $message->getText(true);
		$message_type = $message->getType();

		// Старт или возобновление диалога
		$conv = new Conversation($user_id, $chat_id, $this->getName());
		
		if($message_type == 'command'){
			// Введена стартовая команда, устанавливаем начальную стадию в переменной диалога
			switch($message->getCommand()){
				case 'addProduct':
					$conv->notes['stage'] = 'parent_id';
					break;
			}			
		}		
		
		if($message_type == 'text'){
			// Обрабатываем общие для разных стадий действия
			switch($text){
				case 'Отмена':
					$conv->cancel();
					$tbot = new Tbot($this, $user_id, $chat_id);
					return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Добавление товара отменено', 'reply_markup' => $tbot->getDefaultKeyboard()]);
					break;
				case 'Назад':
					$stages = ['name', 'thumb', 'descr', 'price', 'msg'];
					if($position = array_search($conv->notes['stage'], $stages)){
						$response = $this->sendConvStage($conv, $stages[ $position - 1 ]);
						$conv->update();
						return $response;
					}
					break;
			}
		}

		$pdo = DB::getPdo();

		// Обрабатываем ввод в зависимости от текущей стадии
		switch($conv->notes['stage']){
			case 'parent_id':	// Начальная стадия, ожидается ID раздела (в аргументе стартовой команды)
				if(preg_match("/^[0-9]+$/u", $text) && $page = $pdo->query("select * from my_page where id={$text}")->fetch()){
					$conv->notes['page']['parent_id'] = $page['id'];
					Request::sendMessage(['chat_id' => $chat_id, 'text' => "Добавляем новый товар в раздел ".$page['name']]);
					$response = $this->sendConvStage($conv, 'name');
				}
				else{
					$conv->cancel();
					return Request::emptyResponse();
				}
				break;
			case 'name': // Ожидается ввод названия товара
			    if($message_type != 'text') return Request::emptyResponse();
				
				$sth = $pdo->prepare("select id from my_page where name=:name");
				$sth->bindValue(':name', $text);
				$sth->execute();
				
				if($sth->fetch()){
					$data['text'] = 'Товар с таким названием уже существует!';
				}
				else{					
					$conv->notes['page']['name'] = $text;
					$response = $this->sendConvStage($conv, 'thumb');					
				}				
				break;
			case 'thumb': // Ожидается эскиз 
			    if($message_type != 'photo') return Request::emptyResponse();
				
				$doc = end($message->getPhoto());
				$file_id = $doc->getFileId();
				$file    = Request::getFile(['file_id' => $file_id]);
				if ($file->isOk() && Request::downloadFile($file->getResult())) {
					// Выводим уменьшенный эскиз для предпросмотра
					$thumb_folder = $this->telegram->getDownloadPath();
					$source_name = $file->getResult()->getFilePath();
					$conv->notes['page']['thumb'] = Tbot::createThumb($thumb_folder, $source_name);
					
					$download_url = $config['base_url'].$config['download_path'];
					$data1['chat_id'] = $chat_id;
					$data1['photo'] = $download_url . $conv->notes['page']['thumb'];
					$data1['caption'] = 'Уменьшенное изображение';
					Request::sendPhoto($data1);
					
					$response = $this->sendConvStage($conv, 'descr');
				} else {
					$data['text'] = 'Не удалось сохранить фото';
				}								
				break;
			case 'descr': // Ожидается ввод описания
			    if($message_type != 'text') return Request::emptyResponse();
				
				$conv->notes['page']['descr'] = $text;
				$response = $this->sendConvStage($conv, 'price');				
				break;
			case 'price': // Ожидается ввод стоимости
			    if($message_type != 'text' || !preg_match("/^[0-9]+$/u", $text)) return Request::emptyResponse();
				
				$conv->notes['page']['price'] = $text;
				$conv->notes['messages'] = [ 'media' => [] ];
				$response = $this->sendConvStage($conv, 'msg');				
				break;
			case 'msg':	// Ожидается ввод/редактирование наполнения страницы (текст, фото, видео)
			    if(!in_array($message_type, ['text', 'photo', 'video'])) return Request::emptyResponse();
				
				if($text == 'Готово'){
					// Получен маркер завершения ввода
					if(!isset($conv->notes['messages']['text'])){
						return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Вы не отправили текст для страницы товара']);
						break;
					}
					
					// Сохранение в БД
					$page = $conv->notes['page'];
					$position = $pdo->query("select count(*)+1 from my_page where parent_id=".$page['parent_id'])->fetchColumn();
					
					$sth = $pdo->prepare("
						insert into my_page (parent_id, type, thumb, name, descr, price, position, hidden) 
						values(:parent_id, 'product', :thumb, :name, :descr, :price, :position, 1)
					");
					$sth->execute([
						':parent_id' => $page['parent_id'],
						':thumb' => $page['thumb'],
						':name' => $page['name'],
						':descr' => $page['descr'],
						':price' => $page['price'],
						':position' => $position
					]);
					$page['id'] = $pdo->lastInsertId();
					
					$sth = $pdo->prepare('insert into my_message (my_page_id, type, contents, position) values ('.$page['id'].', :type, :contents, :position)');
					
					$contents = [];						
					foreach($conv->notes['messages']['media'] as $media){
						$contents[] = $media;
					}						
					$sth->execute([':type' => 'media_group', ':contents' => json_encode($contents), ':position' => 0]);																	
					
					
					$message = $conv->notes['messages']['text'];
					$sth->execute([':type' => $message['type'], ':contents' => $message['contents'], ':position' => 1]);
					
					// Завершение диалога
					$conv->stop();
					$tbot = new Tbot($this, $user_id, $chat_id);
					Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Товар добавлен! Не забудьте опубликовать его!', 'reply_markup' => $tbot->getDefaultKeyboard()]);					
					return $tbot->displayPage(['id' => $page['id']]);
				}
				else{
					// Сохраняем наполнение в переменных диалога в зависимости от типа сообщения
					if($message_type == 'photo' || $message_type == 'video'){
						if($message_type == 'photo') $doc = end($message->getPhoto());
						else $doc = $message->getVideo();
						
						$file_id = $doc->getFileId();
						$message_id = $message->getMessageId();

						$msg = ['type' => $message_type, 'media' => $file_id];
						if($caption = $message->getCaption()) $msg['caption'] = $caption;
								
						$conv->notes['messages']['media'][$message_id] = $msg;			
					}
					else{
						$msg = ['type' => 'text', 'contents' => $text];
						$conv->notes['messages']['text'] = $msg;
					}
					$conv->update();
					return Request::emptyResponse(); // Ничего не отвечаем до получения Готово
				}				
				break;
		}
		
		$conv->update();
		return isset($response) ? $response : Request::emptyResponse();
    }
	
	/**
	 * Вывод сообщения о переходе на новую стадию диалога
	 * 
	 * @param \Longman\TelegramBot\Conversation $conv
	 * @param string $stage Метка новой стадии диалога
	 * @param array $args Дополнительные данные для формирования сообщения
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function sendConvStage($conv, $stage, $args = []){
		$message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
		$user_id = $message->getFrom()->getId();		
		
		$reply_markup = new Keyboard(['Назад'], ['Отмена']);
		$resize_keyboard = true;
		
		switch($stage){
			case 'name':
				$text = "Название товара, включая вес:";
				$reply_markup = new Keyboard(['Отмена']);
				break;
			case 'thumb':
				$text = 'Фото в списке товаров (уменьшится до квадрата 156x156):';
				break;
			case 'descr':
				$text = 'Описание в списке товаров:';
				break;
			case 'price':
				$text = 'Стоимость в рублях (только цифра):';
				break;
			case 'msg':
				$text = 'Наполняем страницу товара. Можно отправлять фотографии, видео, текст. Сообщения можно редактировать. Если текстовых сообщений несколько, сохраняется только последнее';
				$reply_markup = new Keyboard(['Готово'], ['Назад'], ['Отмена']);
				break;
		}		
		
		$conv->notes['stage'] = $stage;
		
		if($reply_markup instanceof Keyboard && $resize_keyboard) $reply_markup->setResizeKeyboard(true);
		return Request::sendMessage([
			'chat_id' => $chat_id, 
			'text' => $text,
			'parse_mode' => 'markdown',
			'reply_markup' => $reply_markup
		]);		
	}
	
}
