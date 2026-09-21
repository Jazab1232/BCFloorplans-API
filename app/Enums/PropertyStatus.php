<?php

namespace App\Enums;

enum PropertyStatus: string
{
    case JUST_LISTED = 'Just listed';
    case UNDER_CONTRACT = 'Under contract';
    case SOLD = 'Sold';
    case PENDING = 'Pending';
    case WITHDRAWN = 'Withdrawn';
    case EXPIRED = 'Expired';
}
