<?php

namespace App;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
}
