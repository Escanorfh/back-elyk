<?php
namespace App\Policies;
use App\Models\User;
use App\Models\PatientPath;
use Illuminate\Auth\Access\HandlesAuthorization;

class PatientPathPolicy {
    use HandlesAuthorization;
    public function view(User $user, PatientPath $path) {
        return $user->role === "admin" || $user->id === $path->patient_id;
    }
}
