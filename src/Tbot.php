<?php

namespace Tbot;

use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\InlineQuery\InlineQueryResultArticle;
use Longman\TelegramBot\Entities\InputMessageContent\InputTextMessageContent;

use PDO;

class Tbot
{
	
	/**
	 * Telegram команда, которая обрабатывается в данный момент
	 * 
	 * @var \Longman\TelegramBot\Commands\Command
	 */
	public $command;
	
	/**
	 * ID чата, из которого пришла команда (null для Inline запросов)
	 * 
	 * @var int|null
	 */
	public $chat_id;
	
	/**
	 * ID пользователя, от которого пришла команда
	 * 
	 * @var int
	 */
	public $user_id;
	
	/**
	 * Является ли пользователь, от которого пришла команда, администратором бота
	 * 
	 * @var bool
	 */
	public $isAdmin;	
	
	/**
	 * Дополнительные переменные бота
	 * 
	 * @var array
	 */
	public $config;
	
	/**
	 * @param \Longman\TelegramBot\Commands\Command $command
	 * @param int $user_id
	 * @param int $chat_id
	 */
	public function __construct($command, $user_id, $chat_id = null){
		$this->command = $command;
		$this->user_id = $user_id;		
		$this->chat_id = $chat_id;
		
		$telegram = $command->getTelegram();
		$this->isAdmin = $telegram->isAdmin($user_id);
		$this->config = $telegram->getCommandConfig('dummyCommand');
	}
	
	/**
	 * Обработка команды /start
	 * 
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function start(){		
		return $this->displayPage(['type' => 'start']);		
	}
	
	/**
	 * Обработка сообщений за пределами диалогов
	 * 
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function genericMessage(){
		$message = $this->command->getMessage();
		if($message->getType() != 'text') return Request::emptyResponse();		
		$messageText = $message->getText();			
		
        // Пользователь закрыл результаты Inline запроса, не реагируем
		if($messageText == '🤔') return Request::emptyResponse();
		
		// По умолчанию ищем страницу с таким названием
		return $this->displayPage(['name' => $messageText]);
	}
	
	/**
	 * Обработка нажатий Inline кнопок с параметром callback_data 
	 * 
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function callbackQuery(){
        $callback_query    = $this->command->getCallbackQuery();
        $callback_query_id = $callback_query->getId();
        $callback_data     = $callback_query->getData();

        $data = ['callback_query_id' => $callback_query_id];
		
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		$isAdmin = $this->isAdmin;
		
		if(preg_match("/^([a-zA-Z]*)([0-9]*)$/u", $callback_data, $match)){
			switch($match[1]){
				case 'orderCreate': // Нажата кнопка Оформить заказ
					$conv = new Conversation($user_id, $chat_id, 'orderCreateConv');
					$this->orderCreateConv($conv);
					break;
				case 'hide': // Нажата кнопка Скрыть товар
					if($isAdmin && $match[2] && $this->showHidePage($match[2], 1)){						
						Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Страница скрыта!']);
					}				
					break;
				case 'show': // Нажата кнопка Показать товар
					if($isAdmin && $match[2] && $this->showHidePage($match[2], 0)){						
						Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Страница опубликована!']);
					}				
					break;
				case 'edit': // Нажата кнопка Редактировать товар
				    if($isAdmin && $match[2] && $page = DB::getPdo()->query("select * from my_page where id={$match[2]} and type='product'")->fetch(PDO::FETCH_ASSOC)){
				        $conv = new Conversation($user_id, $chat_id, 'editConv');
				        $conv->notes['id'] = $page['id'];
				        $this->sendConvStage($conv, 'edit_start', $page);
				        $conv->update();
				    }
				    break;
				case 'cartAdd': // Нажата кнопка В корзину
					if($match[2]) $data += $this->cartAdd($match[2]);
					break;
				case 'cartIncrease': // Нажата кнопка Добавить (в корзине)
					if($match[2]) $data += $this->cartUpdate($match[2], 'increase', $user_id, $chat_id);
					break;
				case 'cartDecrease': // Нажата кнопка Убрать (в корзине)
					if($match[2]) $data += $this->cartUpdate($match[2], 'decrease', $user_id, $chat_id);
					break;
				case '': // Нажата кнопка перехода на товар/раздел
					if($match[2]) $this->displayPage(['id' => $match[2]]);
					break;
			}
		}
		
		return Request::answerCallbackQuery($data);
	}	
	
	/**
	 * Обработка Inline запросов
	 * 
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function inlineQuery(){
		$inline_query = $this->command->getInlineQuery();
        $query        = $inline_query->getQuery();
		$isAdmin = $this->isAdmin;
		$download_url = $this->config['base_url'].$this->config['download_path'];

        $data    = ['inline_query_id' => $inline_query->getId(), 'is_personal' => false, 'cache_time' => 30, 'results' => '[]'];
		
        // Не реагируем на запросы короче 3 символов
        if(mb_strlen($query, 'utf-8') < 3) return Request::answerInlineQuery($data);
		
        $results = [];
		
		if($page = $this->fetchPage(['name' => $query, 'type' => 'section'])){
		    // Если есть точное совпадение с названием раздела, выводим все товары из него
			$telegram = $this->command->getTelegram();
			if($isAdmin){
			    // Кнопка добавления товара в раздел
				$results[] = new InlineQueryResultArticle([
					'id' => 'a',
					'title' => 'Добавить товар',
					'description'           => 'Нажмите, чтобы добавить новый товар',
					'thumb_url'				=> $download_url . 'add_grey_156x156.jpg',
					'thumb_width' 			=> 156,
					'thumb_height' 			=> 156,
					'input_message_content' => new InputTextMessageContent(['message_text' => '/addProduct '.$page['id']]),
				]);
				$data['is_personal'] = true;
				$data['cache_time'] = 5;
			}
			
			$products = $page['children'];
		}
		else{
		    // Иначе ищем по названию товаров и выводим совпадения
			$products = $this->searchPages(['name' => $query], ['type' => 'product']);
		}
		
		foreach($products as $child){				
			$results[] = new InlineQueryResultArticle([
				'id'                    => $child['id'],
				'title'                 => ($child['hidden'] ? '🔒 ' : '').$child['name'].', '.$child['price'].'₽',
				'description'           => $child['descr'],
				'thumb_url'				=> $download_url.$child['thumb'],
				'thumb_width' 			=> 156,
				'thumb_height' 			=> 156,
				'input_message_content' => new InputTextMessageContent(['message_text' => $child['name']]),
			]);
		}
		
		if(count($results)){
		    // Кнопка закрытия списка
			$results[] = new InlineQueryResultArticle([
				'id' => 'x',
				'title' => 'Закрыть',
				'description'           => 'Нажмите, чтобы скрыть список',
				'thumb_url'				=> $download_url.'close_grey_156x156.jpg',
				'thumb_width' 			=> 156,
				'thumb_height' 			=> 156,
				'input_message_content' => new InputTextMessageContent(['message_text' => '🤔']),
			]);				
		}		

        $data['results'] = '[' . implode(',', $results) . ']';		

        return Request::answerInlineQuery($data);		
	}
	
	/**
	 * Форматирование товара в корзине
	 * 
	 * @param array $product Данные товара из БД
	 * @param boolean $lock Заблокировать кнопки управления
	 * @return array Форматированное сообщение
	 */
	public function formatCartProduct($product, $lock = false){
		$data = [
			'text' => '*'.$product['name'].', '.$product['price']."₽*\nКоличество: ".$product['quanity'].' | Всего: '.($product['price']*$product['quanity']).'₽',
			'parse_mode' => 'markdown'
		];
		if(!$lock) $data['reply_markup'] = new InlineKeyboard([
			['text' => '➕ Добавить', 'callback_data' => 'cartIncrease'.$product['id']],
			['text' => '➖ Убрать', 'callback_data' => 'cartDecrease'.$product['id']],
			['text' => '🔍 Перейти', 'callback_data' => $product['id']]
		]);
		return $data;
	}
	
	/**
	 * Форматирование суммы товаров в корзине
	 * 
	 * @param int $sum Сумма
	 * @param boolean $lock Заблокировать кнопки управления
	 * @return array Форматированное сообщение
	 */
	public function formatCartSum($sum, $lock = false){
		$data = [
			'text' => '*Сумма заказа: '.$sum.'₽*',
			'parse_mode' => 'markdown'
		];
		if(!$lock) $data['reply_markup'] = new InlineKeyboard([
			['text' => '📋 Оформить', 'callback_data' => 'orderCreate'] // 🎀  🎉
		]);
		return $data;
	}
	
	/**
	 * Вывод страницы из БД
	 * 
	 * @param array $values Набор фильтров для поиска страницы
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function displayPage($values){		
		$page = $this->fetchPage($values);
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		
		if($page === false || $page['type'] == 'section') return Request::emptyResponse();				
        
		if($page['type'] == 'catalog'){
		    // Вывод разделов/подразделов каталога парами
			if(count($page['children']) < 2) return Request::emptyResponse();
			$pair = []; // Массив, в котором будут пары разделов для вывода
			foreach($page['children'] as $child){
				$pair[] = $child;
				if(count($pair) != 2) continue; // Ждем, пока в массиве будет два раздела
				
				// Формируем кнопки для перехода в разделы
				$keyboard = [];
				foreach([0,1] as $i){
					$button = ['text' => $pair[$i]['name']];
					if($pair[$i]['type'] == 'catalog') $button['callback_data'] = $pair[$i]['id'];
					else $button['switch_inline_query_current_chat'] = $pair[$i]['name'];
					$keyboard[] = $button;
				}
				
				// Отправляем фото двух разделов с кнопками
				$data = [
					'chat_id'      => $chat_id,
					'photo' => $this->config['base_url'].'collage.php?f='.$pair[0]['thumb'].'&s='.$pair[1]['thumb'],
					'reply_markup' => new InlineKeyboard($keyboard),
					'disable_notification' => true,
				];				
				$response = Request::sendPhoto($data);
				
				$pair = [];
			}
			return $response;
		}
		elseif($page['type'] == 'cart'){
		    // Вывод корзины
			$cart = $this->fetchCartContents();
			if(!count($cart)) return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'В вашей корзине ничего нет! Совсем ничего 😭']);
			
			$sum = 0;
			$oldSumDeleted = false;
			// Поочередный вывод товаров в корзине
			foreach($cart as $product){
				if($product['sum_message_id'] && !$oldSumDeleted){
				    // Удаляем предыдущее сообщение с суммой товаров в корзине, если оно было
					Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['sum_message_id']]);
					$oldSumDeleted = true;
				}
				if($product['message_id']){
				    // Удаляем предыдущее сообщение с этим товаром в корзине, если оно было
					Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['message_id']]);
				}				
				
				$data = $this->formatCartProduct($product);
				$data['chat_id'] = $chat_id;
				$sent = Request::sendMessage($data);
				
				if($sent->isOk()){		
				    // Сохраняем ID сообщения с этим товаром в корзине
					DB::getPdo()->query("update my_cart set message_id=".$sent->getResult()->getMessageId()." where user_id={$user_id} and my_page_id=".$product['id']);
				}
				$sum += $product['price'] * $product['quanity'];
			}
			
			// Вывод суммы товаров в корзине
			$data = $this->formatCartSum($sum);
			$data['chat_id'] = $chat_id;
			
			$sentSum = Request::sendMessage($data);
			if($sentSum->isOk()){
			    // Сохраняем ID сообщения с суммой товаров в корзине (в каждую запись товара)
				DB::getPdo()->query("update my_cart set sum_message_id=".$sentSum->getResult()->getMessageId()." where user_id={$user_id}");
			}
			return $sentSum;
		}
		else{
		    // Вывод товаров и информационных страниц
			if(!count($page['messages'])) return Request::emptyResponse();
			foreach($page['messages'] as $i => $message){
				$data = ['chat_id' => $chat_id];
				switch($message['type']){	
				  case 'media_group':
					$contents = json_decode($message['contents'], true);
					$media = [];
					foreach($contents as $element) {
						if(!isset($element['type'])) $element['type'] = 'photo';
						$media[] = $element;
					}
					$data['media'] = $media;
					$method = 'sendMediaGroup';									  
					break;				
				  case 'photo':
					$data['photo'] = $message['contents'];
					$method = 'sendPhoto';
					break;
				  case 'video':
					$data['video'] = $message['contents'];
					$method = 'sendVideo';
					break;
				  case 'text':
					$data += [						
						'text'    => $message['contents'],
						'parse_mode' => 'Markdown',
						'disable_web_page_preview' => true,
					];
					$method = 'sendMessage';
					break;
				}
				
				if($page['type'] == 'product' && count($page['messages']) - $i == 1){
				    // Клавиатура для последнего сообщения на странице товара
					$section = $this->fetchParents($page['parent_id']);
					
					$kbRows = [
						[ ['text' => '🛒 В корзину', 'callback_data' => 'cartAdd'.$page['id']] ], []
					];
					
					if($section['parent']['parent_id']){
					    // Если товар в подразделе, показываем три дополнительных кнопки
						$kbRows[0][] = ['text' => $section['name'], 'switch_inline_query_current_chat' => $section['name']]; // На подраздел
						
						$kbRows[1][] = ['text' => $section['parent']['name'], 'callback_data' => $section['parent']['id']]; // На раздел
						$kbRows[1][] = ['text' => $section['parent']['parent']['name'], 'callback_data' => $section['parent']['parent']['id']]; // На корень каталога
					}
					else{
					    // Если товар в разделе, показываем две дополнительных кнопки
						$kbRows[1][] = ['text' => $section['name'], 'switch_inline_query_current_chat' => $section['name']]; // На раздел
						$kbRows[1][] = ['text' => $section['parent']['name'], 'callback_data' => $section['parent']['id']];	// На корень каталога				
					}
					
					if($this->isAdmin){
					    // Кнопки управления товаром
						$kbRows[2] = [];
						if($page['hidden']) $kbRows[2][] = ['text' => '🔓 Показать', 'callback_data' => 'show'.$page['id']];
						else $kbRows[2][] = ['text' => '🔒 Скрыть', 'callback_data' => 'hide'.$page['id']];
						$kbRows[2][] = ['text' => '✏️ Изменить', 'callback_data' => 'edit'.$page['id']];						
					}					
					
					$data['reply_markup'] = new InlineKeyboard(...$kbRows);					
				}
				elseif(isset($message['reply_keyboard'])){
				    // Если у сообщения есть своя клавиатура
					$keyboard = new Keyboard(...$message['reply_keyboard']);
					$keyboard->setResizeKeyboard(true); //->setOneTimeKeyboard(true)
					$data['reply_markup'] = $keyboard;
				};
				
				$response = Request::$method($data);				
			}
			return $response;
		}
	}
	
	/**
	 * Поиск страницы в БД
	 * 
	 * @param array $values Набор фильтров для поиска страницы
	 * @return boolean|array
	 */
	public function fetchPage($values){
		$isAdmin = $this->isAdmin;
		
		$where = []; // Набор SQL условий для поиска
		foreach($values as $key => $val) $where[] = $key.'=:'.$key;
		if(!$isAdmin) $where[] = 'hidden=0';
		
		$pdo = DB::getPdo();
		$sth = $pdo->prepare("select * from my_page where ".implode(' and ', $where).' limit 1');
		
		foreach($values as $key => $val) $sth->bindValue(':'.$key, $val);
		if(!$sth->execute() || !$page = $sth->fetch(PDO::FETCH_ASSOC)) return false;
		
		if($page['type'] == 'catalog' || $page['type'] == 'section'){
		    // Для каталога и разделов ищем дочерние страницы
			$childrenWhere = ["parent_id={$page['id']}"];
			if(!$isAdmin) $childrenWhere[] = "hidden=0";
			$children = $pdo->query("select * from my_page where ".implode(' and ', $childrenWhere)." order by position asc")->fetchAll(PDO::FETCH_ASSOC);
			$page['children'] = $children;
		}
		else{
		    // Для остальных страниц ищем сообщения для вывода
			$messages = $pdo->query("select * from my_message where my_page_id={$page['id']} order by position asc")->fetchAll(PDO::FETCH_ASSOC);		
			
			if($page['type'] != 'product'){
			    // Ищем клавиатуру для сообщений на страницах (кроме страниц товаров)
				foreach($messages as $i => $message){
					$buttons = $pdo->query("select * from my_button where my_message_id={$message['id']} order by row asc, position asc")->fetchAll(PDO::FETCH_ASSOC);
					foreach($buttons as $button){
						$key = $button['type'].'_keyboard';
						if(!isset($messages[$i][$key][$button['row']])) $messages[$i][$key][$button['row']] = [];
						$messages[$i][$key][$button['row']][] = $button['text'];
					}
				}
			}
			
			$page['messages'] = $messages;
		}
		
		return $page;
	}
	
	/**
	 * Поиск товара в БД (для Inline запроса)
	 * 
	 * @param array $like Набор нестрогих фильтров
	 * @param array $strict Набор строгих фильтров
	 * @return array
	 */
	public function searchPages($like = [], $strict = []){
		$isAdmin = $this->isAdmin;
		
		$where = []; // Набор SQL условий для поиска
		foreach($like as $key => $val) $where[] = "{$key} like :{$key}";
		foreach($strict as $key => $val) $where[] = "{$key}=:{$key}";
		if(!$isAdmin) $where[] = 'hidden=0';
		
		$pdo = DB::getPdo();
		$sth = $pdo->prepare("select * from my_page where ".implode(' and ', $where)." order by name asc");
		foreach($like as $key => $val) $sth->bindValue(':'.$key, "%{$val}%");
		foreach($strict as $key => $val) $sth->bindValue(':'.$key, $val);
		
		if(!$sth->execute()) return [];
		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}
	
	/**
	 * Выборка из БД товаров в корзине
	 * 
	 * @return array
	 */
	public function fetchCartContents(){
		$pdo = DB::getPdo();
		
		return $pdo->query("
			select * from my_cart left join my_page on my_cart.my_page_id=my_page.id 
			where my_cart.user_id={$this->user_id} and my_page.id is not null and my_page.hidden=0 and my_page.type='product'
			order by my_cart.id asc
		")->fetchAll(PDO::FETCH_ASSOC);
	}
	
	/**
	 * Рекурсивная выборка родителей страницы
	 * 
	 * @param int $id ID страницы
	 * @return array Данные родителя
	 */
	public function fetchParents($id){
		$pdo = DB::getPdo();
		
		$parent = $pdo->query("select * from my_page where id={$id} limit 1")->fetch(PDO::FETCH_ASSOC);
		if($parent['parent_id'] > 0) $parent['parent'] = $this->fetchParents($parent['parent_id']);
		return $parent;
	}
	
	/**
	 * Установка атрибута скрытой страницы
	 * 
	 * @param int $id ID страницы
	 * @param int $hidden 0|1 
	 * @return number
	 */
	public function showHidePage($id, $hidden){
		$pdo = DB::getPdo();
		return $pdo->query("update my_page set hidden={$hidden} where id={$id} limit 1")->rowCount();
	}
	
	/**
	 * Удаление страницы
	 * 
	 * @param int $id
	 * @return boolean|int
	 */
	public function deletePage($id){
		$pdo = DB::getPdo();
		$telegram = $this->command->getTelegram();
		
		if(!$page = $pdo->query("select * from my_page where id={$id} limit 1")->fetch(PDO::FETCH_ASSOC)) return false;
		
		if($page['thumb'] != ''){
		    // Удаляем эскиз страницы, если он больше не используется
			$file = $telegram->getDownloadPath() . '/' . $page['thumb'];
			$count = $pdo->query("select count(*) from my_page where thumb='{$page['thumb']}' and id!={$page['id']}")->fetchColumn();			
			if($count == 0 && file_exists($file)) unlink($file);
		}
		
		$pdo->query("delete from my_message where my_page_id={$page['id']}");
		return $pdo->query("delete from my_page where id={$page['id']}")->rowCount();		
	}
	
	/**
	 * Добавление товара в корзину
	 * 
	 * @param int $page_id ID товара
	 * @return array Данные для ответа на callback
	 */
	public function cartAdd($page_id){
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		$pdo = DB::getPdo();
		
		$pdo->query("insert into my_cart (user_id, my_page_id, quanity) values ({$user_id}, {$page_id}, 1) on duplicate key update quanity = quanity+1");
		
		$cart = $this->fetchCartContents($user_id);

		if(count($cart)){
			$text = "В вашей корзине:\n\n";
			$sum = 0;
			$oldSumDeleted = false;
			foreach($cart as $product){				
				$text .= "{$product['quanity']}x {$product['name']}, {$product['price']}₽\n";
				$sum += $product['price'] * $product['quanity'];				
				
				if(!$oldSumDeleted && $product['sum_message_id']){
					Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['sum_message_id']]);
					$oldSumDeleted = true;
				}
				if($product['message_id']) Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['message_id']]);
			}
			$pdo->query("update my_cart set message_id=0, sum_message_id=0 where user_id={$user_id}");
			$text .= "\nСумма: {$sum}₽";
		}
		else{
			$text = "В вашей корзине пусто!\n\nТовара, который вы хотите добавить, нет в наличии";
		}
		
		return ['text' => $text, 'show_alert' => true];
	}
	
	/**
	 * Изменение товара в корзине
	 * 
	 * @param int $page_id ID товара
	 * @param string $action "increase"|"decrease" действие с товаром
	 * @return array Данные для ответа на callback
	 */
	public function cartUpdate($page_id, $action){
		$pdo = DB::getPdo();		
		$user_id = $this->user_id;
		$chat_id = $this->chat_id;
		$cart = $this->fetchCartContents($user_id);
		
		$sum = 0;
		$sum_message_id = 0;
		foreach($cart as $product){			
			if($product['id'] != $page_id) {
				$sum += $product['price'] * $product['quanity'];
				if(in_array($action, ['increase', 'decrease'])) continue;
			}
			
			if(!$sum_message_id && $product['sum_message_id']) $sum_message_id = $product['sum_message_id'];
			if($action == 'increase') $product['quanity']++;
			elseif($action == 'decrease') $product['quanity']--;
			
			if($product['quanity'] == 0){
				$pdo->query("delete from my_cart where user_id={$user_id} and my_page_id={$product['id']}");
				if($product['message_id']) Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['message_id']]);
			}
			else{
				if(in_array($action, ['increase', 'decrease'])){
					$sum += $product['price'] * $product['quanity'];
					$pdo->query("update my_cart set quanity={$product['quanity']} where user_id={$user_id} and my_page_id={$product['id']}");	
				}
				$data = $this->formatCartProduct($product, $action == 'lock');
				$data['chat_id'] = $chat_id;
				$data['message_id'] = $product['message_id'];				
				
				if($product['message_id']) Request::editMessageText($data);
			}
		}
		
		if($sum_message_id){
			if($sum == 0){
				Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $sum_message_id]);
				$this->displayPage(['type' => 'cart']);
			}
			else{
				$data = $this->formatCartSum($sum, $action == 'lock');
				$data['chat_id'] = $chat_id;
				$data['message_id'] = $sum_message_id;
				
				Request::editMessageText($data);
			}
		}		
		
		return ['text' => 'Корзина обновлена!', 'alert' => false];		
	}
	
	/**
	 * Обработка сообщений в диалоге оформления заказа
	 * 
	 * @param \Longman\TelegramBot\Conversation $conv
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function orderCreateConv($conv){
		$commandName = $this->command->getName();
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		
		$pdo = DB::getPdo();	
		
		if($commandName == 'callbackquery'){
		    // Нажата inline кнопка с параметром callback_data
			$callback_query = $this->command->getCallbackQuery();
			$callback_data = $callback_query->getData();
			$callback_query_id = $callback_query->getId();
			$data = ['callback_query_id' => $callback_query_id];
			
			if($callback_data == 'orderCreate' && !isset($conv->notes['stage'])){
			    // Нажата кнопка оформления заказа, создаем диалог
				Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Начинаем оформление заказа. Этот процесс можно прервать в любой момент, набрав /cancel']);
				
				if($contact = $pdo->query("select * from my_contact where user_id={$user_id}")->fetch(PDO::FETCH_ASSOC)){
					$this->sendConvStage($conv, 'contact_confirm', ['first_name' => $contact['first_name']]);					
				}
				else{
					$this->sendConvStage($conv, 'request_contact', ['first_name' => $callback_query->getFrom()->getFirstName()]);
				}
				$conv->update();
			}
			else{
			    // Нажата посторонняя кнопка
				$data += [
					'text' => 'Во время оформления заказа эти кнопки отключены. Если вы хотите прервать оформление заказа, наберите /cancel',
					'show_alert' => true
				];
			}
			return Request::answerCallbackQuery($data);			
		}

		$message = $this->command->getMessage();			
		$message_type = $message->getType();
		
		if($message_type == 'command'){
		    // Обрабатываем команду /cancel
			switch($message->getCommand()){
				case 'cancel':
					$conv->cancel();				
					return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Оформление заказа отменено :(', 'reply_markup' => $this->getDefaultKeyboard()]);
			}
		}
		
		// Обрабатываем ввод в зависимости от стадии диалога
		switch($conv->notes['stage']){
			case 'contact_confirm':	// Ожидается контакт получателя заказа
				$my_contact = $pdo->query("select * from my_contact where user_id={$user_id}")->fetch(PDO::FETCH_ASSOC);
				if($message_type == 'text' && $message->getText() == 'Я - получатель заказа'){
				    // Берем контакт заказчика
					$conv->notes['contact'] = $my_contact;
				}
				elseif($message_type == 'contact'){
					$contact = $message->getContact();
					$conv->notes['contact'] = [
						'phone_number' => $this->escapeMarkdown($contact->getPhoneNumber()),
						'first_name' => $this->escapeMarkdown($contact->getFirstName()),
						'last_name' => $this->escapeMarkdown($contact->getLastName()),
						'user_id' => $contact->getUserId(),
						'address' => $my_contact['address'], // Берем адрес из контакта заказчика
					];
				}
				else break;
			
				if($conv->notes['contact']['address'] == ''){						 
					$response = $this->sendConvStage($conv, 'address');
				}		
				else{					
					$response = $this->sendConvStage($conv, 'address_confirm');
				}				
				break;
			case 'request_contact': // Ожидается контакт заказчика
				if($message_type != 'contact') break;
				$contact = $message->getContact();
				if($contact->getUserId() != $user_id){
					$response = Request::sendMessage(['chat_id' => $chat_id, 'text' => $contact->getFirstName().' это не вы!']);
				}
				else{
				    // Сохраняем контакт в БД
					$sth = $pdo->prepare("insert into my_contact (phone_number, first_name, last_name, user_id) values (:phone_number, :first_name, :last_name, :user_id)");
					if($sth->execute([
						':phone_number' => $this->escapeMarkdown($contact->getPhoneNumber()),
						':first_name' => $this->escapeMarkdown($contact->getFirstName()),
						':last_name' => $this->escapeMarkdown($contact->getLastName()),
						':user_id' => $user_id
					])){						
						$response = $this->sendConvStage($conv, 'contact_confirm');
					}
					else{
						$response = Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Что-то пошло не так! Попробуйте отправить контакт еще раз!']);						
					}
				}				
				break;
			case 'address': // Ожидается ввод адреса доставки
				if($message_type != 'text') break;
				$address = $this->escapeMarkdown($message->getText());				
				$sth = $pdo->prepare("update my_contact set address=:address where user_id={$user_id}");
				if($sth->execute([':address' => $address])){
					$response = $this->sendConvStage($conv, 'time');
					$conv->notes['contact']['address'] = $address;
				}
				else{
					$response = Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Что-то пошло не так! Попробуйте отправить адрес еще раз!']);
				}
				break;
			case 'address_confirm': // Ожидается подтверждение прежнего адреса доставки
				if($message_type != 'text') break;				
				if(in_array($message->getText(), ['Да', 'да'])){
					$response = $this->sendConvStage($conv, 'time');
				}
				else{
					$response = $this->sendConvStage($conv, 'address');
				}				
				break;
			case 'time': // Ожидается ввод времени доставки
				if($message_type != 'text') break;

				$conv->notes['time'] = $this->escapeMarkdown($message->getText());
				$response = $this->sendConvStage($conv, 'fop');			
				break;
			case 'fop': // Ожидается выбор формы оплаты
				if($message_type != 'text') break;
				
				$conv->notes['fop'] = $this->escapeMarkdown($message->getText());
				$response = $this->sendConvStage($conv, 'special');
				break;
			case 'special': // Ожидается ввод особых пожеланий
				if($message_type != 'text') break;
				
				$conv->notes['special'] = $this->escapeMarkdown($message->getText());
				$response = $this->sendConvStage($conv, 'summary');				
				break;
			case 'summary': // Ожидается подтверждение правильности заказа
				if($message_type != 'text') break;
				switch($message->getText()){
					case 'Отправить заказ':
						return $this->orderCreateFinish($conv);
						break;
					case 'Изменить получателя':
						$response = $this->sendConvStage($conv, 'contact_confirm');
						$conv->notes['stage_rewrite'] = 'summary';
						break;
					case 'Изменить адрес доставки':
						$response = $this->sendConvStage($conv, 'address');
						$conv->notes['stage_rewrite'] = 'summary';
						break;
					case 'Изменить время доставки':
						$response = $this->sendConvStage($conv, 'time');
						$conv->notes['stage_rewrite'] = 'summary';					
						break;
					case 'Изменить форму оплаты':
						$response = $this->sendConvStage($conv, 'fop');
						$conv->notes['stage_rewrite'] = 'summary';					
						break;
					case 'Изменить особые пожелания':
						$response = $this->sendConvStage($conv, 'special');
						$conv->notes['stage_rewrite'] = 'summary';					
						break;
					case 'Отменить оформление':						
						$conv->cancel();						
						return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Оформление заказа отменено :(', 'reply_markup' => $this->getDefaultKeyboard()]);
				}				
				break;
		}
		$conv->update();
		return isset($response) ? $response : Request::emptyResponse();
	}
	
	/**
	 * Обработка сообщений в диалоге редактирования страницы товара
	 *
	 * @param \Longman\TelegramBot\Conversation $conv
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function editConv($conv){
	    $update_type = $this->command->getUpdate()->getUpdateType();		
		$chat_id = $this->chat_id;
	    $user_id = $this->user_id;
		$pdo = DB::getPdo();
		
	    if($update_type == 'callback_query'){
	        // Нажата inline кнопка с параметром callback_data
	        $callback_query = $this->command->getCallbackQuery();
	        $callback_query_id = $callback_query->getId();
			$callback_data     = $callback_query->getData();
			$answer = ['callback_query_id' => $callback_query_id];
			
			if($callback_data == 'del_media'){
			    // Нажата кнопка удаления фото/видео
				$message_id = $callback_query->getMessage()->getMessageId();
				$i = array_search($message_id, $conv->notes['media']);
				if($i !== false && $my_message = $pdo->query("select * from my_message where my_page_id=".$conv->notes['id']." and type='media_group' limit 1")->fetch(PDO::FETCH_ASSOC)){
					$media = json_decode($my_message['contents'], true);
					if(isset($media[$i])){
						unset($media[$i]);
						$sth = $pdo->prepare("update my_message set contents=:contents where id=".$my_message['id']);
						$sth->execute([':contents' => json_encode($media)]);
						Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $conv->notes['media'][$i]]);
						return Request::answerCallbackQuery($answer + ['text' => 'Удалено', 'show_alert' => false]);
					}
				}
				return Request::answerCallbackQuery($answer);
			}
			
			// Нажата посторонняя кнопка
	        return Request::answerCallbackQuery($answer + [
	            'text' => 'Во время редактирования страницы эти кнопки отключены',
	            'show_alert' => false	            
	        ]);	        
	    }
		if($update_type == 'edited_message'){
			$message = $this->command->getEditedMessage();
		}
	    else{
			$message = $this->command->getMessage();
		}
	    $message_type = $message->getType();
	    
		// Выбираем страницу товара из БД
	    $page = $pdo->query("select * from my_page where id=".$conv->notes['id']." and type='product'")->fetch(PDO::FETCH_ASSOC);
	    if(!$page){
	        $conv->cancel();
	        return Request::sendMessage([
	            'chat_id' => $chat_id,
	            'text' => 'Не удается найти товар',
	            'reply_markup' => $this->getDefaultKeyboard()
	        ]);
	    }
	    
	    // Обработка кнопки Назад, доступной на разных стадиях диалога
	    if($message_type == 'text' && $message->getText() == 'Назад'){			
			$result = $this->sendConvStage($conv, 'edit_start');
			$conv->update();
			return $result;
		}
		
		// Обработка ввода в зависимости от стадии диалога
	    switch($conv->notes['stage']){
	        case 'edit_start': // Начальный экран, ожидается фото/видео для добавления к товару или нажатие текстовой кнопки для перехода к другой стадии
	            if(!in_array($message_type, ['text', 'photo', 'video'])) return Request::emptyResponse();
	            
	            if(in_array($message_type, ['photo', 'video'])){
	                // Фото/видео для добавления к товару
					if($message_type == 'photo') {
						$doc = end($message->getPhoto());
						$mention = 'Фото';
					}
					else {
						$doc = $message->getVideo();
						$mention = 'Видео';
					}
					
					$file_id = $doc->getFileId();
					
					$msg = $pdo->query("select * from my_message where my_page_id={$page['id']} and type!='text' order by position asc limit 1")->fetch(PDO::FETCH_ASSOC);
					if($msg['type'] == 'media_group'){
						$msg['contents'] = json_decode($msg['contents'], true);						
					}
					else{
						$msg['contents'] = [ ['type' => $msg['type'], 'media' => $msg['contents']] ];
						$msg['type'] = 'media_group';
					}
					
					$msg['contents'][ $message->getMessageId() ] = ['type' => $message_type, 'media' => $file_id];
					$msg['contents'] = json_encode($msg['contents']);
					
					$sth = $pdo->prepare("update my_message set type=:type, contents=:contents where id=".$msg['id']);
					$sth->execute([':type' => $msg['type'], ':contents' => $msg['contents']]);
					
					if($update_type == 'edited_message') return Request::emptyResponse();
	                return Request::sendMessage(['chat_id' => $chat_id, 'text' => $mention.' добавлено к товару']);
	            }
	            
	            // Переходы к другим стадиям диалога
	            switch($message->getText()){
	                case 'Выход':
	                    $conv->stop();
	                    Request::sendMessage([
	                        'chat_id' => $chat_id,
	                        'text' => 'Редактирование завершено',
	                        'reply_markup' => $this->getDefaultKeyboard()
	                    ]);
	                    // Завершаем диалог и показываем действующую страницу товара
						return $this->displayPage(['id' => $page['id']]);
	                    break;
	                case 'Место в списке':
	                    $response = $this->sendConvStage($conv, 'edit_position', $page);
	                    break;
	                case 'Цена':
	                    $response = $this->sendConvStage($conv, 'edit_price', $page);
	                    break;
	                case 'Текст':
	                    $my_message = $pdo->query("select * from my_message where my_page_id=".$conv->notes['id']." and type='text'")->fetch(PDO::FETCH_ASSOC);
	                    if(!$my_message){
							$text = 'Текст отсутствует'; // Todo: fix
						}
	                    Request::sendMessage(['chat_id' => $chat_id, 'text' => $my_message['contents'], 'parse_mode' => 'markdown']);
	                    
	                    $response = $this->sendConvStage($conv, 'edit_message');
	                    break;
	                case 'Название':
	                    $response = $this->sendConvStage($conv, 'edit_name', $page);
	                    break;
	                case 'Эскиз':
	                    // Показываем текущий эскиз
						$download_url = $this->config['base_url'].$this->config['download_path'];
	                    Request::sendPhoto(['chat_id' => $chat_id, 'photo' => $download_url.$page['thumb']]);
	                    $response = $this->sendConvStage($conv, 'edit_thumb');
	                    break;
	                case 'Описание':
	                    $response = $this->sendConvStage($conv, 'edit_descr', $page);
	                    break;
					case 'Удалить фото':
					    // Показываем фото/видео отдельными сообщениями с кнопкой Удалить
						$my_message = $pdo->query("select * from my_message where my_page_id=".$page['id']." and type!='text' limit 1")->fetch(PDO::FETCH_ASSOC);
						if(!$my_message){
							return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Фотографии отсутствуют']);
						}
						
						if($my_message['type'] != 'media_group'){
							$media = [ ['type' => $my_message_type, 'media' => $my_message['contents']] ];
							$sth = $pdo->prepare("update my_message set type='media_group', contents=:contents where id=".$my_message['id']);
							$sth->execute([':contents' => json_encode($media)]);
						}
						else{
							$media = json_decode($my_message['contents'], true);
						}
						
						if(!count($media)) return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Фотографии отсутствуют']);
						
						$conv->notes['media'] = [];
						foreach($media as $i => $item){
							$data = ['chat_id' => $chat_id, 'reply_markup' => new InlineKeyboard([
								['text' => 'Удалить', 'callback_data' => 'del_media']
							])];
							if(!isset($item['type']) || $item['type'] == 'photo'){
								$data['photo'] = $item['media'];
								$method = 'sendPhoto';
							}	
							else{
								$data['video'] = $item['media'];
								$method = 'sendVideo';
							}
							$sent = Request::$method($data);
							$conv->notes['media'][$i] = $sent->getResult()->getMessageId();
						}
						$response = $this->sendConvStage($conv, 'edit_media');
						break;
	                case '❌ Удалить':
	                    $response = $this->sendConvStage($conv, 'edit_confirm_del');
	                    break;
	            }
	            
	            
	            break;
	        case 'edit_position': // Ожидается ввод места в списке
	            if($message_type != 'text') return Request::emptyResponse();
	            $message_text = $message->getText();
				
				if($message_text == 'Сделать первым') $pos = 0;
				elseif($message_text == 'Сделать последним') $pos = 99;
				else{
					if(!preg_match("/^[0-9]+$/u", $message_text)) return Request::emptyResponse();
					$pos = $message_text;
				}
				
				$pdo->query("update my_page set position={$pos} where id=".$page['id']);
				// Обновляем позиции всех товаров в разделе
				$pdo->query("SET @pos = 0;");
				$order_mod = ($page['position'] < $pos) ? 'asc' : 'desc';
				$pdo->query("update my_page set position = @pos:= @pos + 1 where parent_id={$page['parent_id']} order by position asc, (id={$page['id']}) {$order_mod}");
				
				$position = $pdo->query("select position from my_page where id=".$page['id'])->fetchColumn();
				Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Текущее место в списке: '.$position]);
				$response = $this->sendConvStage($conv, 'edit_start');
				break;
	        case 'edit_message': // Ожидается ввод/редактирование текста для страницы товара
	            if($message_type != 'text') return Request::emptyResponse();
	            $message_text = $message->getText();			
				
				$sth = $pdo->prepare("update my_message set contents=:contents where my_page_id={$page['id']} and type='text' limit 1");
				$sth->execute([':contents' => $message_text]);
				
				if($update_type == 'edited_message') return Request::emptyResponse();
				return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Текст обновлен']);
				break;
	        case 'edit_name': // Ожидается ввод названия товара
	            if($message_type != 'text') return Request::emptyResponse();
	            $message_text = $message->getText();
				
				$sth = $pdo->prepare("update my_page set name=:name where id=".$page['id']);
				$sth->execute([':name' => $message_text]);
				$page['name'] = $message_text;
				$response = $this->sendConvStage($conv, 'edit_start', $page);
				break;	
	        case 'edit_thumb': // Ожидается эскиз
				if($message_type != 'photo') return Request::emptyResponse();
			
				$doc = end($message->getPhoto());
				$file_id = $doc->getFileId();
				$file    = Request::getFile(['file_id' => $file_id]);
				if (!$file->isOk() || !Request::downloadFile($file->getResult())) return Request::sendMessage(['chat_id' => $chat_id, 'text' => 'Не удалось сохранить фото']);
								    
				$thumb_folder = $this->command->getTelegram()->getDownloadPath();
				$source_name = $file->getResult()->getFilePath();
				$thumb = self::createThumb($thumb_folder, $source_name);
				
				$sth = $pdo->prepare("update my_page set thumb=:thumb where id=".$page['id']);
				$sth->execute([':thumb' => $thumb]);				

				// Удаляем старый эскиз, если он не используется
				$old_thumb = $thumb_folder.'/'.$page['thumb'];
				$count = $pdo->query("select count(*) from my_page where thumb='{$page['thumb']}'")->fetchColumn();
				if($count == 0 && file_exists($old_thumb)) unlink($old_thumb);
				
				// Выводим новый эскиз для предпросмотра
				$download_url = $this->config['base_url'].$this->config['download_path'];
				Request::sendPhoto([
					'chat_id' => $chat_id,
					'photo' => $download_url.$thumb,
					'caption' => 'Эскиз обновлен'
				]);
				
				$response = $this->sendConvStage($conv, 'edit_start');
				break;
	        case 'edit_descr': // Ожидается ввод описания
	            if($message_type != 'text') return Request::emptyResponse();
	            $message_text = $message->getText();
	            
				$sth = $pdo->prepare("update my_page set descr=:descr where id=".$page['id']);
				$sth->execute([':descr' => $message_text]);
				$page['descr'] = $message_text;
				$response = $this->sendConvStage($conv, 'edit_start', $page);	            
	            break;
	        case 'edit_confirm_del': // Ожидается подтверждение удаления страницы
	            if($message_type == 'text' && $message->getText() == '❗️ Да'){
	                $this->deletePage($conv->notes['id']);
	                $conv->stop();
	                return Request::sendMessage([
	                    'chat_id' => $chat_id,
	                    'text' => 'Страница удалена',
	                    'reply_markup' => $this->getDefaultKeyboard()
	                ]);
	            }	            
	            break;
	        case 'edit_price': // Ожидается ввод стоимости
	            if($message_type != 'text' || !preg_match("/^[0-9]+$/u", $price = $message->getText())) return Request::emptyResponse();
	            $pdo->query("update my_page set price={$price} where id=".$page['id']);
				$page['price'] = $price;
	            $response = $this->sendConvStage($conv, 'edit_start', $page);
	            break;
			case 'edit_media': // Ожидается Назад для выхода со страницы удаления фото/видео
				if($message_type == 'text' && $message->getText() == 'Нaзад'){
					foreach($conv->notes['media'] as $message_id) Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $message_id]);
					$response = $this->sendConvStage($conv, 'edit_start');
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
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		$reply_markup = Keyboard::remove();
		$resize_keyboard = true;
		
		if(isset($conv->notes['stage_rewrite'])){
			$stage = $conv->notes['stage_rewrite'];
			unset($conv->notes['stage_rewrite']);
		}
		
		switch($stage){
		    // Стадии диалога оформления заказа
			case 'address':
				$text = 'Куда доставить ваш заказ? Не забудьте указать подъезд и этаж';				
				break;
			case 'address_confirm':
				$text = "Доставить заказ по этому адресу?\n\n".$conv->notes['contact']['address'];
				$reply_markup = new Keyboard(['Да', 'Нет']);	
				break;
			case 'request_contact':
				$text = 'Привет, '.$args['first_name'].'! Мы еще не знакомы. Нажми на кнопку внизу экрана, чтобы отправить мне свой контакт';
				$reply_markup = new Keyboard([
					['text' => 'Отправить контакт', 'request_contact' => true]
				]);
				break;
			case 'contact_confirm':
				$text = "Отправьте контакт получателя заказа, если это не вы";
				if(isset($args['first_name'])) $text = 'С возвращением, '.$args['first_name']."!\n\n".$text;
				$reply_markup = new Keyboard(['Я - получатель заказа']);
				break;
			case 'time':
				$text = 'В какой день и в какое время вам удобно получить заказ?';
				break;
			case 'fop':
				$reply_markup = new Keyboard(['Наличными', 'Сбербанк онлайн']);
				$text = 'Выберите форму оплаты';				
				break;
			case 'special':
				$reply_markup = new Keyboard(['Нет особых пожеланий']);
				$text = 'Особые пожелания к заказу (если есть)';
				break;
			case 'summary':
				//$contact = DB::getPdo()->query("select * from my_contact where user_id={$user_id}")->fetch(PDO::FETCH_ASSOC);
				$text = $this->formatOrderSummary($conv);
				
				$reply_markup = new Keyboard(
					['Отправить заказ'], 
					['Изменить получателя'], ['Изменить адрес доставки'], ['Изменить время доставки'], ['Изменить форму оплаты'], ['Изменить особые пожелания'], 
					['Отменить оформление']
				);
				$resize_keyboard = false;		
				break;
				
			// Стадии диалога редактирования	
			case 'edit_start':
				if(isset($args['name'], $args['price'], $args['descr'])) Request::sendMessage([
					'chat_id' => $chat_id,
					'text' => "*{$args['name']}, {$args['price']}₽*\n\n".$args['descr'],
					'parse_mode' => 'markdown'
				]);			
			    $text = 'Отправьте фотографии и видео, чтобы добавить их к товару, или выберите, что нужно изменить.';
			    $reply_markup = $this->getEditKeyboard();
			    $resize_keyboard = false;
			    break;
			case 'edit_position':
			    $text = 'Текущее место в списке: '.$args['position']."\n\nНаберите новое место в списке (цифрой)";
			    $reply_markup = new Keyboard(['Сделать первым'], ['Сделать последним'], ['Назад']);
			    break;
			case 'edit_price':
			    $text = "Наберите новую цену (цифрой)";
			    $reply_markup = new Keyboard(['Назад']);
			    break;
			case 'edit_message':
			    $text = 'Это текущий текст страницы. Наберите новый текст, при необходимости отредактируйте';
			    $reply_markup = new Keyboard(['Назад']);
			    break;
			case 'edit_name':
			    $text = '*'.$args['name']."*\n\nНаберите новое название (включая вес)";
			    $reply_markup = new Keyboard(['Назад']);
			    break;
			case 'edit_thumb':
			    $text = 'Отправьте новое фото для эскиза (фото будет уменьшено до квадрата 156x156)';
			    $reply_markup = new Keyboard(['Назад']);
			    break;
			case 'edit_descr':
			    $text = "Наберите новое описание";
			    $reply_markup = new Keyboard(['Назад']);				
			    break;
			case 'edit_media':
				$text = "Отменить удаление будет нельзя";
				$reply_markup = new Keyboard(['Нaзад']);
				break;				
			case 'edit_confirm_del':
			    $text = 'Удалить страницу? Это действие нельзя будет отменить';
			    $reply_markup = new Keyboard(['❗️ Да'], ['Назад']);
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
	
	/**
	 * Форматирование итоговой информации о заказе
	 * 
	 * @param \Longman\TelegramBot\Conversation $conv
	 * @param boolean $toAdmin Дополнительная информация для администратора
	 * @return string Текст сообщения
	 */
	public function formatOrderSummary($conv, $toAdmin = false){
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		$cart = $this->fetchCartContents($user_id);
		$sum = 0;
		
		$oldSumDeleted = false;		
		$text = ($toAdmin) ? '' : "Ваш заказ: \n\n";
		foreach($cart as $product){
			$text .= "{$product['quanity']}x {$product['name']}, {$product['price']}₽\n";
			$sum += $product['price'] * $product['quanity'];					
			
			if($toAdmin){
				if(!$oldSumDeleted && $product['sum_message_id']){
					$oldSumDeleted = true;
					Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['sum_message_id']]);
				}
				if($product['message_id']) Request::deleteMessage(['chat_id' => $chat_id, 'message_id' => $product['message_id']]);				
			}
		}
		
		$text .= "*Сумма: {$sum}₽*\n\n";
		if($toAdmin){
			$contact = DB::getPdo()->query("select * from my_contact where user_id={$user_id}")->fetch(PDO::FETCH_ASSOC);
			$text .= "*Заказчик*: ".trim($contact['first_name'].' '.$contact['last_name']).", ".$contact['phone_number']."\n";
		}
		$text .= "*Получатель*: ".trim($conv->notes['contact']['first_name'].' '.$conv->notes['contact']['last_name']).", ".$conv->notes['contact']['phone_number']."\n";
		$text .= "*Адрес доставки*: {$conv->notes['contact']['address']}\n";
		$text .= "*Время доставки*: {$conv->notes['time']}\n";
		$text .= "*Форма оплаты*: {$conv->notes['fop']}\n";
		$text .= "*Особые пожелания*: {$conv->notes['special']}";

		return $text;
	}	
	
	/**
	 * Завершение оформления заказа
	 * 
	 * @param \Longman\TelegramBot\Conversation $conv
	 * @return \Longman\TelegramBot\Entities\ServerResponse
	 */
	public function orderCreateFinish($conv){
		$pdo = DB::getPdo();
		$chat_id = $this->chat_id;
		$user_id = $this->user_id;
		
		// Отправляем сообщение заказчику
		$contact = $pdo->query("select * from my_contact where user_id={$user_id}")->fetch(PDO::FETCH_ASSOC);
		$text = $contact['first_name'].', мы очень рады, что ты доверяешь нам свои сырные заказы!) Курьер привезет заказ, а если заблудится - позвонит по контактному номеру.'."\n\n";
		$text .= 'Спасибо! Если возникнут вопросы, звони нам: +79220001131';		
		Request::sendMessage(['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $this->getDefaultKeyboard()]);
		
		$text = $this->formatOrderSummary($conv, true);
		// Очищаем корзину и завершаем диалог
		$pdo->query("delete from my_cart where user_id={$user_id}");						
		$conv->stop();
		
		// Отправляем сообщение в группу администраторов
		return Request::sendMessage(['chat_id' => $this->config['admin_group'], 'text' => $text, 'parse_mode' => 'markdown']);
	}

	/**
	 * Получение клавиатуры по умолчанию
	 * 
	 * @return \Longman\TelegramBot\Entities\Keyboard
	 */
	public function getDefaultKeyboard(){
		$pdo = DB::getPdo();
		$isAdmin = $this->isAdmin;
		
		$rows = [];
		// Клавиатура по умолчанию закреплена за текстовым сообщением стартовой страницы
		$buttons = $pdo->query("
            select my_button.* from my_button 
            left join my_message on my_button.my_message_id = my_message.id
            left join my_page on my_message.my_page_id = my_page.id
            where my_message.type='text' and my_page.type='start' order by row asc, position asc
        ")->fetchAll();
		foreach($buttons as $button){
			$rows[ $button['row'] ][ $button['position'] ] = $button['text'];
		}
		
		$keyboard = new Keyboard(...$rows);
		$keyboard->setResizeKeyboard(true);
		return $keyboard;
	}
	
	/**
	 * Получение клавиатуры для редактирования товара
	 * 
	 * @return \Longman\TelegramBot\Entities\Keyboard
	 */
	public function getEditKeyboard(){
	    $keyboard = new Keyboard(
	        ['Место в списке', 'Цена'],	        
	        ['Текст', 'Название'],        
	        ['Эскиз', 'Описание'],
	        ['Удалить фото', '❌ Удалить'],
			['Выход']
	    );
	    return $keyboard->setResizeKeyboard(true);	    
	}
	
	/**
	 * Экранирование разметки текста
	 * 
	 * @param string $str
	 * @return string
	 */
	public function escapeMarkdown($str){
		return str_replace([
			'*', '_', '[', ']', '`' 
		], [
			'\*', '\_', '\[', '\]', '\`'
		], (string)$str);
	}
	
	/**
	 * Создание из картинки квадратного эскиза 156x156
	 * 
	 * @param string $source_name Имя файла исходной картинки
	 * @param string $thumb_folder Путь к папке для хранения картинок
	 * @return string Имя файла эскиза
	 */
	public static function createThumb($download_folder, $source_name){
	    $path = $download_folder . '/' . $source_name;
		$img = imagecreatefromjpeg($path);
		$width = imagesx($img);
		$height = imagesy($img);
		$side = 156;		

		if($width > $height){
			$newx = ($width-$height)/2;
			$width = $height;
			$cropped = imagecrop($img, ['x' => $newx, 'y' => 0, 'width' => $width, 'height' => $height]);			
		}
		elseif($height > $width){
			$newy = ($height-$width)/2;
			$height = $width;
			$cropped = imagecrop($img, ['x' => 0, 'y' => $newy, 'width' => $width, 'height' => $height]);			
		}
		else{
			$cropped = $img;
		}
		
		$result = imagecreatetruecolor($side, $side);
		imagecopyresampled($result, $cropped, 0, 0, 0, 0, $side, $side, $width, $height);		
		
		ob_start();
		imagejpeg($result, null, 90);
		$thumb_contents = ob_get_contents();
		ob_end_clean();
		imagedestroy($result);
		
		$thumb_name = md5($thumb_contents);
		file_put_contents($download_folder.'/'.$thumb_name, $thumb_contents);
		unlink($path);
		
		return $thumb_name;
	}
	
}
