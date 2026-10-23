<?php
$file = __DIR__ . '/tests/Feature/ExamScoringServiceTest.php';
$content = file_get_contents($file);
$content = str_replace('\$', '$', $content);
file_put_contents($file, $content);
echo "Fixed syntax errors.\n";
