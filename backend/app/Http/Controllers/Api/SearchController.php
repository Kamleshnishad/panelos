<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GlobalSearchService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use ApiResponse;

    public function __construct(private GlobalSearchService $search)
    {
    }

    /** GET /api/search?q=  — records of the user's own company that the role may open, grouped by kind. */
    public function index(Request $request)
    {
        $q = (string) $request->query('q', '');

        return $this->successResponse(['query' => trim($q), 'groups' => $this->search->search($request->user(), $q)], 'Search results');
    }
}
