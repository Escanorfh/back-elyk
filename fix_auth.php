<?php
$file = __DIR__ . '/app/Http/Controllers/AuthController.php';
$content = file_get_contents($file);
$content = str_replace('\$', '$', $content);
file_put_contents($file, $content);
echo "Fixed AuthController.\n";
