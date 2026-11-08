<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderCancellationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CancelOrderRequest $request, Order $order, OrderService $service): RedirectResponse
    {
        try {
            $service->cancel($order, $request->integer('lock_version'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to cancel the order. No stock changes were saved.');
        }

        return to_route('orders.show', $order)->with('success', 'Order is cancelled. Reserved stock has been restored once.');
    }
}
