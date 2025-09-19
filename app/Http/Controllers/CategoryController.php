<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    /**
     * List all categories (public).
     *
     * @OA\Get(
     *   path="/api/categories",
     *   tags={"Categories"},
     *   summary="List all categories",
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="categories",
     *         type="array",
     *         @OA\Items(
     *           type="object",
     *           @OA\Property(property="id", type="integer", example=1),
     *           @OA\Property(property="name", type="string", example="Groceries"),
     *           @OA\Property(property="description", type="string", example="Supermarket and food essentials"),
     *           @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-04T10:00:00Z"),
     *           @OA\Property(property="updated_at", type="string", format="date-time", example="2025-09-04T10:00:00Z")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=404, description="No categories found.")
     * )
     */
    public function index()
    {
        $categories = Category::orderBy('name')->get();

        if ($categories->isEmpty()) {
            return response()->json(['message' => 'No categories found.'], 404);
        }

        return response()->json([
            'categories' => CategoryResource::collection($categories),
        ]);
    }

    /**
     * Create a new category (admin only).
     *
     * @OA\Post(
     *   path="/api/categories",
     *   tags={"Categories"},
     *   summary="Create a new category (admin only)",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name"},
     *       @OA\Property(property="name", type="string", maxLength=255, example="Utilities"),
     *       @OA\Property(property="description", type="string", maxLength=1000, example="Electricity, water, internet")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Category created",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Category created successfully"),
     *       @OA\Property(property="category",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=5),
     *         @OA\Property(property="name", type="string", example="Utilities"),
     *         @OA\Property(property="description", type="string", example="Electricity, water, internet")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Only admins can create categories"),
     *   @OA\Response(
     *     response=422,
     *     description="Validation error",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="errors", type="object",
     *         example={"name":{"The name has already been taken."}}
     *       )
     *     )
     *   )
     * )
     */
    public function store(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        if (Auth::user()->role !== 'admin') {
            return response()->json(['error' => 'Only admins can create categories'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|unique:categories,name|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Category created successfully',
            'category' => new CategoryResource($category),
        ]);
    }

    /**
     * Get a single category by id (public).
     *
     * @OA\Get(
     *   path="/api/categories/{category}",
     *   tags={"Categories"},
     *   summary="Get a single category",
     *   @OA\Parameter(
     *     name="category",
     *     in="path",
     *     required=true,
     *     description="Category ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="category",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=2),
     *         @OA\Property(property="name", type="string", example="Restaurants"),
     *         @OA\Property(property="description", type="string", example="Dining and coffee shops")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=404, description="Category not found")
     * )
     */
    public function show(Category $category)
    {
        return response()->json([
            'category' => new CategoryResource($category),
        ]);
    }

    /**
     * Update a category (admin only).
     *
     * @OA\Put(
     *   path="/api/categories/{category}",
     *   tags={"Categories"},
     *   summary="Update a category (admin only)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="category",
     *     in="path",
     *     required=true,
     *     description="Category ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string", maxLength=255, example="Dining"),
     *       @OA\Property(property="description", type="string", maxLength=1000, example="Eating out and cafés")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Category updated",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Category updated successfully"),
     *       @OA\Property(property="category",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=2),
     *         @OA\Property(property="name", type="string", example="Dining"),
     *         @OA\Property(property="description", type="string", example="Eating out and cafés")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Only admins can update categories"),
     *   @OA\Response(
     *     response=422,
     *     description="Validation error",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="errors", type="object",
     *         example={"name":{"The name has already been taken."}}
     *       )
     *     )
     *   )
     * )
     */
    public function update(Request $request, Category $category)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        if (Auth::user()->role !== 'admin') {
            return response()->json(['error' => 'Only admins can update categories'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:categories,name,' . $category->id,
            'description' => 'sometimes|nullable|string|max:1000',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => new CategoryResource($category),
        ]);
    }

    /**
     * Delete a category (admin only).
     *
     * @OA\Delete(
     *   path="/api/categories/{category}",
     *   tags={"Categories"},
     *   summary="Delete a category (admin only)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="category",
     *     in="path",
     *     required=true,
     *     description="Category ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Category deleted",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Category deleted successfully")
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Only admins can delete categories")
     * )
     */
    public function destroy(Category $category)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        if (Auth::user()->role !== 'admin') {
            return response()->json(['error' => 'Only admins can delete categories'], 403);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }
}
