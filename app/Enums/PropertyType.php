<?php

namespace App\Enums;

enum PropertyType: string
{
    case DETACHED_HOME = 'Detached Home';
    case SEMI_DETACHED = 'Semi-Detached';
    case CONDO = 'Condo';
    case TOWNHOUSE = 'Townhouse';
    case APARTMENT = 'Apartment';
    case COMMERCIAL = 'Commercial';
}
