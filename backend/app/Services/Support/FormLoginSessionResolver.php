<?php

namespace App\Services\Support;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FormLoginSessionResolver
{
    /**
     * Perform automated simulation of form-based login to harvest session cookies.
     *
     * @param string $baseUrl Base URL of the application (e.g. https://app.example.com)
     * @param string $username Username / Email credential
     * @param string $password Password credential
     * @param string $loginPath Relative login path (e.g. /login)
     * @return string|null Formatted cookie string or null if login failed
     */
    public function resolve(
        string $baseUrl,
        string $username,
        string $password,
        string $loginPath = '/login'
    ): ?string {
        try {
            $baseUrl = rtrim($baseUrl, '/');
            $loginPath = '/' . ltrim($loginPath, '/');
            $loginUrl = $baseUrl . $loginPath;

            $cookieJar = new CookieJar();

            // Step 1: GET the login page to capture CSRF token and initial cookies
            $response = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'timeout' => 15,
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ],
            ])->get($loginUrl);

            $html = $response->body();

            // Extract CSRF token
            $csrfToken = $this->extractCsrfToken($html);

            // Detect field names
            $userField = $this->detectUserField($html);
            $passField = $this->detectPasswordField($html);

            // Prepare POST payload
            $payload = [
                $userField => $username,
                $passField => $password,
            ];

            if ($csrfToken) {
                $payload['_token'] = $csrfToken;
            }

            // Step 2: POST credentials with captured CSRF token and cookies
            $postResponse = Http::withOptions([
                'cookies' => $cookieJar,
                'verify' => false,
                'timeout' => 15,
                'allow_redirects' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => $loginUrl,
                    'Origin' => $baseUrl,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ])->asForm()->post($loginUrl, $payload);

            // Build consolidated cookie string from cookie jar
            $cookies = [];
            foreach ($cookieJar->toArray() as $cookie) {
                $cookies[] = "{$cookie['Name']}={$cookie['Value']}";
            }

            if (empty($cookies)) {
                return null;
            }

            $cookieString = implode('; ', $cookies);
            Log::info("FormLoginSessionResolver: Login simulated successfully for {$baseUrl}. Cookies captured: " . count($cookies));

            return $cookieString;
        } catch (\Throwable $e) {
            Log::warning("FormLoginSessionResolver failed for {$baseUrl}: " . $e->getMessage());
            return null;
        }
    }

    private function extractCsrfToken(string $html): ?string
    {
        // 1. Check <input type="hidden" name="_token" value="...">
        if (preg_match('/<input[^>]*name=["\']_token["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }

        // 2. Check <input type="hidden" value="..." name="_token">
        if (preg_match('/<input[^>]*value=["\']([^"\']+)["\'][^>]*name=["\']_token["\']/i', $html, $m)) {
            return $m[1];
        }

        // 3. Check <meta name="csrf-token" content="...">
        if (preg_match('/<meta[^>]*name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }

        // 4. Check <input type="hidden" name="csrf_token" ...>
        if (preg_match('/<input[^>]*name=["\']csrf[-_]?token["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function detectUserField(string $html): string
    {
        if (preg_match('/<input[^>]*name=["\'](email|username|user|login|account|identifier)["\']/i', $html, $m)) {
            return $m[1];
        }
        return 'email';
    }

    private function detectPasswordField(string $html): string
    {
        if (preg_match('/<input[^>]*type=["\']password["\'][^>]*name=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<input[^>]*name=["\']([^"\']+)["\'][^>]*type=["\']password["\']/i', $html, $m)) {
            return $m[1];
        }
        return 'password';
    }
}
