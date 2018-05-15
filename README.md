# ecofood_tmn_bot
Telegram бот [@ecofood_tmn_bot](http://t.me/ecofood_tmn_bot) на основе [php-telegram-bot](https://github.com/php-telegram-bot/example-bot)

**Структура проекта**

- `Commands/*Command.php` (Вызываются при получении ботом соответствующих команд, включая обычные сообщения, Inline запросы и т.д.)
- `src/Tbot.php` (Основная логика бота)
- `collage.php` (Используется в каталоге товаров для выдачи эскизов разделов)
- `composer.json` (Описание и зависимости проекта для Composer)
- `hook.php` (Используется для получения webhook запросов от Telegram)
- `set.php` (Используется для регистрации webhook в Telegram)
- `stucture.sql` (Структура дополнительных таблиц БД, помимо [используемых в php-telegram-bot](https://github.com/php-telegram-bot/core/blob/master/structure.sql))
