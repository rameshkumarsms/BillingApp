<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $q = Invoice::query()->where('merchant_id', $request->attributes->get('merchant_id'))->with('lines')->latest('period_start');
        if ($request->filled('customer_id')) {
            $q->where('customer_id', (int) $request->query('customer_id'));
        }

        return response()->json($q->paginate(25));
    }
}
