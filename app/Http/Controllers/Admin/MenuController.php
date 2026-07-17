<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuType;
use App\Http\Controllers\Controller;
use App\Http\Requests\MenuStoreRequest;
use App\Http\Service\MenuService;
use App\Models\Category;
use App\Models\Menu;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MenuController extends Controller
{
    private $menuService;

    public function __construct(MenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $categoriesWithMenu = $this->menuService->getCatergoriesWithMenus();
        return view('admin.menus.index', compact('categoriesWithMenu'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $categories = Category::all();
        return view('admin.menus.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(MenuStoreRequest $request)
    {
        $image = null;
        $description = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image')->store('public/menus');
        }

        if ($request->has('description')) {
            $description = $request->description;
        }

        $menuType = MenuType::from($request->type);

        $menuQuantity = $request->quantity ? $request->quantity : 0;

        $menu = Menu::create([
            'name' => $request->name,
            'shortcode' => $request->shortCode,
            'description' => $description,
            'image' => $image,
            'price' => $request->price,
            'type' => $menuType,
            'quantity' => $menuQuantity,
            'production_area' => $request->input('production_area', 'kitchen'),
        ]);

        if ($request->has('category')) {
            $menu->category()->attach($request->category);
        }

        return to_route('admin.menus.index')->with('success', 'Menu created successfully.');
    }


    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Menu $menu)
    {
        $categories = Category::all();
        return view('admin.menus.edit', compact('menu', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Menu $menu)
    {
        $request->validate([
            'name' => 'required',
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'shortCode' => [
                'required',
                Rule::unique('menus', 'shortcode')->ignore($menu->id),
                'regex:/^\S*$/'
            ],
            'category' => ['required', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000'],
            'type' => ['required', 'in:stock,service'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'production_area' => ['nullable', 'in:kitchen,bar'],
        ]);

        $image = $menu->image;
        if ($request->hasFile('image')) {
            Storage::delete($menu->image);
            $image = $request->file('image')->store('public/menus');
        }

        $operation = $menu->update([
            'name' => $request->name,
            'shortcode' => $request->shortCode,
            'description' => $request->description,
            'image' => $image,
            'price' => $request->price,
            'type' => MenuType::from($request->type),
            'quantity' => $request->quantity ?: 0,
            'production_area' => $request->input('production_area', $menu->production_area ?: 'kitchen'),
        ]);

        if ($request->has('category')) {
            $menu->category()->sync($request->category);
        }

        if ($operation) {
            return to_route('admin.menus.index')->with('success', 'Menu updated successfully.');
        }
        return to_route('admin.menus.index')->with('danger', 'Menu not updated.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Menu $menu)
    {
        if ($menu->image) {
            Storage::delete($menu->image);
        }
        $menu->category()->detach();
        $menu->delete();
        return to_route('admin.menus.index')->with('danger', 'Menu deleted successfully.');
    }
}
