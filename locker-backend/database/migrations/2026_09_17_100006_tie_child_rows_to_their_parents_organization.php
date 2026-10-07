<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the composite-foreign-key rule past compartments.
 *
 * The scope decides what a query returns; these decide what the database will
 * accept. A grant, a group grant or an open request cannot point at a
 * compartment owned by a different organization — not merely "is not queried",
 * but cannot be written.
 */
return new class extends Migration
{
    /** @var array<string, string> child table => column referencing compartments.id */
    private const CHILDREN = [
        'compartment_accesses' => 'compartment_id',
        'compartment_open_requests' => 'compartment_id',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE compartments ADD CONSTRAINT compartments_id_organization_id_unique UNIQUE (id, organization_id)');

        foreach (self::CHILDREN as $table => $column) {
            // Rows predating organizations are stamped by their projector on
            // replay; until then a null organization simply leaves the
            // constraint unenforced for that row rather than blocking the
            // migration.
            DB::table($table)
                ->whereNull('organization_id')
                ->update([
                    'organization_id' => DB::raw(
                        "(SELECT c.organization_id FROM compartments c WHERE c.id = {$table}.{$column})"
                    ),
                ]);

            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_compartment_same_organization_foreign
                FOREIGN KEY ({$column}, organization_id)
                REFERENCES compartments (id, organization_id)
                ON DELETE CASCADE
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (array_keys(self::CHILDREN) as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_compartment_same_organization_foreign");
        }

        DB::statement('ALTER TABLE compartments DROP CONSTRAINT IF EXISTS compartments_id_organization_id_unique');
    }
};
