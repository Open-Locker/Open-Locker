<?php

declare(strict_types=1);

use App\Support\Organizations\DefaultOrganization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two halves of "enforced twice" that were still missing.
 *
 * Group membership and group compartment grants carried no organization at all,
 * so a cross-organization group grant was physically writable — and group
 * membership confers compartment access.
 *
 * The tables added earlier were left nullable so replay could stamp them. A
 * null component silently disables a composite foreign key under the default
 * MATCH SIMPLE, so those rows had neither the scope nor the constraint. They are
 * backfilled and made binding here, now that every writer supplies one.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> table => [parent table, referencing column] */
    private const GROUP_CHILDREN = [
        'group_user' => ['groups', 'group_id'],
        'group_compartment_accesses' => ['groups', 'group_id'],
    ];

    /** @var list<string> */
    private const NULLABLE_TABLES = [
        'groups',
        'terms_documents',
        'compartment_accesses',
        'compartment_open_requests',
    ];

    public function up(): void
    {
        foreach (array_keys(self::GROUP_CHILDREN) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignUuid('organization_id')->nullable()
                    ->after(self::GROUP_CHILDREN[$table][1])
                    ->constrained()->cascadeOnDelete();
            });

            DB::table($table)->whereNull('organization_id')->update([
                'organization_id' => DB::raw(
                    "(SELECT g.organization_id FROM groups g WHERE g.id = {$table}.group_id)"
                ),
            ]);
        }

        $default = DefaultOrganization::id();

        foreach ([...self::NULLABLE_TABLES, ...array_keys(self::GROUP_CHILDREN)] as $table) {
            if ($default !== null) {
                DB::table($table)->whereNull('organization_id')->update(['organization_id' => $default]);
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('organization_id')->nullable(false)->change();
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_id_organization_id_unique UNIQUE (id, organization_id)');

        foreach (self::GROUP_CHILDREN as $table => [$parent, $column]) {
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_group_same_organization_foreign
                FOREIGN KEY ({$column}, organization_id)
                REFERENCES {$parent} (id, organization_id)
                ON DELETE CASCADE
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            foreach (array_keys(self::GROUP_CHILDREN) as $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_group_same_organization_foreign");
            }

            DB::statement('ALTER TABLE groups DROP CONSTRAINT IF EXISTS groups_id_organization_id_unique');
        }

        foreach (self::NULLABLE_TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->uuid('organization_id')->nullable()->change());
        }

        foreach (array_keys(self::GROUP_CHILDREN) as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('organization_id'));
        }
    }
};
