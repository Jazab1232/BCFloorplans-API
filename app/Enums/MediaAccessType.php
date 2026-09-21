<?php

namespace App\Enums;

enum MediaAccessType: string
{
    case LOCKBOX = 'Lockbox';
    case KEY = 'Key';
    case CODE = 'Access Code';
    case APPOINTMENT = 'Appointment Only';
    case AGENT = 'Listing Agent Only';
}
