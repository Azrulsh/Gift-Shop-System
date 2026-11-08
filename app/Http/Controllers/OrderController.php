<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexOrderRequest $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $orders = Order::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(fn (Builder $query) => $query->whereLike('order_number', '%'.$search.'%')->orWhereLike('customer_name_snapshot', '%'.$search.'%'));
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->validated('status')))
            ->when($request->filled('payment_status'), fn (Builder $query) => $query->where('payment_status', $request->validated('payment_status')))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(15)->withQueryString();

        return view('orders.index', compact('orders', 'search'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('orders.create', $this->formData(new Order));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOrderRequest $request, OrderService $service): RedirectResponse
    {
        try {
            $order = $service->create($request->validated());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Unable to create the order. No stock changes were saved.');
        }

        return to_route('orders.show', $order)->with('success', 'Order saved successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order): View
    {
        $order->load('items');

        return view('orders.show', compact('order'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order): View|RedirectResponse
    {
        if ($order->status->isTerminal()) {
            return to_route('orders.show', $order)->with('error', 'Completed and cancelled orders are read-only.');
        }

        return view('orders.edit', $this->formData($order));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOrderRequest $request, Order $order, OrderService $service): RedirectResponse
    {
        try {
            $service->update($order, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Unable to update the order. No stock changes were saved.');
        }

        return to_route('orders.show', $order)->with('success', 'Order updated successfully.');
    }

    private function formData(Order $order): array
    {
        $order->load('items');

        return [
            'order' => $order,
            'customers' => $order->exists ? collect() : Customer::orderBy('name')->get(['id', 'name', 'email']),
            'products' => Product::orderBy('name')->get(['id', 'name', 'price', 'stock_quantity']),
        ];
    }
}
