<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    private const int MAX_CENTS = 9999999999999999;

    private const int MAX_STOCK = 4294967295;

    /**
     * @param  array{customer_id: int|string, creation_token: string, order_date: string, status: string, payment_status?: string, items: array<array-key, array{product_id: int|string, quantity: int|string}>}  $data
     */
    public function create(array $data): Order
    {
        try {
            return DB::transaction(function () use ($data): Order {
                if ($existing = Order::where('creation_token', $data['creation_token'])->first()) {
                    return $existing;
                }

                $customer = Customer::query()->lockForUpdate()->find($data['customer_id']);
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
                }
                $order = Order::create([
                    'order_number' => 'GS-'.Str::ulid(),
                    'creation_token' => $data['creation_token'],
                    'customer_id' => $customer->id,
                    'customer_name_snapshot' => $customer->name,
                    'customer_email_snapshot' => $customer->email,
                    'customer_phone_snapshot' => $customer->phone,
                    'customer_address_snapshot' => $customer->address,
                    'order_date' => $data['order_date'],
                    'status' => $data['status'],
                    'payment_status' => $data['payment_status'] ?? PaymentStatus::Unpaid,
                    'total_amount' => '0.00',
                ]);
                $this->replaceItems($order, $data['items']);

                return $order->fresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($existing = Order::where('creation_token', $data['creation_token'])->first()) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  array{lock_version: int|string, status: string, payment_status: string, order_date?: string, items?: array<array-key, array{product_id: int|string, quantity: int|string}>}  $data
     */
    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->checkVersion($order, $data['lock_version']);
            if ($order->status->isTerminal()) {
                throw ValidationException::withMessages(['status' => 'Completed and cancelled orders are read-only.']);
            }

            $status = OrderStatus::from($data['status']);
            $payment = PaymentStatus::from($data['payment_status']);
            $allowed = $order->status === OrderStatus::Pending
                ? [OrderStatus::Pending, OrderStatus::Processing]
                : [OrderStatus::Processing, OrderStatus::Completed];
            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'This order status transition is not allowed.']);
            }
            if ($order->payment_status === PaymentStatus::Paid && $payment !== PaymentStatus::Paid) {
                throw ValidationException::withMessages(['payment_status' => 'A paid order cannot be marked unpaid.']);
            }
            if ($status === OrderStatus::Completed && $payment !== PaymentStatus::Paid) {
                throw ValidationException::withMessages(['payment_status' => 'An order must be paid before completion.']);
            }

            if ($order->itemsAreEditable()) {
                if (isset($data['items'])) {
                    $this->replaceItems($order, $data['items']);
                }
                $order->order_date = $data['order_date'] ?? $order->order_date;
            } elseif (array_key_exists('items', $data)
                || (isset($data['order_date']) && $data['order_date'] !== $order->order_date->toDateString())) {
                throw ValidationException::withMessages(['items' => 'Only unpaid pending orders allow date or item changes.']);
            }

            $order->status = $status;
            $order->payment_status = $payment;
            $order->lock_version++;
            $order->save();

            return $order->fresh();
        }, attempts: 3);
    }

    public function cancel(Order $order, int $version): Order
    {
        return DB::transaction(function () use ($order, $version): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === OrderStatus::Cancelled) {
                return $order;
            }
            $this->checkVersion($order, $version);
            if (! $order->canBeCancelled()) {
                throw ValidationException::withMessages(['status' => 'Only unpaid pending or processing orders can be cancelled.']);
            }

            $items = $order->items()->get()->keyBy('product_id');
            $products = Product::whereIn('id', $items->keys())->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'An ordered product is missing. Stock was not changed.']);
            }
            foreach ($products as $product) {
                $this->adjustStock($product, $items[$product->id]->quantity, 'items');
            }
            $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'lock_version' => $order->lock_version + 1]);

            return $order->fresh();
        }, attempts: 3);
    }

    private function checkVersion(Order $order, int|string $version): void
    {
        if ($order->lock_version !== (int) $version) {
            throw ValidationException::withMessages(['lock_version' => 'This order changed since you opened it. Reload before trying again.']);
        }
    }

    /**
     * @param  array<array-key, array{product_id: int|string, quantity: int|string}>  $rows
     */
    private function replaceItems(Order $order, array $rows): void
    {
        $existing = $order->items()->get()->keyBy('product_id');
        $selected = collect($rows)->keyBy(fn (array $row): int => (int) $row['product_id']);
        if ($selected->count() !== count($rows) || $selected->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Select at least one product, with no duplicates.']);
        }
        $ids = $existing->keys()->merge($selected->keys())->unique();
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== $ids->count()) {
            throw ValidationException::withMessages(['items' => 'One or more selected products no longer exist.']);
        }

        $total = 0;
        $lines = [];
        foreach ($rows as $index => $row) {
            $product = $products[(int) $row['product_id']];
            $old = $existing->get($product->id);
            $quantity = (int) $row['quantity'];
            $unitPrice = $old?->unit_price ?? $product->price;
            $cents = $this->cents($unitPrice);
            if ($quantity < 1 || $quantity > 1000000 || $cents > intdiv(self::MAX_CENTS, $quantity)) {
                throw ValidationException::withMessages(['items.'.$index.'.quantity' => 'This quantity exceeds the supported order amount.']);
            }
            $subtotal = $cents * $quantity;
            if ($total > self::MAX_CENTS - $subtotal) {
                throw ValidationException::withMessages(['items' => 'The order total exceeds the supported amount.']);
            }
            $total += $subtotal;
            $this->adjustStock($product, ($old?->quantity ?? 0) - $quantity, 'items.'.$index.'.quantity');
            $lines[] = ['product_id' => $product->id, 'product_name_snapshot' => $old?->product_name_snapshot ?? $product->name, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'subtotal' => $this->decimal($subtotal)];
        }
        foreach ($existing as $old) {
            if (! $selected->has($old->product_id)) {
                $this->adjustStock($products[$old->product_id], $old->quantity, 'items');
            }
        }

        $order->items()->delete();
        $order->items()->createMany($lines);
        $order->total_amount = $this->decimal($total);
        $order->save();
    }

    private function adjustStock(Product $product, int $difference, string $field): void
    {
        $available = $product->stock_quantity + $difference;
        if ($available < 0 || $available > self::MAX_STOCK) {
            throw ValidationException::withMessages([$field => 'Insufficient available stock or stock limit exceeded for '.$product->name.'.']);
        }
        $product->stock_quantity = $available;
        $product->save();
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
