<?php

namespace App\Policies;

use App\Models\PersonalTask;
use App\Models\User;

/**
 * التمييز المطلوب: تعديل المحتوى للمالك فقط إذا أنشأه المريض، أما إنهاء المهمة وإعادة فتحها
 * ففعل المريض على نفسه — يملكه حتى لو نسَبَها المعالج (وقتها تبقى للقراءة في بقية الحقول).
 */
class PersonalTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PersonalTask $task): bool
    {
        return $user->id === $task->user_id || $user->id === $task->created_by;
    }

    /**
     * النص ملك من كتبه: المريض يعدّله ما لم ينسبه معالج، والمعالج يصحّح ما نسبه هو —
     * وإلا بقي خطأ إملائي في وصف علاجي لا يملك أحد أن يصلحه.
     */
    public function update(User $user, PersonalTask $task): bool
    {
        return $task->isEditableBy($user->id) || $user->id === $task->created_by;
    }

    public function delete(User $user, PersonalTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function complete(User $user, PersonalTask $task): bool
    {
        return $user->id === $task->user_id;
    }

    public function reopen(User $user, PersonalTask $task): bool
    {
        return $this->complete($user, $task);
    }
}
