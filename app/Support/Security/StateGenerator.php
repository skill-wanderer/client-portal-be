<?php

namespace App\Support\Security;

class StateGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}