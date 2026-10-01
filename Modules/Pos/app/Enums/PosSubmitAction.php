<?php

namespace Modules\Pos\Enums;

enum PosSubmitAction: string
{
    case SendToKitchen = "send_to_kitchen";
    case PayAndFire = "pay_and_fire";
    case HoldOrder = "hold_order";

}
