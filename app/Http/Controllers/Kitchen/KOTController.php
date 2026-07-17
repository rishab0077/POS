<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class KOTController extends Controller
{
    public function displayKOTs(): RedirectResponse
    {
        // Preserve the legacy admin URL while using the maintained KOT live view.
        return redirect()->route('order.KOT.view');
    }
}
