<?php

namespace App\Http\Controllers;

use App\Http\Resources\ItemLoanResource;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Models\ItemLoan;
use App\Services\LockerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    /**
     * Get all Items.
     *
     * @response AnonymousResourceCollection<ItemResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ItemResource::collection(Item::all());
    }

    /**
     * Get all Items from User.
     *
     * @response AnonymousResourceCollection<ItemResource>
     */
    public function getBorrowedItemsFromUser(Request $request): AnonymousResourceCollection
    {
        $userItemIds = ItemLoan::where('user_id', $request->user()->id)
            ->whereNull('returned_at')
            ->pluck('item_id');

        return ItemResource::collection(Item::whereIn('id', $userItemIds)->get());
    }

    /**
     * Borrow a Item
     */
    public function borrowItem(Item $item, Request $request, LockerService $lockerService): JsonResponse
    {
        // Prüfe ob das Item bereits ausgeliehen ist
        if ($item->activeLoan()->exists()) {
            return response()->json([
                'status' => false,
                'message' => __('Item is already borrowed'),
            ]);
        }

        DB::transaction(function () use ($lockerService, $item, $request) {
            // Erstelle einen neuen Ausleih-Eintrag
            ItemLoan::create([
                'item_id' => $item->id,
                'user_id' => $request->user()->id,
                'borrowed_at' => now(),
            ]);

            $lockerService->openLocker($item->locker);
        });

        return response()->json([
            'status' => true,
            'message' => __('Item borrowed successfully'),
        ]);
    }

    /**
     * Returns a Item
     */
    public function returnItem(Item $item, Request $request, LockerService $lockerService): JsonResponse
    {
        $activeLoan = $item->activeLoan;

        if (! $activeLoan) {
            return response()->json([
                'status' => false,
                'message' => __('Item is not borrowed'),
            ]);
        }

        DB::transaction(function () use ($lockerService, $item, $activeLoan) {
            $activeLoan->update([
                'returned_at' => now(),
            ]);

            $lockerService->openLocker($item->locker);
        });

        return response()->json([
            'status' => true,
            'message' => __('Item returned successfully'),
        ]);
    }

    /**
     * Get loan history for the current user.
     *
     * @response AnonymousResourceCollection<ItemLoanResource>
     */
    public function getLoanHistoryForUser(Request $request): AnonymousResourceCollection
    {
        $loans = ItemLoan::where('user_id', $request->user()->id)
            ->with('item')
            ->get();

        return ItemLoanResource::collection($loans);
    }
}
