<?php

namespace App\Enums;

enum PaymentMethods: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case ESEWA = 'esewa';
    case KHALTI = 'khalti';
    case FONEPAY = 'fonepay';
    case CREDIT = 'credit';
    case FONEPAY_QR = 'fonepay_qr';
    case BANK_TRANSFER = 'bank_transfer';
}
