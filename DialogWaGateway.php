<?php

class DialogWaGateway
{
    private $apiUrl;
    private $session;
    private $token;

    public function __construct($apiUrl, $session, $token)
    {
        $this->apiUrl = $apiUrl;
        $this->session = $session;
        $this->token = $token;
    }

    public function checkGateway()
    {
        $response = $this->_hitApi([
            'endpoint' => $this->apiUrl . '/session/' . $this->session,
            'type'     => 'GET',
            'data'     => null,
            'token'    => $this->token,
        ]);

        if (!$response['status']) {
            return false;
        }

        $result = json_decode($response['response'], true);
        if (!is_array($result)) {
            return false;
        }

        $is_expired = isset($result['is_expired']) ? $result['is_expired'] : true;
        $is_out_of_limit = isset($result['is_out_of_limit']) ? $result['is_out_of_limit'] : true;
        $status = isset($result['status']) ? $result['status'] : false;

        return !$is_expired && !$is_out_of_limit && $status;
    }

    public function sendWa($target, $text, $filePath = null, $timeout = 30)
    {
        $data = [
            'session' => $this->session,
            'target'  => $target,
            'message' => $text,
        ];

        $hasFile = $filePath && is_file($filePath);
        if ($hasFile) {
            $absolutePath = realpath($filePath) ?: $filePath;
            $mimeType = $this->_detectMimeType($absolutePath);

            $data['file'] = class_exists('CURLFile')
                ? new CURLFile($absolutePath, $mimeType, basename($absolutePath))
                : '@' . $absolutePath . ';type=' . $mimeType . ';filename=' . basename($absolutePath);
        }

        $apiResponse = $this->_hitApi([
            'endpoint' => $this->apiUrl . ($hasFile ? '/send-media' : '/send-text'),
            'type'     => 'POST',
            'data'     => $data,
            'token'    => $this->token,
            'timeout'  => $timeout,
        ]);

        if ($apiResponse['status'] === false) {
            $message = isset($apiResponse['response']) ? $apiResponse['response'] : (isset($apiResponse['message']) ? $apiResponse['message'] : 'Terjadi Kesalahan!');
            return ['status' => 'failed', 'sent_response' => $message];
        }

        $decoded = json_decode($apiResponse['response'], true);
        if (!is_array($decoded) || !isset($decoded['data'])) {
            return is_array($decoded)
                ? $decoded
                : ['status' => 'failed', 'sent_response' => 'Invalid response format'];
        }

        $send = [];
        foreach ($decoded['data'] as $item) {
            $key = ($item['status'] == 200) ? $item['target'] : $target;
            $send[$key] = $item;
        }

        $message = '';
        $wa_status = 'failed';

        if (isset($send[$target]['status'])) {
            $message = $send[$target]['message'];
            switch ($send[$target]['status']) {
                case 200:
                    $wa_status = 'completed';
                    break;
                case 422:
                    $wa_status = 'invalid_number';
                    break;
                default:
                    $wa_status = 'failed';
                    break;
            }
        } elseif (isset($send['status'])) {
            $message   = isset($send['response']) ? $send['response'] : (isset($send['message']) ? $send['message'] : '');
            $wa_status = ($send['status'] == 200) ? 'completed' : 'failed';
        }

        return [
            'status'        => $wa_status,
            'processed_at'  => date('Y-m-d H:i:s'),
            'sent_response' => $message,
        ];
    }

    private function _formatHeaders($headers)
    {
        $result = [];
        foreach ($headers as $k => $v) {
            $result[] = "$k: $v";
        }
        return $result;
    }

    private function _detectMimeType($filePath)
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            return $mime;
        }

        if (function_exists('mime_content_type')) {
            return mime_content_type($filePath);
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $map = [
            'pdf'  => 'application/pdf',
            'txt'  => 'text/plain',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'zip'  => 'application/zip',
            'gif'  => 'image/gif',
        ];
        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    private function _hitApi($options = [])
    {
        $endpoint = isset($options['endpoint']) ? $options['endpoint'] : null;
        $type     = isset($options['type']) ? $options['type'] : 'GET';
        $data     = isset($options['data']) ? $options['data'] : null;
        $token    = isset($options['token']) ? $options['token'] : null;
        $headers  = isset($options['headers']) ? $options['headers'] : [];
        $timeout  = isset($options['timeout']) ? $options['timeout'] : 30;

        $headers = is_array($headers) ? $headers : [];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $this->_formatHeaders($headers),
        ]);

        $upperType = strtoupper($type);
        if ($upperType !== 'GET' && $upperType !== 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperType);
        }

        if (in_array($upperType, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($upperType === 'GET' && is_array($data) && !empty($data)) {
            $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $endpoint);
        }

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        return [
            'status'   => !$error,
            'code'     => $http_code,
            'response' => $response ?: $error,
        ];
    }
}