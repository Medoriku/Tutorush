<?php
declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (preg_match('~(^|/)(storage|vendor|\.git)(/|$)|(^|/)(smtp-config|google-oauth-config)\.php$~i', $path) || str_contains($path, "\0")) {
    http_response_code(403);
    exit('Forbidden');
}
return false;