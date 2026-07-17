<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Table;
use App\Rules\DateBetween;
use App\Rules\TimeBetween;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;


class ReservationController extends Controller
{
    public function stepOne(Request $request)
    {
        $reservation = $request->session()->get('reservation');
        $min_date = Carbon::today();
        $max_date = Carbon::now()->addWeek();
        return view('reservations.step-one', compact('reservation', 'min_date', 'max_date'));
    }

    public function storeStepOne(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required'],
            'last_name' => ['required'],
            'email' => ['required', 'email'],
            'res_date' => ['required', 'date', new DateBetween, new TimeBetween],
            'tel_number' => ['required'],
            'guest_number' => ['required'],
        ]);

        if (empty($request->session()->get('reservation'))) {
            $reservation = new Reservation();
            $reservation->fill($validated);
            $request->session()->put('reservation', $reservation);
        } else {
            $reservation = $request->session()->get('reservation');
            $reservation->fill($validated);
            $request->session()->put('reservation', $reservation);
        }

        return to_route('reservations.step.two');
    }
    public function stepTwo(Request $request)
    {
        $reservation = $request->session()->get('reservation');

        if (!$reservation) {
            return to_route('reservations.step.one');
        }

        $res_table_ids = Reservation::orderBy('res_date')->get()->filter(function ($value) use ($reservation) {
            return $value->res_date->format('Y-m-d') == $reservation->res_date->format('Y-m-d');
        })->pluck('table_id');
        $tables = Table::where('status', TableStatus::Available)
            ->where('guest_number', '>=', $reservation->guest_number)
            ->whereNotIn('id', $res_table_ids)->get();
        return view('reservations.step-two', compact('reservation', 'tables'));
    }

    public function storeStepTwo(Request $request)
    {
        $validated = $request->validate([
            'table_id' => ['required', 'integer', 'exists:tables,id']
        ]);
        $reservation = $request->session()->get('reservation');

        if (!$reservation) {
            return to_route('reservations.step.one');
        }

        DB::transaction(function () use ($reservation, $validated) {
            $table = Table::whereKey($validated['table_id'])->lockForUpdate()->firstOrFail();

            if ($table->status !== TableStatus::Available || $table->guest_number < $reservation->guest_number) {
                throw ValidationException::withMessages([
                    'table_id' => 'This table is no longer available for the reservation.',
                ]);
            }

            $alreadyReserved = Reservation::where('table_id', $table->id)
                ->whereDate('res_date', $reservation->res_date->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($alreadyReserved) {
                throw ValidationException::withMessages([
                    'table_id' => 'This table has already been reserved for that date.',
                ]);
            }

            $reservation->fill($validated);
            $reservation->save();
        });

        $request->session()->forget('reservation');

        return to_route('thankyou');
    }
}
