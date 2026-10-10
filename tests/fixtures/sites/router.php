<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/redirect-internal') { header('Location: http://169.254.169.254/latest/meta-data/', true, 302); exit; }
if ($p === '/old' || $p === '/old/') { readfile(__DIR__ . '/old.html'); exit; }
if (str_starts_with($p, '/good')) { readfile(__DIR__ . '/good.html'); exit; }
if ($p === '/scroller') { readfile(__DIR__ . '/scroller.html'); exit; }
if ($p === '/slow') { sleep(30); echo 'slow'; exit; }
http_response_code(404); echo 'nie ma';
