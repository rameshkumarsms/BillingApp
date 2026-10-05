<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, int $id, DashboardService $dashboard)
    {
        abort_unless($request->attributes->get('merchant_id') === $id, 403, 'API key does not belong to this merchant.');

        return response()->json(['data' => $dashboard->get($id)]);
    }
}
