<?php
/**
 * Stands in for a publisher's server in the tests, run with PHP's built-in
 * web server. The directory to serve from is in the PUBLISHER_DIR environment
 * variable.
 *
 * /vocabulary.ttl  serves the file
 * /flaky.ttl  answers 503 the first time it is asked, then serves the file
 * /down.ttl   always answers 503
 * /busy.ttl   answers 429 with a Retry-After the first time, then the file
 * anything else is not found
 *
 * The file served is published.ttl in the directory, and each request is
 * counted in requests.log there, one path per line.
 */
$dir = getenv('PUBLISHER_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents("$dir/requests.log", "$path\n", FILE_APPEND);
$asked = count(array_keys(file("$dir/requests.log", FILE_IGNORE_NEW_LINES), $path, true));
if ($path === '/down.ttl' || ($path === '/flaky.ttl' && $asked === 1)) {
    http_response_code(503);
    echo 'Service unavailable.';
} elseif ($path === '/busy.ttl' && $asked === 1) {
    http_response_code(429);
    header('Retry-After: 0');
    echo 'Too many requests.';
} elseif (in_array($path, ['/vocabulary.ttl', '/flaky.ttl', '/busy.ttl'], true)) {
    header('Content-Type: text/turtle');
    readfile("$dir/published.ttl");
} else {
    http_response_code(404);
    echo 'Not found.';
}
