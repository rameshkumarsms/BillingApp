<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function store(Request $request)
    {
        $m = $request->attributes->get('merchant_id');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', "unique:customers,email,NULL,id,merchant_id,{$m}"],
        ]);

        return response()->json(['data' => Customer::query()->create($data + ['merchant_id' => $m])], 201);
    }
}
