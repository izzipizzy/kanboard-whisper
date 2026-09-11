<?php
namespace Kanboard\Plugin\Whisper\Service;

class Http
{
    public function request($url, $payload = null, array $headers = [], $timeout = 60, $maxBytes = 20971520)
    {
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        if ($payload !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $payload); }
        $ok = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        // Never include URLs, tokens or provider response bodies in exceptions/logs.
        if ($ok === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException(I18n::text('Service unavailable (HTTP ').$code.I18n::text('). Try again later.'), (int)$code);
        }
        return $body;
    }
    public function json($url, array $payload, array $headers = [], $timeout = 60)
    {
        $body = $this->request($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), array_merge(['Content-Type: application/json'], $headers), $timeout, 1048576);
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new \RuntimeException(I18n::text('Invalid service response.')); }
        return $result;
    }
}
