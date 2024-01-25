<?php

namespace TypechoPlugin\LskyProPlus;

use CURLFile;

class LskyproClient
{
    private string $baseUrl;
    private string $apikey;
    private int $strategyId = -1; // default: -1

    function __construct(string $baseUrl, string $apikey, string $strategyId)
    {
        if (str_ends_with($baseUrl, "/")) {
            $this->baseUrl = $baseUrl;
        } else {
            $this->baseUrl = $baseUrl . "/";
        }
        $this->apikey = $apikey;

        if (is_numeric($strategyId)) {
            $this->strategyId = (int)$strategyId;
        }
    }

    public function delete(string $imageKey): bool|array
    {
        $path = 'api/v1/images/' . $imageKey;
        return $this->request($path, 'DELETE');
    }

    public function upload(string $imageFile): bool|array
    {
        $post = ['file' => new CURLFile($imageFile)];
        if ($this->strategyId >= 0) {
            $post['strategy_id'] = $this->strategyId;
        }
        return $this->request('api/v1/upload', 'POST', postFields: $post);
    }

    /**
     * Request the lskypro api with given path and parameters.
     *
     * @param string $path url path, without / prefix.
     * @param string $method e.g. POST, DELETE.
     * @param array|null $headers string to string array. e.g. ['Accept' => 'application/json'].
     * @param array|null $postFields
     * @return bool|string
     */
    private function request(string $path, string $method, array $headers = null, array|null $postFields = null): bool|array
    {
        $defaultHeaders = [
            'Authorization' => $this->apikey,
            'Accept' => 'application/json',
        ];
        if ($headers) {
            $defaultHeaders = array_merge($defaultHeaders, $headers);
        }

        $headersArray = array_map(function ($value, $key) {
            return $key . ": " . $value;
        }, $defaultHeaders, array_keys($defaultHeaders));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headersArray);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($postFields) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
        $res = curl_exec($ch);
        curl_close($ch);

        if (!$res) {
            return false;
        }
        $json = json_decode($res, true);
        if ($json == null) {
            return false;
        }
        return $json;
    }
}