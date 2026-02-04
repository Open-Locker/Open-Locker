<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CompartmentResource;
use App\Services\CompartmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompartmentController extends Controller
{
    public function __construct(
        private readonly CompartmentService $compartmentService,
    ) {}

    /**
     * List all compartments with their current contents.
     *
     * @response AnonymousResourceCollection<CompartmentResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CompartmentResource::collection(
            $this->compartmentService->listWithContents()
        );
    }
}

