<?php

namespace App\Domain\Identity;

enum AccountState: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';

    public function mayAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public function blocksAccess(): bool
    {
        return !$this->mayAuthenticate();
    }
}
