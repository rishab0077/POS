<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryStoreRequest;
use App\Http\Service\CategoryService;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    private $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // get all categories order by rank col
        $categories = $this->categoryService->getCatergories();
        return view('admin.categories.index', compact('categories'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.categories.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CategoryStoreRequest $request)
    {


        $image = null;
        $description = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image')->store('public/categories');
        }

        if ($request->has('description')) {
            $description = $request->description;
        }

        $rank = Category::count() + 1;

        Category::create([
            'name' => $request->name,
            'print_destination' => $request->input('print_destination', Category::PRINT_DESTINATION_KOT),
            'loyalty_eligible' => $request->boolean('loyalty_eligible'),
            'description' => $description,
            'image' => $image,
            'rank' => $rank
        ]);

        return to_route('admin.categories.index')->with('success', 'Category created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Category $category)
    {
        return view('admin.categories.edit', compact('category'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'print_destination' => ['nullable', Rule::in([
                Category::PRINT_DESTINATION_KOT,
                Category::PRINT_DESTINATION_BOT,
            ])],
            'description' => ['nullable', 'string', 'max:2000'],
            'loyalty_eligible' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000'],
        ]);
        $image = $category->image;
        if ($request->hasFile('image')) {
            Storage::delete($category->image);
            $image = $request->file('image')->store('public/categories');
        }

        $category->update([
            'name' => $request->name,
            'print_destination' => $request->input('print_destination', $category->print_destination ?: Category::PRINT_DESTINATION_KOT),
            'loyalty_eligible' => $request->boolean('loyalty_eligible'),
            'description' => $request->description,
            'image' => $image
        ]);
        return to_route('admin.categories.index')->with('success', 'Category updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Category $category)
    {
        if ($category->menus->count() > 0) {
            return to_route('admin.categories.index')->with('warning', 'Category cannot be deleted because it has menus.');
        }
        // delete image if exists
        if ($category->image) {
            Storage::delete($category->image);
        }
        $category->menus()->detach();
        $category->delete();

        // update ranks
        $categories = Category::orderBy('rank', 'asc')->get();
        foreach ($categories as $key => $category) {
            $category->rank = $key + 1;
            $category->save();
        }


        return to_route('admin.categories.index')->with('danger', 'Category deleted successfully.');
    }

    public function updateRanks(Request $request)
    {
        $updatedRankings = $request->updatedRankings;

        try {
            foreach ($updatedRankings as $updatedRank) {
                $category = Category::find($updatedRank['id']);
                if ($category) {
                    $category->rank = $updatedRank['rank'];
                    $category->save();
                }
            }
            return response()->json(['message' => 'Category ranks updated successfully', 'status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Something went wrong', 'status' => 'error']);
        }
    }
}
