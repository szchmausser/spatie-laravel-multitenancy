<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('landlord')->statement(
            'CREATE UNIQUE INDEX idx_payment_matches_matched '
            .'ON payment_matches (payment_id) WHERE match_status = \'matched\''
        );
    }

    public function down(): void
    {
        DB::connection('landlord')->statement(
            'DROP INDEX IF EXISTS idx_payment_matches_matched'
        );
    }
};
