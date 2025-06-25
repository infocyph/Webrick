<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\OpenApi;

use Infocyph\Webrick\Interfaces\RouterInterface;
use Infocyph\Webrick\Router\Attributes\{
    ErrorResponse, RequestSchema, ResponseSchema, Security, Summary, Tag
};
use Psr\Http\Message\UriInterface;
use stdClass;

/**
 * Converts the Router’s route table + attributes into an OpenAPI 3.1 doc.
 */
final class OpenApiGenerator
{
    public function __construct(private RouterInterface $router)
    {
    }

    /**
     * Writes the spec to disk (JSON or YAML, inferred from extension).
     */
    public function write(string $file, UriInterface $base): void
    {
        $doc = $this->spec($base);

        if (preg_match('/\.(ya?ml)$/i', $file)) {
            $yaml = yaml_emit(json_decode(json_encode($doc), true), YAML_UTF8_ENCODING);
            file_put_contents($file, $yaml);
        } else {
            file_put_contents($file, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /* -----------------------------------------------------------------
       Build the stdClass tree
       ----------------------------------------------------------------- */
    public function spec(UriInterface $base): stdClass
    {
        $doc = (object) [
            'openapi' => '3.1.0',
            'info'    => (object) [
                'title'   => 'API',
                'version' => '1.0.0',
            ],
            'servers' => [ (object) ['url' => (string) $base] ],
            'paths'   => new stdClass(),
            'components' => (object) [
                'schemas' => new stdClass(),
            ],
        ];

        /* ---- shared StandardError schema ----------------------- */
        $doc->components->schemas->StandardError = (object) [
            'type'       => 'object',
            'properties' => (object) [
                'error' => (object) [
                    'type'       => 'object',
                    'properties' => (object) [
                        'message' => (object) ['type' => 'string'],
                        'code'    => (object) ['type' => 'integer'],
                    ],
                    'required' => ['message', 'code'],
                ],
            ],
            'required' => ['error'],
        ];

        $routes = (new \ReflectionProperty($this->router, 'routes'))
            ->getValue($this->router)
            ->named();

        $sb = new SchemaBuilder();

        /* ============================================================
           Iterate routes
           ============================================================*/
        foreach ($routes as $name => $route) {
            $method = strtolower($route->getMethod());
            $path   = $route->getPath();
            $op     = (object) [];
            $doc->paths->{$path} ??= new stdClass();
            $doc->paths->{$path}->{$method} = $op;

            /* ---- summary / tags ---- */
            $rm = new \ReflectionMethod(...$route->getHandler());

            if ($sumAttr = $rm->getAttributes(Summary::class)[0] ?? null) {
                $op->summary = $sumAttr->newInstance()->text;
            }
            foreach ($rm->getAttributes(Tag::class) as $t) {
                $op->tags[] = $t->newInstance()->name;
            }

            /* ---- security ---- */
            if ($secAttrs = $rm->getAttributes(Security::class)) {
                foreach ($secAttrs as $sa) {
                    $inst = $sa->newInstance();
                    $op->security[] = (object) [ $inst->scheme => $inst->scopes ];
                }
            }

            /* =======================================================
               Request-body (DTO)
               =======================================================*/
            $reqSchemaAttr = $rm->getAttributes(RequestSchema::class)[0] ?? null;
            if ($reqSchemaAttr) {
                $dto  = $reqSchemaAttr->newInstance()->class;
                $name = (new \ReflectionClass($dto))->getShortName();
                $doc->components->schemas->{$name} ??= $sb->build($dto);

                $op->requestBody = (object) [
                    'required' => true,
                    'content'  => (object) [
                        'application/json' => (object) [
                            'schema' => (object) ['$ref' => "#/components/schemas/{$name}"],
                        ],
                    ],
                ];
            }

            /* =======================================================
               Success response
               =======================================================*/
            $successRef = null;

            /* explicit via attribute */
            if ($respAttr = $rm->getAttributes(ResponseSchema::class)[0] ?? null) {
                $dto = $respAttr->newInstance()->class;
                $name = (new \ReflectionClass($dto))->getShortName();
                $doc->components->schemas->{$name} ??= $sb->build($dto);
                $successRef = "#/components/schemas/{$name}";
            }
            /* implicit via return-type */ elseif ($rt = $rm->getReturnType()) {
                if ($rt instanceof \ReflectionNamedType && !$rt->isBuiltin()) {
                    $dto  = $rt->getName();
                    $name = (new \ReflectionClass($dto))->getShortName();
                    $doc->components->schemas->{$name} ??= $sb->build($dto);
                    $successRef = "#/components/schemas/{$name}";
                }
            }

            $responses = (object) [];
            $op->responses = $responses;

            $responses->{'200'} = $successRef
                ? (object) [
                    'description' => 'Success',
                    'content'     => (object) [
                        'application/json' => (object) [
                            'schema' => (object) ['$ref' => $successRef],
                        ],
                    ],
                ]
                : (object) ['description' => 'Success'];

            /* =======================================================
               Attribute-defined error responses
               =======================================================*/
            foreach ($rm->getAttributes(ErrorResponse::class) as $er) {
                $inst = $er->newInstance();
                $code = (string) $inst->code;
                $dto  = $inst->dto;
                $name = (new \ReflectionClass($dto))->getShortName();
                $doc->components->schemas->{$name} ??= $sb->build($dto);

                $responses->{$code} = (object) [
                    'description' => 'Error',
                    'content'     => (object) [
                        'application/json' => (object) [
                            'schema' => (object) ['$ref' => "#/components/schemas/{$name}"],
                        ],
                    ],
                ];
            }

            /* =======================================================
               Standard Error responses (400,401,403,404,429,500)
               =======================================================*/
            foreach ([400,401,403,404,429,500] as $code) {
                $c = (string) $code;
                if (!isset($responses->{$c})) {
                    $responses->{$c} = (object) [
                        'description' => 'Error',
                        'content'     => (object) [
                            'application/json' => (object) [
                                'schema' => (object) [
                                    '$ref' => '#/components/schemas/StandardError',
                                ],
                            ],
                        ],
                    ];
                }
            }
        }

        /* -----------------------------------------------------------
           Merge schemas collected at runtime (DTORegistry)
           -----------------------------------------------------------*/
        foreach (DTORegistry::dump() as $cls => $schema) {
            $name = (new \ReflectionClass($cls))->getShortName();
            $doc->components->schemas->{$name} ??= $schema;
        }

        return $doc;
    }
}
