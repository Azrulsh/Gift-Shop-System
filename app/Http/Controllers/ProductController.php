<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('name', '%'.$search.'%')
                        ->orWhereLike('category', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', compact('products', 'search'));
    }

    public function create(): View
    {
        return view('products.create', ['product' => new Product]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $imagePath = null;

        try {
            $data = $request->safe()->except('image');
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('products', 'public');
                if ($imagePath === false) {
                    throw new RuntimeException('Unable to store product image.');
                }
                $data['image_path'] = $imagePath;
            }
            $product = Product::create($data);
        } catch (Throwable $exception) {
            report($exception);
            $this->deleteImage($imagePath ?: null);

            return back()->withInput($request->except('image'))
                ->with('error', 'Unable to create the product. Please try again.');
        }

        return to_route('products.show', $product)->with('success', 'Product created successfully.');
    }

    public function show(Product $product): View
    {
        return view('products.show', compact('product'));
    }

    public function edit(Product $product): View
    {
        return view('products.edit', compact('product'));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $imagePath = null;
        $oldImagePath = $product->image_path;

        try {
            $data = $request->safe()->except(['image', 'original_stock_quantity']);
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('products', 'public');
                if ($imagePath === false) {
                    throw new RuntimeException('Unable to store product image.');
                }
                $data['image_path'] = $imagePath;
            }
            DB::transaction(function () use ($product, $request, $data, &$oldImagePath): void {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                if ($locked->stock_quantity !== $request->integer('original_stock_quantity')) {
                    throw ValidationException::withMessages(['stock_quantity' => 'Stock changed since you opened this product. Reload before saving.']);
                }
                $oldImagePath = $locked->image_path;
                $locked->update($data);
            }, attempts: 3);
        } catch (ValidationException $exception) {
            $this->deleteImage($imagePath ?: null);

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->deleteImage($imagePath ?: null);

            return back()->withInput($request->except('image'))
                ->with('error', 'Unable to update the product. Please try again.');
        }

        $response = to_route('products.show', $product)->with('success', 'Product updated successfully.');
        if ($imagePath && ! $this->deleteImage($oldImagePath)) {
            $response->with('error', 'The product was saved, but its previous image could not be removed.');
        }

        return $response;
    }

    public function destroy(Product $product): RedirectResponse
    {
        $imagePath = $product->image_path;
        try {
            DB::transaction(function () use ($product, &$imagePath): void {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                if ($locked->orderItems()->exists()) {
                    throw ValidationException::withMessages(['product' => 'This product is referenced by an order and cannot be deleted.']);
                }
                $imagePath = $locked->image_path;
                $locked->delete();
            }, attempts: 3);
        } catch (ValidationException $exception) {
            return back()->with('error', 'This product is referenced by an order and cannot be deleted.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to delete the product. Please try again.');
        }

        $response = to_route('products.index')->with('success', 'Product deleted successfully.');
        if (! $this->deleteImage($imagePath)) {
            $response->with('error', 'The product was deleted, but its image could not be removed.');
        }

        return $response;
    }

    private function deleteImage(?string $path): bool
    {
        if ($path === null) {
            return true;
        }

        try {
            if (! Storage::disk('public')->delete($path)) {
                throw new RuntimeException('Unable to remove product image: '.$path);
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
