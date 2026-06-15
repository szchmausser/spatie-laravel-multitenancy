<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethodConfig;
use Illuminate\Support\Facades\DB;

class BankTransferGateway implements PaymentGatewayInterface
{
    /**
     * Record a bank transfer payment for an order.
     *
     * Creates both the Payment (supertipo) and BankTransferDetail (subtipo)
     * in a single database transaction to guarantee atomicity.
     *
     * The receiving account is resolved from the PaymentMethodConfig table
     * using payment_method_config_id when provided.
     *
     * @param  array{
     *     amount_cents: int,
     *     payment_method_config_id: ?int,
     *     sender_bank: string,
     *     sender_name: string,
     *     sender_id: string,
     *     sender_account_number: ?string,
     *     tenant_rif: ?string,
     *     payment_date: string,
     *     concept: ?string,
     *  }  $data
     */
    public function recordPayment(Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($order, $data) {
            $payment = Payment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount_cents' => $data['amount_cents'],
                'currency' => 'VES',
                'payment_method' => 'bank_transfer',
                'payment_method_config_id' => $data['payment_method_config_id'] ?? null,
                'status' => PaymentStatus::Pending,
            ]);

            $config = $this->resolveReceivingAccount($data['payment_method_config_id'] ?? null);

            $payment->bankTransferDetail()->create([
                'account_number' => $config['account_number'],
                'bank_name' => $config['bank_name'],
                'account_holder' => $config['account_holder'],
                'holder_id' => $config['holder_id'],
                'sender_bank' => $data['sender_bank'],
                'sender_name' => $data['sender_name'],
                'sender_id' => $data['sender_id'],
                'sender_account_number' => $data['sender_account_number'] ?? null,
                'tenant_rif' => $data['tenant_rif'] ?? null,
                'payment_date' => $data['payment_date'],
                'concept' => $data['concept'] ?? null,
            ]);

            return $payment;
        });
    }

    /**
     * Resolve receiving account details for Bank Transfer.
     *
     * Looks up the PaymentMethodConfig by ID. Throws if not found
     * because bank_transfer always requires an explicit config.
     */
    private function resolveReceivingAccount(?int $configId): array
    {
        if ($configId === null) {
            abort(422, 'payment_method_config_id is required for bank_transfer payments.');
        }

        $config = PaymentMethodConfig::query()
            ->where('id', $configId)
            ->where('type', 'bank_transfer')
            ->first();

        if (! $config) {
            abort(422, 'Invalid bank transfer configuration.');
        }

        return [
            'account_number' => $config->account_number,
            'bank_name' => $config->bank_name,
            'account_holder' => $config->account_holder,
            'holder_id' => $config->holder_id,
        ];
    }

    /**
     * Get display instructions for Bank Transfer.
     *
     * Returns the destination account number, bank name, account holder,
     * and holder ID so the frontend can show the tenant where to send the payment.
     */
    public function getInstructions(Payment $payment): array
    {
        $detail = $payment->bankTransferDetail;

        return [
            'type' => 'bank_transfer',
            'title' => 'Transferencia Bancaria',
            'fields' => [
                ['label' => 'Banco', 'value' => $detail->bank_name],
                ['label' => 'Cuenta', 'value' => $detail->account_number],
                ['label' => 'Titular', 'value' => $detail->account_holder],
                ['label' => 'RIF/Cédula', 'value' => $detail->holder_id],
            ],
            'amount' => $payment->amount_cents,
        ];
    }
}
