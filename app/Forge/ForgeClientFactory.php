<?php

declare(strict_types=1);

namespace App\Forge;

use App\Models\User;

class ForgeClientFactory
{
    public function forUser(User $user): ForgeClient
    {
        $connection = $user->forgeConnection;

        if ($connection === null) {
            throw new ForgeNotConnectedException;
        }

        return new ForgeClient($connection->token);
    }
}
