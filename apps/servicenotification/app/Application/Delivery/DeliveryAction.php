<?php

declare(strict_types=1);

namespace App\Application\Delivery;

enum DeliveryAction: string
{
    case Ack = 'ack';
    case Retry = 'retry';
    case DeadLetter = 'dead_letter';
}
