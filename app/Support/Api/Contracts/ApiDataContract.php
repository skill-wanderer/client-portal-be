<?php

namespace App\Support\Api\Contracts;

interface ApiDataContract
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}