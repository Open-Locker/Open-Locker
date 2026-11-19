<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Emails are stored lowercase from now on (#371). Accounts that differ only
     * in casing would collide on the unique index, and merging them is a manual
     * decision, so the migration stops instead of picking one.
     */
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('LOWER(email) AS normalized_email')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_email');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot lowercase user emails: several accounts differ only in casing (%s). Merge or rename them, then run the migration again.',
                $duplicates->implode(', '),
            ));
        }

        DB::table('users')->update(['email' => DB::raw('LOWER(email)')]);
    }

    public function down(): void
    {
        // The original casing is not kept; lowercase emails stay valid.
    }
};
