<?php

namespace App\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case Admin = 'admin';
}
