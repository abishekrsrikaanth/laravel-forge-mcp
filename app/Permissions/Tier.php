<?php

namespace App\Permissions;

enum Tier: string
{
    case Read = 'read';
    case Action = 'action';
    case Risky = 'risky';
}
