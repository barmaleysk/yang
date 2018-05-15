--
-- Структура таблицы `my_button`
--

CREATE TABLE IF NOT EXISTS `my_button` (
  `id` int(11) UNSIGNED AUTO_INCREMENT COMMENT 'Уникальный ID кнопки',
  `my_message_id` int(11) NOT NULL COMMENT 'ID сообщения, за которым закреплена клавиатура',
  `type` ENUM('custom', 'inline') NOT NULL COMMENT 'Тип клавиатуры, на которой расположена кнопка',
  `text` varchar(100) NOT NULL COMMENT 'Текст кнопки',
  `row` tinyint(1) NOT NULL COMMENT 'Строка, в которой расположена кнопка на клавиатуре',
  `position` tinyint(1) NOT NULL COMMENT 'Позиция кнопки в строке'
  
  PRIMARY KEY (`id`), 
  KEY `my_message_id` (`my_message_id`),
  
  FOREIGN KEY (`my_message_id`) REFERENCES `my_message` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Структура таблицы `my_cart`
--

CREATE TABLE IF NOT EXISTS `my_cart` (
  `id` int(11) UNSIGNED AUTO_INCREMENT COMMENT 'Уникальный ID товара в корзине',
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя Telegram, который добавил товар',
  `my_page_id` int(11) NOT NULL COMMENT 'ID страницы товара',
  `quanity` tinyint(4) NOT NULL COMMENT 'Количество товара в корзине',
  `message_id` int(11) NOT NULL COMMENT 'ID исходящего сообщения Telegram об этом товаре в корзине',
  `sum_message_id` int(11) NOT NULL COMMENT 'ID исходящего сообщения Telegram о сумме товаров в корзине'
  
  PRIMARY KEY (`id`), 
  UNIQUE KEY `user_id` (`user_id`,`my_page_id`),
  
  FOREIGN KEY (`my_page_id`) REFERENCES `my_page` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Структура таблицы `my_contact`
--

CREATE TABLE IF NOT EXISTS `my_contact` (
  `id` int(11) UNSIGNED AUTO_INCREMENT COMMENT 'Уникальный ID контакта',
  `phone_number` varchar(20) NOT NULL COMMENT 'Номер телефона',
  `first_name` varchar(50) NOT NULL COMMENT 'Имя',
  `last_name` varchar(50) NOT NULL COMMENT 'Фамилия',
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя Telegram',
  `address` text NOT NULL COMMENT 'Адрес доставки'
  
  PRIMARY KEY (`id`), 
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Структура таблицы `my_message`
--

CREATE TABLE IF NOT EXISTS `my_message` (
  `id` int(11) UNSIGNED AUTO_INCREMENT COMMENT 'Уникальный ID сообщения',
  `my_page_id` int(11) NOT NULL COMMENT 'ID страницы, которой принадлежит сообщение',
  `type` ENUM('text', 'photo', 'video', 'media_group') NOT NULL COMMENT 'Тип сообщения',
  `contents` text NOT NULL COMMENT 'Содержимое сообщения (текст/json/Telegram file_id)',
  `position` tinyint(1) NOT NULL COMMENT 'Позиция сообщения на странице'
  
  PRIMARY KEY (`id`), 
  KEY `my_page_id` (`my_page_id`),
  
  FOREIGN KEY (`my_page_id`) REFERENCES `my_page` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Структура таблицы `my_page`
--

CREATE TABLE IF NOT EXISTS `my_page` (
  `id` int(11) UNSIGNED AUTO_INCREMENT COMMENT 'Уникальный ID страницы',
  `parent_id` int(11) NOT NULL COMMENT 'ID родительской страницы',
  `type` ENUM('start', 'catalog', 'section', 'product', 'cart', 'generic') NOT NULL COMMENT 'Тип страницы',
  `thumb` varchar(50) NOT NULL COMMENT 'Имя файла эскиза страницы',
  `name` varchar(100) NOT NULL COMMENT 'Название страницы',
  `descr` varchar(200) NOT NULL COMMENT 'Описание (для товаров)',
  `price` int(11) NOT NULL COMMENT 'Стоимость (для товаров)',
  `position` tinyint(2) NOT NULL COMMENT 'Позиция среди страниц с тем же родителем',
  `hidden` tinyint(1) NOT NULL COMMENT 'Признак скрытой страницы'
  
  PRIMARY KEY (`id`), 
  KEY `parent_id` (`parent_id`), 
  KEY `name` (`name`), 
  KEY `type` (`type`), 
  KEY `hidden` (`hidden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;