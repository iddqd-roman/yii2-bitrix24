<?php

namespace iddqd\yii2bitrix24;

use yii\base\Component;

class Bitrix24 extends Component{
    
    const URL_PATTERN = 'https://{subdomain}.bitrix24.ru';
    private $_token_provider;
    
    public $url;
    public $oauth_url = 'https://oath.bitrix.info/oauth/token/';
    public $subdomain;
    public $client_id;
    public $client_secret;
    public $token_provider;
    
    
    public function init(){
        parent::init();
        if($this->subdomain !== null){
            $this->setSubdomain($this->subdomain);
        }
        if(is_array($this->token_provider)){
            $this->setTokenProvider($this->token_provider);
        }
    }
    
    /**
     * Устанавливает значение $subdomain
     * @param string $subdomain
     */
    public function setSubdomain($subdomain){
        $this->subdomain = $subdomain;
        $this->url = str_replace('{subdomain}', $subdomain, self::URL_PATTERN);
    }
    
    /**
     * Загружает объект в поле $_token_provider
     * @param array $config
     */
    public function setTokenProvider($config){
        $class = $config['class'];
        unset($config['class']);
        $this->_token_provider = new $class($config);
    }
    
    /**
     * GET-запрос через CURL
     * @param string $url
     * @param array $params
     * @return array|null Ответ сервера
     */
    private function curl($url, $params){
        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($c, CURLOPT_HTTPHEADER, []);
        $result = curl_exec($c);
        $array = json_decode($result, true);
        return is_array($array) ? $array : $result;
    }
    
    /**
     * Собсна, основной метод, отправляющий запрос на создание и т.д. лидов, контактов и прочего.
     * Использование:
     * Bx24Helper::request('/crm.lead.add/', [
     * 'fields => [
     *      'TITLE' => 'Лид от Васи Пупкина',
     *      'STATUS_ID' => 'NEW',
     *      'ASSIGNED_BY_ID' => '14',
     *      'OPENED' => 'Y',
     *      'PHONE' => [['VALUE' => $this->phone, 'VALUE_TYPE' => 'WORK']]
     * ],
     * 'params' => ['REGISTER_SONET_EVENT' => 'Y'],
     * ]);
     * @param string $method
     * @param array $params
     * @return array|null Результат curl-запроса
     */
    public function request($method, $params = []){
        $token_data = $this->_token_provider->getTokenData();
        if(!is_array($token_data)){
            return false;
        }
        $url = $this->url . '/rest/' . $method . '/';
        $response = $this->curl($url, $params + ['auth' => $token_data['access_token']]);
        if(isset($response['error'])){
            $this->refreshToken();
            $token_data = $this->_token_provider->getTokenData();
            return $this->curl($url, $params + ['auth' => $token_data['access_token']]);
        }
        else{
            return $response;
        }
    }
    
    /**
     * Первый этап oauth-аутентификации.
     * Используется только на получение первого TOKEN.
     * Дальше используется REFRESH_TOKEN.
     * @return array|null Результат авторизации (по хорошему CODE)
     */
    public function auth(){
        $method = '/oauth/authorize/';
        $url = $this->url . $method;
        $params = [
            'client_id' => $this->client_id,
        ];
        return $this->curl($url, $params);
    }
    
    /**
     * Второй этап oauth-аутентификации
     * @param string $code CODE, полученный на первом этапе аутентификации
     * @return boolean Результат записи в БД json-массива, где ключевые: TOKEN и REFRESH_TOKEN
     */
    public function saveToken($code){
        $auth = $this->curl($this->oauth_url, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
        ]);
        return $this->_token_provider->saveTokenData($auth);
    }
    
    /**
     * Метод, который запускается, если TOKEN, хранящийся в БД устарел.
     * Генерирует новый TOKEN и REFRESH_TOKEN, и записывает в БД.
     * Запускается автоматически
     * @return boolean Результат записи в БД
     */
    public function refreshToken(){
        $token_data = $this->_token_provider->getTokenData();
        $auth = $this->curl($this->oauth_url, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'refresh_token' => $token_data['refresh_token'],
        ]);
        return $this->_token_provider->saveTokenData($auth);
    }
}
