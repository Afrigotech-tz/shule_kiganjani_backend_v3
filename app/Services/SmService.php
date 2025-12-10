<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Log;

class SmService
{
    protected $client;
    protected $apiUrl;
    protected $apiKey;
    protected $senderId;



    public function __construct()
    {
        $this->client = new Client();
        $this->apiUrl = config('services.sms.api_url');  // URL from config
        $this->apiKey = config('services.sms.api_key');  // API key from config
        $this->senderId = config('services.sms.sender_id');  // Sender ID from config
    }

    /**
     * Sends SMS to a given phone number.
     *
     * @param string $phoneNumber
     * @param string $message
     * @return array
     *
     */



    public function sendSms($phoneNumber, $message)
    {
        $data = [
            'from' => $this->senderId,
            'to' => $phoneNumber,
            'text' => $message,
            'reference' => 'sms-ref-'.uniqid(),  // Unique reference for each SMS
        ];

        $headers = [
            'Authorization' => 'Basic ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $response = $this->client->post($this->apiUrl, [
                'headers' => $headers,
                'json' => $data,
                'verify' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                return ['success' => true, 'response' => $body];
            } else {
                return ['success' => false, 'response' => $body, 'http_code' => $statusCode];
            }
        } catch (RequestException $e) {
            $error = $e->getMessage();
            if ($e->hasResponse()) {
                $error = $e->getResponse()->getBody()->getContents();
            }


            return ['success' => false, 'error' => $error];
        }

        


    }


}


