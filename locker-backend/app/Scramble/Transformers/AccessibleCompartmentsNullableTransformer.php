<?php

declare(strict_types=1);

namespace App\Scramble\Transformers;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * Ensures nullable timestamps from AccessibleCompartmentsResource are documented as such.
 *
 * JsonResource inference does not propagate nested nullable ISO8601 strings from optional chaining.
 */
final class AccessibleCompartmentsNullableTransformer
{
    public function __invoke(OpenApi $document): void
    {
        foreach ($document->components->schemas as $schema) {
            $root = $schema->type ?? null;
            if (! $root instanceof ObjectType || ! isset($root->properties['locker_banks'])) {
                continue;
            }

            $lockerBanksProp = $root->properties['locker_banks'];
            if (! $lockerBanksProp instanceof ArrayType) {
                continue;
            }

            $bankItem = self::objectItemsOf($lockerBanksProp);
            if ($bankItem === null) {
                continue;
            }

            self::nullableProp($bankItem, 'last_compartment_state_change_at');

            $compartmentsProp = $bankItem->properties['compartments'] ?? null;
            if (! $compartmentsProp instanceof ArrayType) {
                continue;
            }

            $compartmentItem = self::objectItemsOf($compartmentsProp);
            if ($compartmentItem === null) {
                continue;
            }

            self::nullableProp($compartmentItem, 'door_state_changed_at');
            self::nullableProp($compartmentItem, 'content_note');
            self::nullableProp($compartmentItem, 'content_note_updated_at');
            self::nullableProp($compartmentItem, 'content_note_updated_by_user_id');
        }
    }

    private static function objectItemsOf(ArrayType $array): ?ObjectType
    {
        $items = $array->items;

        if ($items instanceof Schema && $items->type instanceof ObjectType) {
            return $items->type;
        }

        if ($items instanceof ObjectType) {
            return $items;
        }

        return null;
    }

    private static function nullableProp(ObjectType $object, string $property): void
    {
        $prop = $object->properties[$property] ?? null;

        if ($prop instanceof Type) {
            $prop->nullable(true);
        }

        if (in_array($property, $object->required, true)) {
            $object->required = array_values(array_diff($object->required, [$property]));
        }
    }
}
