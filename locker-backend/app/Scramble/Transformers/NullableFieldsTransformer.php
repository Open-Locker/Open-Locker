<?php

namespace App\Scramble\Transformers;

use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\OpenApi\SchemaObject;

class NullableFieldsTransformer {

    public function __invoke( OpenApi $document ): void {
        foreach ( $document->components->schemas as $schema ) {
            $type = $schema->type;
            if ( ! $type instanceof ObjectType ) {
                continue;
            }

            $required   = $type->required;
            $properties = $type->properties;

            $nullable = [];
            if ( is_array( $required ) ) {
                foreach ( $required as $fieldName ) {
                    if ( isset( $properties[ $fieldName ] ) && $properties[ $fieldName ]->nullable ) {
                        $nullable[] = $fieldName;
                    }
                }
            }
            if ( count( $nullable ) > 0 ) {
                $required       = array_diff( $required, $nullable );
                $type->required = $required;
            }
        }
    }
}
