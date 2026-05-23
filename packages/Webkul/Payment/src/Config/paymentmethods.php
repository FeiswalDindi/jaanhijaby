<?php

return [
    'cashondelivery'  => [
        'code'        => 'cashondelivery',
        'title'       => 'Cash On Delivery',
        'description' => 'Cash On Delivery',
        'class'       => 'Webkul\Payment\Payment\CashOnDelivery',
        'active'      => true,
        'sort'        => 1,
    ],

    'moneytransfer'   => [
        'code'        => 'moneytransfer',
        'title'       => 'Money Transfer',
        'description' => 'Money Transfer',
        'class'       => 'Webkul\Payment\Payment\MoneyTransfer',
        'active'      => true,
        'sort'        => 2,
    ],

    'mpesa'           => [
        'code'        => 'mpesa',
        'title'       => 'M-Pesa',
        'description' => 'Pay securely via Safaricom Lipa Na M-Pesa',
        'class'       => 'Bruno\Mpesa\Payment\Mpesa',
        'active'      => true,
        'sort'        => 8,
    ],
];
