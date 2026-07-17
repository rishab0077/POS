<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'default_supplier_id',
        'name',
        'unit',
        'sku',
        'current_quantity',
        'low_stock_threshold',
        'average_unit_cost',
        'last_purchase_cost',
        'auto_deduct',
        'active',
    ];

    protected $casts = [
        'current_quantity' => 'decimal:3',
        'low_stock_threshold' => 'decimal:3',
        'average_unit_cost' => 'decimal:2',
        'last_purchase_cost' => 'decimal:2',
        'auto_deduct' => 'boolean',
        'active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(StockCategory::class, 'category_id');
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function defaultSupplier()
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    public function menuMappings()
    {
        return $this->hasMany(MenuItemStockMapping::class);
    }
}
