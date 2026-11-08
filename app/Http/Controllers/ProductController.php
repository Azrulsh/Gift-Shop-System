<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\Supplier;
use App\SupplierStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        $products = Product::query()->with('supplier')
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
        return view('products.create', ['product' => new Product, 'suppliers' => $this->supplierOptions()]);
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
            $product = DB::transaction(function () use ($data): Product {
                $this->validateSupplierAssignment($data);

                return Product::create($data);
            }, attempts: 3);
        } catch (ValidationException $exception) {
            $this->deleteImage($imagePath ?: null);

            throw $exception;
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
        $product->load('supplier');

        return view('products.show', compact('product'));
    }

    public function edit(Product $product): View
    {
        $product->load('supplier');

        return view('products.edit', ['product' => $product, 'suppliers' => $this->supplierOptions($product)]);
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
                $this->validateSupplierAssignment($data, $locked);
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

    private function supplierOptions(?Product $product = null): Collection
    {
        return Supplier::withTrashed()->where(function (Builder $query) use ($product): void {
            $query->where(function (Builder $query): void {
                $query->where('status', SupplierStatus::Active)->whereNull('deleted_at');
            });
            if ($product?->supplier_id !== null) {
                $query->orWhereKey($product->supplier_id);
            }
        })->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    private function validateSupplierAssignment(array $data, ?Product $product = null): void
    {
        if (! array_key_exists('supplier_id', $data) || $data['supplier_id'] === null
            || ($product?->supplier_id !== null && (int) $data['supplier_id'] === (int) $product->supplier_id)) {
            return;
        }
        $supplier = Supplier::query()->where('status', SupplierStatus::Active)->lockForUpdate()->find($data['supplier_id']);
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'This supplier is no longer active or available. Choose another supplier.']);
        }
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
