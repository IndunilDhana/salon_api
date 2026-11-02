<?php

namespace App\Models;

class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    protected $connection = 'central';
}
