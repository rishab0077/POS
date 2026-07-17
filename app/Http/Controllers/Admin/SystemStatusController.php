<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\SystemStatusService;

class SystemStatusController extends Controller
{
    public function __invoke(SystemStatusService $statusService)
    {
        return view('admin.system.status', [
            'status' => $statusService->status(),
            'alerts' => $statusService->alertFindings(),
        ]);
    }
}
