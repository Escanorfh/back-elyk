<?php
$dir = __DIR__ . '/database/migrations/';

function updateMigration($file, $table, $columns) {
    $path = $GLOBALS['dir'] . $file;
    if (!file_exists($path)) return;
    
    $content = file_get_contents($path);
    $upMethodStart = strpos($content, "Schema::create('$table', function (Blueprint \$table) {");
    if ($upMethodStart === false) return;
    
    $tableStart = strpos($content, "\$table->id();", $upMethodStart);
    if ($tableStart === false) return;
    
    $insertionPoint = $tableStart + strlen("\$table->id();") + 1;
    
    $newContent = substr_replace($content, "\n" . $columns, $insertionPoint, 0);
    file_put_contents($path, $newContent);
}

// 1. Disorders
updateMigration('2026_08_20_093244_create_disorders_table.php', 'disorders', "            \$table->string('name');\n            \$table->string('name_ar');\n            \$table->text('description')->nullable();");

// 2. Exams
updateMigration('2026_08_20_093244_create_exams_table.php', 'exams', "            \$table->foreignId('disorder_id')->constrained()->cascadeOnDelete();\n            \$table->string('name');\n            \$table->string('name_ar');\n            \$table->text('description')->nullable();\n            \$table->boolean('is_active')->default(true);");

// 3. Subscales
updateMigration('2026_08_20_093245_create_subscales_table.php', 'subscales', "            \$table->foreignId('exam_id')->constrained()->cascadeOnDelete();\n            \$table->string('name');\n            \$table->string('name_ar');\n            \$table->integer('order')->default(0);");

// 4. Questions
updateMigration('2026_08_20_093245_create_questions_table.php', 'questions', "            \$table->foreignId('exam_id')->constrained()->cascadeOnDelete();\n            \$table->foreignId('subscale_id')->constrained()->cascadeOnDelete();\n            \$table->text('text');\n            \$table->text('text_ar');\n            \$table->enum('type', ['single_select', 'multi_select']);\n            \$table->integer('order')->default(0);");

// 5. Question Options
updateMigration('2026_08_20_093246_create_question_options_table.php', 'question_options', "            \$table->foreignId('question_id')->constrained()->cascadeOnDelete();\n            \$table->text('text');\n            \$table->text('text_ar');\n            \$table->integer('score');\n            \$table->integer('order')->default(0);");

// 6. Severity Bands
updateMigration('2026_08_20_093246_create_severity_bands_table.php', 'severity_bands', "            \$table->foreignId('subscale_id')->nullable()->constrained()->cascadeOnDelete();\n            \$table->foreignId('exam_id')->nullable()->constrained()->cascadeOnDelete();\n            \$table->integer('min_score');\n            \$table->integer('max_score');\n            \$table->string('label');\n            \$table->string('label_ar');\n            \$table->integer('order')->default(0);");

// 7. Exam Attempts
updateMigration('2026_08_20_093247_create_exam_attempts_table.php', 'exam_attempts', "            \$table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();\n            \$table->foreignId('exam_id')->constrained()->cascadeOnDelete();\n            \$table->enum('status', ['in_progress', 'completed'])->default('in_progress');\n            \$table->timestamp('started_at')->useCurrent();\n            \$table->timestamp('completed_at')->nullable();");

// 8. Exam Answers
updateMigration('2026_08_20_093247_create_exam_answers_table.php', 'exam_answers', "            \$table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();\n            \$table->foreignId('question_id')->constrained()->cascadeOnDelete();\n            \$table->json('selected_option_ids');\n            \$table->integer('score');");

// 9. Attempt Subscale Results
updateMigration('2026_08_20_093248_create_attempt_subscale_results_table.php', 'attempt_subscale_results', "            \$table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();\n            \$table->foreignId('subscale_id')->constrained()->cascadeOnDelete();\n            \$table->integer('total_score');\n            \$table->foreignId('severity_band_id')->nullable()->constrained()->nullOnDelete();");

// 10. Attempt Results
updateMigration('2026_08_20_093248_create_attempt_results_table.php', 'attempt_results', "            \$table->foreignId('attempt_id')->unique()->constrained('exam_attempts')->cascadeOnDelete();\n            \$table->foreignId('disorder_id')->constrained()->cascadeOnDelete();\n            \$table->integer('total_score');\n            \$table->foreignId('severity_band_id')->nullable()->constrained()->nullOnDelete();\n            \$table->timestamp('computed_at')->useCurrent();");

echo "Migrations updated successfully.\n";
