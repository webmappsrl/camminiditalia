<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Come mostrare un utente (camminatore, gestore) in Nova, nelle mail e nei
 * messaggi del passaporto: una sola regola per tutti.
 */
class UserDisplay
{
    /** Nome, oppure email se il nome è vuoto, oppure `—`. */
    public static function short(?Model $user): string
    {
        $name = trim((string) $user?->getAttribute('name'));
        $email = trim((string) $user?->getAttribute('email'));

        return $name !== '' ? $name : ($email !== '' ? $email : '—');
    }

    /** `Nome (email)`, oppure solo ciò che c'è, oppure `—`. */
    public static function withEmail(?Model $user): string
    {
        $name = trim((string) $user?->getAttribute('name'));
        $email = trim((string) $user?->getAttribute('email'));

        if ($name === '') {
            return $email !== '' ? $email : '—';
        }

        return $email !== '' ? $name.' ('.$email.')' : $name;
    }
}
