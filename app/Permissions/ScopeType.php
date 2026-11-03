<?php

namespace App\Permissions;

enum ScopeType: string
{
    case Global = 'global';
    case Organization = 'organization';
    case Server = 'server';
    case Site = 'site';
}
