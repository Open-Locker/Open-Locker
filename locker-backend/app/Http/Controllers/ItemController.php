<?php

namespace App\Http\Controllers;

use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    /**
     * Get all Items.
     *
     * @response AnonymousResourceCollection<ItemResource>
     */
    public function index(): AnonymousResourceCollection
    {
        return ItemResource::collection(Item::all());
    }
}
