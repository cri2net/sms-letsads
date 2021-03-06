# README

Эта библиотека зависит от пакета [cri2net/sms-client](https://packagist.org/packages/cri2net/sms-client) и предназначена для отправки sms через шлюз letsads.com
Текущее описание расширяет описание cri2net/sms-client

# Установка
```
composer require cri2net/sms-letsads
```

# Использование
## Описание методов
Тут приведены лишь методы, использование которых отличается от описания в пакете [cri2net/sms-client](https://packagist.org/packages/cri2net/sms-client) (либо не описанные ранее)

- **getStateText($code)** — статический метод, который отдаёт текстовое описание статуса от шлюза ("расшифровывает" кодировки статуса у шлюза)
- **sendSmsByCron()** — отправка подготовленных sms, которые сохранены в БД

## Примеры кода

### обычная оправка
``` php
<?php

use cri2net\Sms\LetsAds\LetsAds;

$sms = new LetsAds($login, $password);
$sms->alfaname = 'MyName'; // по умолчанию InfoCentr или Test или

$data = $sms->sendSMS('+380480000000', 'Hello!');
var_dump($data); // array('campaignID' => 1111, 'status' => 'MESSAGE_IN_QUEUE')
```

### Оправка из БД
Для отправки из БД нужно создать в БД таблицу, как описано в пакете [cri2net/sms-client](https://packagist.org/packages/cri2net/sms-client)

Также, нужно инициализировать соединение с базой через библиотеку [cri2net/php-pdo-db](https://packagist.org/packages/cri2net/php-pdo-db)

``` php
<?php

// сохранение sms в БД для отправки
$arr = [
    'to'               => '+380480000000',
    'created_at'       => microtime(true),
    'updated_at'       => microtime(true),
    'min_sending_time' => microtime(true), // отправка прямо сейчас, но можно указать время в будущем для отложенной отправки
    'replace_data'     => json_encode([
        'username'     => 'Джон', // массив с правилами замен
    ]),
    'raw_text'         => 'Hello, {{username}}!', // переменные в тексте следует обрамлять в двойные фигурный кавычки
];
$insert_sms_id = \cri2net\php_pdo_db\PDO_DB::insert($arr, 'sms_table_name');

// непосредственно отправка, предположительно в кроне
$sms = new \cri2net\Sms\LetsAds\LetsAds($login, $password);
$sms->table = 'sms_table_name'; // нужно создать таблицу в БД

$sms->sendSmsByCron();
$sms->checkStatusByCron();
```

При сохранении в БД можно использовать поле additional. Оно предназначено исключительно для пользователя. В него, например, можно сохранить в JSON привязку к другой сущности или любую другую информацию

Поле processing по умолчанию NULL. В этом случае sms попробует отправить любой доступный шлюз, а после отправки заполнит это поле своим ключём. Но можно указать это поле при вставке в БД, и тогда sms сможет отправить только один конкретный шлюз.
``` php
<?php

$arr = [
    // ...
    'processing' => $sms->getProcessingKey(),
    'additional' => json_encode([
        'payment_id' => '10'
    ]),
    // ...
];
```
