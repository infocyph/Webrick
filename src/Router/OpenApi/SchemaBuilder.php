<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\OpenApi;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

/**
 * Builds JSON-Schema objects from DTO classes via reflection.
 *
 * • Scalar + array + nested DTO detection
 * • Central enum translation (BackedEnum ⇒ one shared schema)
 * • Recursion-safe (tracks $seen to break cycles)
 */
final class SchemaBuilder
{
    /** @var array<string,true> */
    private array $seen = [];

    public function build(string $class): stdClass
    {
        if (isset($this->seen[$class])) {
            return (object) ['$ref' => "#/components/schemas/" . (new ReflectionClass($class))->getShortName()];
        }
        $this->seen[$class] = true;

        $rc = new ReflectionClass($class);
        $schema             = (object) [
            'type'       => 'object',
            'properties' => new stdClass(),
            'required'   => [],
        ];

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }
            $schema->properties->{$prop->getName()} = $this->typeToSchema($type);
            $schema->required[]                     = $prop->getName();
        }

        unset($this->seen[$class]);
        return $schema;
    }

    /* -----------------------------------------------------------------
       Helpers
       ----------------------------------------------------------------- */
    private function typeToSchema(ReflectionNamedType $t): stdClass
    {
        /* built-in scalars */
        if ($t->isBuiltin()) {
            return (object) ['type' => match ($t->getName()) {
                'int'    => 'integer',
                'float'  => 'number',
                'string' => 'string',
                'bool'   => 'boolean',
                'array'  => 'array',
                default  => 'string',
            }];
        }

        /* BackedEnum ⇒ shared components entry */
        if (is_subclass_of($t->getName(), BackedEnum::class)) {
            $enumClass = $t->getName();
            $enumName  = (new ReflectionClass($enumClass))->getShortName();

            DTORegistry::add(
                $enumClass,
                (object) [
                    'type' => 'string',
                    'enum' => array_map(fn ($c) => $c->value, $enumClass::cases()),
                ]
            );

            return (object) ['$ref' => "#/components/schemas/{$enumName}"];
        }

        /* nested DTO */
        return (object) [
            '$ref' => "#/components/schemas/" .
                (new ReflectionClass($t->getName()))->getShortName(),
        ];
    }
}
