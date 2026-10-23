<?php

function appendToModel($model, $methods) {
    $path = __DIR__ . "/app/Models/$model.php";
    if (!file_exists($path)) {
        echo "Model $model not found.\n";
        return;
    }
    
    $content = file_get_contents($path);
    $insertionPoint = strrpos($content, '}');
    
    // Also add protected $guarded = []; to make mass assignment easy
    $guarded = "\n    protected \$guarded = [];\n\n";
    if (strpos($content, 'protected $guarded') === false && strpos($content, 'protected $fillable') === false) {
        $classPos = strpos($content, '{');
        $content = substr_replace($content, $guarded, $classPos + 1, 0);
        $insertionPoint += strlen($guarded);
    }
    
    $newContent = substr_replace($content, $methods . "\n", $insertionPoint, 0);
    file_put_contents($path, $newContent);
}

// User model (need to just add relationships to it)
$userPath = __DIR__ . '/app/Models/User.php';
$userContent = file_get_contents($userPath);
if (strpos($userContent, 'examAttempts') === false) {
    $userMethods = "    public function examAttempts()\n    {\n        return \$this->hasMany(ExamAttempt::class, 'patient_id');\n    }\n";
    $insertionPoint = strrpos($userContent, '}');
    $userContent = substr_replace($userContent, $userMethods, $insertionPoint, 0);
    file_put_contents($userPath, $userContent);
}

// Disorder
appendToModel('Disorder', "    public function exams()\n    {\n        return \$this->hasMany(Exam::class);\n    }");

// Exam
appendToModel('Exam', "    public function disorder()\n    {\n        return \$this->belongsTo(Disorder::class);\n    }\n\n    public function subscales()\n    {\n        return \$this->hasMany(Subscale::class);\n    }\n\n    public function questions()\n    {\n        return \$this->hasMany(Question::class);\n    }\n\n    public function severityBands()\n    {\n        return \$this->hasMany(SeverityBand::class);\n    }");

// Subscale
appendToModel('Subscale', "    public function exam()\n    {\n        return \$this->belongsTo(Exam::class);\n    }\n\n    public function questions()\n    {\n        return \$this->hasMany(Question::class);\n    }\n\n    public function severityBands()\n    {\n        return \$this->hasMany(SeverityBand::class);\n    }");

// Question
$questionMethods = <<<PHP
    public function exam()
    {
        return \$this->belongsTo(Exam::class);
    }

    public function subscale()
    {
        return \$this->belongsTo(Subscale::class);
    }

    public function options()
    {
        return \$this->hasMany(QuestionOption::class);
    }
PHP;
appendToModel('Question', $questionMethods);

// QuestionOption
appendToModel('QuestionOption', "    public function question()\n    {\n        return \$this->belongsTo(Question::class);\n    }");

// SeverityBand
appendToModel('SeverityBand', "    public function exam()\n    {\n        return \$this->belongsTo(Exam::class);\n    }\n\n    public function subscale()\n    {\n        return \$this->belongsTo(Subscale::class);\n    }");

// ExamAttempt
$examAttemptMethods = <<<PHP
    public function patient()
    {
        return \$this->belongsTo(User::class, 'patient_id');
    }

    public function exam()
    {
        return \$this->belongsTo(Exam::class);
    }

    public function answers()
    {
        return \$this->hasMany(ExamAnswer::class, 'attempt_id');
    }

    public function subscaleResults()
    {
        return \$this->hasMany(AttemptSubscaleResult::class, 'attempt_id');
    }

    public function result()
    {
        return \$this->hasOne(AttemptResult::class, 'attempt_id');
    }
PHP;
appendToModel('ExamAttempt', $examAttemptMethods);

// ExamAnswer
$examAnswerMethods = <<<PHP
    protected \$casts = [
        'selected_option_ids' => 'array',
    ];

    public function attempt()
    {
        return \$this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return \$this->belongsTo(Question::class);
    }
PHP;
appendToModel('ExamAnswer', $examAnswerMethods);

// AttemptSubscaleResult
$subscaleResultMethods = <<<PHP
    public function attempt()
    {
        return \$this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function subscale()
    {
        return \$this->belongsTo(Subscale::class);
    }

    public function severityBand()
    {
        return \$this->belongsTo(SeverityBand::class);
    }
PHP;
appendToModel('AttemptSubscaleResult', $subscaleResultMethods);

// AttemptResult
$attemptResultMethods = <<<PHP
    public function attempt()
    {
        return \$this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function disorder()
    {
        return \$this->belongsTo(Disorder::class);
    }

    public function severityBand()
    {
        return \$this->belongsTo(SeverityBand::class);
    }
PHP;
appendToModel('AttemptResult', $attemptResultMethods);

echo "Models updated successfully.\n";
