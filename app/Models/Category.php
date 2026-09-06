<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Http\Service\MenuCategoryCacheService;

class Category extends Model
{
    use HasFactory;

    public const PRINT_DESTINATION_KOT = 'kot';
    public const PRINT_DESTINATION_BOT = 'bot';

    protected $fillable = ['name', 'print_destination', 'loyalty_eligible', 'image', 'description', 'rank'];

    protected $casts = [
        'loyalty_eligible' => 'boolean',
    ];

    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'category_menu');
    }

    public function save(array $options = [])
    {
        $saved = parent::save($options);
        MenuCategoryCacheService::refreshMenusAndCategories();
        return $saved;
    }

    public function delete()
    {
        $deleted = parent::delete();
        MenuCategoryCacheService::refreshMenusAndCategories();
        return $deleted;
    }
}
