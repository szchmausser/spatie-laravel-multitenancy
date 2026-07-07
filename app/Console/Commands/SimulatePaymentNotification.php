<?php

namespace App\Console\Commands;

use App\Enums\BankCode;
use App\Enums\SourceType;
use App\Models\PaymentNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SimulatePaymentNotification extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'simulate:payment-notification
        {--bank= : Bank code (bdv, bnc, banesco, mercantil, provincial)}
        {--amount= : Amount in Bs (e.g., 3000.00)}
        {--reference= : Bank reference (e.g., 006236568762)}
        {--phone=04243153557 : Sender phone number}
        {--source=sms : Source type (sms, bank-app)}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate a bank payment notification for testing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $bank = $this->option('bank');
        $amount = $this->option('amount');
        $reference = $this->option('reference');
        $phone = $this->option('phone');
        $source = $this->option('source');

        if (! $bank || ! $amount || ! $reference) {
            $this->error('Required options: --bank, --amount, --reference');

            return Command::FAILURE;
        }

        $validCodes = array_map(fn (BankCode $c) => $c->value, BankCode::cases());

        if (! in_array($bank, $validCodes, true)) {
            $display = implode(', ', array_map(fn (BankCode $c) => $c->value, BankCode::cases()));
            $this->error("Invalid bank code [{$bank}]. Valid: {$display}");

            return Command::FAILURE;
        }

        $validSources = SourceType::values();

        if (! in_array($source, $validSources, true)) {
            $display = implode(', ', $validSources);
            $this->error("Invalid source type [{$source}]. Valid: {$display}");

            return Command::FAILURE;
        }

        $rawText = $this->formatNotification($bank, $amount, $reference, $phone);
        $dedupHash = PaymentNotification::computeDedupHash($bank, $rawText, $source);

        $notification = PaymentNotification::forceCreate([
            'bank_code' => $bank,
            'raw_text' => $rawText,
            'dedup_hash' => $dedupHash,
            'parse_status' => 'pending',
        ]);

        $this->info("Notification created (ID: {$notification->id})");
        $this->newLine();
        $this->line("  Bank:    {$bank}");
        $this->line("  Amount:  {$amount}");
        $this->line("  Ref:     {$reference}");
        $this->line("  Phone:   {$phone}");
        $this->line("  Hash:    {$dedupHash}");
        $this->newLine();
        $this->line('  Raw text:');
        $this->line("  {$rawText}");

        return Command::SUCCESS;
    }

    /**
     * Generate realistic bank-specific notification text.
     *
     * Based on real notification formats from the plan (section 2.1).
     */
    private function formatNotification(string $bank, string $amount, string $reference, string $phone): string
    {
        $now = Carbon::now();

        return match ($bank) {
            // BDV: Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40
            'bdv' => "Recibiste un PagomovilBDV por Bs. {$amount} del {$phone} Ref: {$reference} en fecha: ".$now->format('d-m-y').' hora: '.$now->format('H:i'),

            // BNC: BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion
            'bnc' => "BNC Pago Movil Recibido Bs.{$amount} Telf.{$phone} Dia:".$now->format('d/m/y').'-'.$now->format('H:i').' Ref:'.$reference.' Llamar al 0500-2625000 si no realizo esta Operacion',
        };
    }
}
