<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;

/**
 * لا عبر-مريض: القراءة والتعديل والمحو كلها لمالك الصفّ فقط، والاستثناء الوحيد أدمن أنشأ العنصر
 * بنفسه له. تعديل المريض لعنصر نسبه المعالج ممنوع — created_by هو المصدر الوحيد للمعلومة.
 */
class CalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CalendarEvent $event): bool
    {
        return $user->id === $event->user_id || $user->id === $event->created_by;
    }

    public function update(User $user, CalendarEvent $event): bool
    {
        return $event->isEditableBy($user->id) || $user->id === $event->created_by;
    }

    public function delete(User $user, CalendarEvent $event): bool
    {
        return $this->update($user, $event);
    }
}
