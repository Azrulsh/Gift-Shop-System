<?php

namespace App\Models;

use App\SupplierStatus;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'contact_person', 'email', 'phone', 'address', 'status'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return ['status' => SupplierStatus::class];
    }
}
