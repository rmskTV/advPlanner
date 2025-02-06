<?php

namespace Modules\AdvBlocks\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdvBlocksController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('advblocks::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('advblocks::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('advblocks::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('advblocks::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
