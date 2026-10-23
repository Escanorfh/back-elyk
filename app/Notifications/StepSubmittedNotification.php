<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StepSubmittedNotification extends Notification
{
    use Queueable;

    protected $patient;
    protected $step;

    public function __construct($patient, $step)
    {
        $this->patient = $patient;
        $this->step = $step;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'patient_id' => $this->patient->id,
            'patient_name' => $this->patient->name,
            'step_title' => $this->step->title,
            'message' => "المريض {$this->patient->name} أنهى المرحلة: {$this->step->title}",
            'action_url' => "/admin/patients/{$this->patient->id}",
        ];
    }
}
