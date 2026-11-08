<?php

namespace App\Models;

use App\OrderStatus;
use App\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_number', 'customer_id', 'order_date', 'status', 'payment_status', 'total_amount', 'customer_name_snapshot', 'customer_email_snapshot', 'customer_phone_snapshot', 'customer_address_snapshot', 'creation_token', 'lock_version', 'cancelled_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['order_date' => 'date', 'status' => OrderStatus::class, 'payment_status' => PaymentStatus::class, 'total_amount' => 'decimal:2', 'lock_version' => 'integer', 'cancelled_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function itemsAreEditable(): bool
    {
        return $this->status === OrderStatus::Pending && $this->payment_status === PaymentStatus::Unpaid;
    }

    public function canBeCancelled(): bool
    {
        return ! $this->status->isTerminal() && $this->payment_status === PaymentStatus::Unpaid;
    }
}
