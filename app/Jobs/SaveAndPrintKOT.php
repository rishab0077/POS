<?php

namespace App\Jobs;

use App\Helpers\PDFHelper;
use App\Helpers\Printers\ThermalPrinter;
use App\Http\Service\RestaurantService;
use App\Models\Restaurant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Production printing uses PrintJob records and
 * scripts/print-station-agent.ps1. Retained for backward compatibility only.
 */
class SaveAndPrintKOT implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $KOT;
    private $kOTPath;
    private $billId;
    private string $productionArea;
    private string $ticketLabel;
    protected $restaurantService;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($KOT, $billId, string $productionArea = 'kitchen', string $ticketLabel = 'KOT')
    {
        $this->KOT = $KOT;
        $this->billId = $billId;
        $this->productionArea = $productionArea;
        $this->ticketLabel = $ticketLabel;

        $this->restaurantService = new RestaurantService();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        $printerName = $this->productionArea === 'bar'
            ? $this->restaurantService->getBarPrinter()
            : $this->restaurantService->getKitchenPrinter();

        try {
            $printer = new ThermalPrinter($printerName);
            $printer->printKOT($this->KOT, $this->billId, $this->productionArea, $this->ticketLabel);
            Log::info($this->ticketLabel . " Printed " . $this->KOT);
        } catch (\Exception $e) {
            Log::error("Error in printing {$this->ticketLabel} " . $e->getMessage());
        }
    }
}
