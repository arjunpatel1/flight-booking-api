<?php

namespace Modules\Import\Enums;

enum ImportType: string
{
    case Menus = 'menus';
    case Orders = 'orders';
    case Products = 'products';
    case Users = 'users';
}
