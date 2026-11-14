<?php

namespace App\Enums;

enum UserType: string
{
    case Human = 'human';
    case Agent = 'agent';
}
