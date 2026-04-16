<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MarketingController extends Controller
{
    public function index(): View
    {
        return view('marketing.home');
    }
}
