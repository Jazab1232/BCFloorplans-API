<?php

namespace App\Enums;

enum OccupancyType: string
{
    case SINGLE_VACANT = 'Single Vacant';
    case TENANT_OCCUPIED = 'Tenant Occupied';
    case OWNER_OCCUPIED = 'Owner Occupied';
}
