<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'phone',
        'amount',
        'merchant_request_id',
        'checkout_request_id',
        'result_code',
        'result_desc',
        'mpesa_receipt_number',
        'status',
        'raw_callback',
    ];

    protected $casts = [
        'raw_callback' => 'array',
        'amount'       => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
