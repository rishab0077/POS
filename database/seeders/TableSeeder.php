<?php

namespace Database\Seeders;

use App\Models\Table;
use App\Models\TableLocation;
use App\Enums\TableStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        $tableLocations = [
            ['name' => 'First Floor Indoor'],
            ['name' => 'Outdoor'],
            ['name' => 'Indoor'],
        ];

        foreach ($tableLocations as $tableLocation) {
            TableLocation::updateOrCreate(['name' => $tableLocation['name']], $tableLocation);
        }

        $indoor = TableLocation::where('name', 'Indoor')->firstOrFail();

        foreach ([
            ['name' => 'T1', 'guest_number' => 4],
            ['name' => 'T2', 'guest_number' => 4],
            ['name' => 'T3', 'guest_number' => 6],
        ] as $table) {
            Table::updateOrCreate(
                ['name' => $table['name']],
                [
                    'guest_number' => $table['guest_number'],
                    'status' => TableStatus::Available,
                    'table_location' => $indoor->id,
                ]
            );
        }
    }
}
