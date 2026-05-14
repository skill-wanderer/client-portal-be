<?php

$publicPath = __DIR__.'/../../public';
$requestPath = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$candidatePath = realpath($publicPath.$requestPath);

if (
    $requestPath !== '/'
    && $candidatePath !== false
    && str_starts_with($candidatePath, $publicPath.DIRECTORY_SEPARATOR)
    && is_file($candidatePath)
) {
    return false;
}

require $publicPath.'/index.php';