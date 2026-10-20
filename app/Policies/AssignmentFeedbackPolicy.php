<?php
namespace App\Policies;
use App\Models\User;
use App\Models\AssignmentFeedback;
use Illuminate\Auth\Access\HandlesAuthorization;

class AssignmentFeedbackPolicy {
    use HandlesAuthorization;
    public function view(User $user, AssignmentFeedback $feedback) {
        if ($user->role === "admin") return true;
        return $feedback->is_visible_to_patient && $user->id === $feedback->assignment->patient_id;
    }
}
