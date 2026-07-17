<?php

namespace App\Modules\Inventory\Models;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItemStockMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id',
        'stock_item_id',
        'quantity_per_sale',
        'active',
    ];

    protected $casts = [
        'quantity_per_sale' => 'decimal:3',
        'active' => 'boolean',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_item_id');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}
