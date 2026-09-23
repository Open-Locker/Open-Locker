<?php

declare(strict_types=1);

use App\Support\Organizations\DefaultOrganization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of what an operator owns.
 *
 * Groups are organization-owned data, but they are deliberately not the
 * tenancy mechanism: a group says who may reach which compartments inside one
 * operator, an organization is the boundary between operators.
 *
 * Terms are per operator, and so is acceptance — a person who belongs to two
 * accepts two documents and may have accepted one and not the other.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'groups',
        'terms_documents',
        'compartment_accesses',
        'compartment_open_requests',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignUuid('organization_id')->nullable()
                    ->after($table === 'groups' ? 'id' : 'id')
                    ->constrained()->cascadeOnDelete();
            });
        }

        $organizationId = DefaultOrganization::id();

        if ($organizationId !== null) {
            foreach (self::TABLES as $table) {
                DB::table($table)->whereNull('organization_id')->update(['organization_id' => $organizationId]);
            }
        }

        // Left nullable deliberately: unlike locker banks, these tables are
        // written by projectors replaying historical events, which have no
        // organization of their own. The projector resolves the default
        // organization, and NOT NULL here would turn a replay into a failure
        // before that resolution could happen.
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('organization_id'));
        }
    }
};
