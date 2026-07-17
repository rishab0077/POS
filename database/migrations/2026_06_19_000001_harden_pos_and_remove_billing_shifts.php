<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tables')
            ->where('status', 'unavaliable')
            ->update(['status' => 'unavailable']);

        DB::table('tables')
            ->where('status', 'paid')
            ->update(['status' => 'available']);

        Schema::table('order_details', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 2)->nullable()->after('quantity');
        });

        DB::table('order_details')
            ->join('menus', 'menus.id', '=', 'order_details.menu_id')
            ->update(['order_details.unit_price' => DB::raw('menus.price')]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_lookup', 64)->nullable()->unique()->after('pin');
            $table->string('pin_hash')->nullable()->after('pin_lookup');
        });

        DB::table('users')
            ->whereNotNull('pin')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('users')->where('id', $user->id)->update([
                    'pin_lookup' => hash('sha256', (string) $user->pin),
                    'pin_hash' => Hash::make((string) $user->pin),
                    'pin' => null,
                ]);
            });

        if (Schema::hasColumn('bills', 'billing_shift_id')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropConstrainedForeignId('billing_shift_id');
            });
        }

        Schema::dropIfExists('billing_shifts');

        if (Schema::hasTable('print_jobs')) {
            DB::table('bills')
                ->whereNotNull('printed_at')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('print_jobs')
                        ->whereColumn('print_jobs.source_id', 'bills.id')
                        ->where('print_jobs.source_type', \App\Models\Bill::class)
                        ->where('print_jobs.type', 'bill')
                        ->where('print_jobs.status', 'printed');
                })
                ->update([
                    'printed_at' => null,
                    'printed_by' => null,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('tables')
            ->where('status', 'unavailable')
            ->update(['status' => 'unavaliable']);

        Schema::create('billing_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_no')->unique();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('closing_cash', 12, 2)->nullable();
            $table->decimal('cash_total', 12, 2)->default(0);
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('wallet_total', 12, 2)->default(0);
            $table->decimal('credit_total', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('void_total', 12, 2)->default(0);
            $table->json('category_totals')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('billing_shift_id')->nullable()->after('id')->constrained('billing_shifts')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pin_lookup']);
            $table->dropColumn(['pin_lookup', 'pin_hash']);
        });

        Schema::table('order_details', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
