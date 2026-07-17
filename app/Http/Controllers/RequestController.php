<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RequestController extends Controller
{
    public function requestWaiter(Request $request)
    {
        $message = "Booking Your Waiter";
        $minutes = 5;
        $seconds = 0;

        return view('waiter.booking', compact('message', 'minutes', 'seconds'));
    }

    public function requestBill(Request $request)
    {
        return view('requests.notice', [
            'title' => 'Request Your Bill',
            'message' => 'Please contact a staff member or the counter to request your bill.',
        ]);
    }

    public function requestExtra(Request $request)
    {
        return view('requests.notice', [
            'title' => 'Request Extra Service',
            'message' => 'Please contact a staff member for any additional service.',
        ]);
    }
}
