<?php

namespace cri2net\Sms\LetsAds;

use \Exception;
use cri2net\sms_client\AbstractSMS;
use cri2net\php_pdo_db\PDO_DB;

class LetsAds extends AbstractSMS
{
    /**
     * Ссылка на API
     * @since 1.0.0
     */
    const API_URL = 'https://letsads.com/api';

    /**
     * Альфаимя отправителя sms, доступное по умолчанию всем клиентам
     * @var string
     * @since 1.0.1
     */
    public $alfaname = 'Test';

    /**
     * Конструктор
     * @param string $login    Логин для доступа к API
     * @param string $password Пароль для доступа к API
     */
    public function __construct($login = null, $password = null)
    {
        if ($login != null) {
            $this->login = $login;
        }
        if ($password != null) {
            $this->password = $password;
        }
    }

    /**
     * Возвращает ключ текущего шлюза для хранения в БД привязки sms к шлюзу
     * @return string Ключ текущего шлюза
     */
    public function getProcessingKey()
    {
        return 'letsads';
    }

    /**
     * Возвращает ссылку для обращений к API
     * @return string ссылка для работы с API
     */
    public function getApiUrl()
    {
        return self::API_URL;
    }

    /**
     * метод для проверки остатка на балансе в аккаунте на sms-fly
     * @return double кол-во гривен
     */
    public function getBalance()
    {
        $response = $this->sendPOST('<balance />');
        return floatval($response->balance . '');
    }

    /**
     * Отправка запроса на API
     * @param  string $data     Строка с XML телом основной части передаваего запроса к API
     * @return SimpleXML object ответ от API
     */
    protected function sendPOST($data = '')
    {
        if (!extension_loaded('curl')) {
            throw new Exception('cURL extension missing');
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?><request>'
              . '<auth>'
              .     '<login>'    . $this->login    . '</login>'
              .     '<password>' . $this->password . '</password>'
              . '</auth>'
              . $data . '</request>';

        $ch = curl_init($this->getApiUrl());
        $options = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST           => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: text/xml",
                "Accept: text/xml",
            ],
        ];

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception($error);
        }
        curl_close($ch);

        $response = @simplexml_load_string($response);
        if (($response === false) || ($response === null)) {
            throw new Exception('String could not be parsed as XML');
        }

        $this->processResponse($response);
        return $response;
    }

    /**
     * Обработка ответа на ошибки
     * @param  SimpleXML $response ответ от API
     * @return void
     */
    public function processResponse($response)
    {
        if ($response->name . '' == 'Error') {
            throw new Exception(self::getErrorText($response->description));
        }
    }

    /**
     * Проверка статуса sms
     * @param  integer $campaignID ID кампании рассылки в системе sms-fly
     * @param  string  $recipient  номер получателя
     * @return string              Текущий статус
     */
    public function checkStatus($campaignID, $recipient)
    {
        $response = $this->sendPOST('<sms_id>'. $campaignID .'</sms_id>');
        return $response->description . '';
    }
    
    /**
     * Реализация отправки SMS
     * @param  string $to          телефон получателя в международном формате
     * @param  string $text        текст сообщения
     * @return array               Детали об отправке
     */
    public function sendSMS($to, $text)
    {
        $to = $this->processPhone($to);
        $text = htmlspecialchars($text, ENT_QUOTES);
        
        $data = '<message>';
        $data .= "<from>" . $this->alfaname . "</from>";
        $data .= "<text>" . $text . "</text>";
        $data .= "<recipient>" . $to . "</recipient>";
        $data .= "</message>";

        $response = $this->sendPOST($data);
        
        return [
            'campaignID' => $response->sms_id . '',
            'status'     => 'MESSAGE_IN_QUEUE',
        ];
    }

    /**
     * sms-fly работает с номерами без символа + в начале номера
     * 
     * @param  string $international_phone Номер телефона в международном формате
     * @return string                      Преобразованный номер
     */
    public function processPhone($international_phone)
    {
        return str_replace('+', '', $international_phone);
    }

    /**
     * Метод проверяет статусы всех сообщений, которые находятся в незавершённом состоянии.
     * Предназначен для вызова из крона
     * @return void
     */
    public function checkStatusByCron()
    {
        if (empty($this->table)) {
            throw new Exception("Поле table не задано");
        }

        $stm = PDO_DB::prepare("SELECT * FROM {$this->table} WHERE processing=? AND status IN ('complete') AND (processing_status IS NULL OR processing_status IN ('MESSAGE_IN_QUEUE'))");
        $stm->execute([$this->getProcessingKey()]);

        while ($item = $stm->fetch()) {

            $update = [];

            try {
                $processing_data = json_decode($item['processing_data']);
                $status = $this->checkStatus($processing_data->first->campaignID, $item['to']);
                // Описание статуса можно получить через self::getStateText($status);

                $update['updated_at'] = microtime(true);
                $update['processing_status'] = $status;

            } catch (Exception $e) {
            }

            PDO_DB::update($update, $this->table, $item['id']);
        }
    }
    
    /**
     * Метод отдаёт текстовое описание кода ошибки от шлюза
     * @param  string $code код ошибки
     * @return string       Описание ошибки
     */
    public static function getErrorText($code)
    {
        switch ($code) {

            case 'API_DISABLED':       return 'для учетной записи пользователя запрещена работа с API';
            case 'AUTH_DATA':          return 'ошибка авторизации: несуществующий пользователь, неправильная пара логин-пароль';
            case 'INCORRECT_FROM':     return 'некорректное имя отправителя';
            case 'INVALID_FROM':       return 'несуществующее имя отправителя для данной учетной записи';
            case 'MAX_MESSAGES_COUNT': return 'превышено максимальное количество респондентов в одном запросе';
            case 'MESSAGE_NOT_EXIST':  return 'сообщение с заданным id не существует';
            case 'MESSAGE_TOO_LONG':   return 'превышена максимальная длина сообщения: 201 для кириллицы, 459 для латиницы';
            case 'NO_DATA':            return 'ошибка данных: не передан XML';
            case 'NO_MESSAGE':         return 'пустое сообщение для оправки';
            case 'NOT_ENOUGH_MONEY':   return 'недостаточно средств для отправки сообщения респондентам в запросе';
            case 'REQUEST_FORMAT':     return 'неправильный тип запроса';
            case 'UNKNOWN_ERROR':      return 'неизвестная ошибка';
            case 'USER_NOT_MODERATED': return 'запрещена отправка сообщений без проверки для учетной записи пользователя';
            case 'WRONG_DATA_FORMAT':  return 'ошибка формата переданного XML';

            default:                   return 'Неизвестный код ошибки ' . $code;
        }
    }
    
    /**
     * Метод отдаёт текстовое описание кода состояния сообщения
     * @param  string $code Статус от шлюза
     * @return string       Описание статуса
     */
    public static function getStateText($code)
    {
        switch ($code) {

            case 'MESSAGE_IS_DELIVERED':  return 'сообщение доставлено респонденту';
            case 'MESSAGE_IS_SENT':       return 'сообщение отправлено';
            case 'MESSAGE_NOT_DELIVERED': return 'сообщение не доставлено';
            case 'MESSAGE_IN_QUEUE':      return 'сообщение поставлено в очередь на отправку';

            default:                      return 'Неизвестный код состояния ' . $code;
        }
    }
}
