<?php
declare(strict_types=1);

namespace Tests\e2e;

/** Minimal browser: keeps cookies and the CSRF token, follows redirects. */
final class Client
{
    private \CurlHandle $curl;
    private string $csrf = '';
    public int $status = 0;
    public string $path = '';
    public string $body = '';

    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 20]);
    }

    public function get(string $path): self
    {
        curl_setopt_array($this->curl, [CURLOPT_HTTPGET => true, CURLOPT_URL => $this->base . $path]);
        return $this->send();
    }

    public function post(string $path, array $data = [], bool $withToken = true): self
    {
        curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_URL => $this->base . $path, CURLOPT_POSTFIELDS => http_build_query($data + ($withToken ? ['_csrf' => $this->csrf] : []))]);
        return $this->send();
    }

    private function send(): self
    {
        $this->body = (string) curl_exec($this->curl);
        $this->status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $this->path = (string) parse_url(curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL), PHP_URL_PATH);
        if (preg_match('/name="_csrf" value="(\w+)"/', $this->body, $m)) {
            $this->csrf = $m[1];
        }
        return $this;
    }

    public function sees(string $text): bool
    {
        return str_contains(html_entity_decode($this->body, ENT_QUOTES), $text);
    }
}
