<?php

namespace Battis\OpenAPI\Generator\Classes;

use Battis\DataUtilities\Path;
use Battis\DataUtilities\Text;
use Battis\OpenAPI\CLI\Logger;
use Battis\OpenAPI\Client\Exceptions\ArgumentException;
use Battis\OpenAPI\Generator\Exceptions\GeneratorException;
use Battis\OpenAPI\Generator\Exceptions\SchemaException;
use Battis\OpenAPI\Generator\Mappers\EndpointMapper;
use Battis\OpenAPI\Generator\Sanitize;
use Battis\OpenAPI\Generator\TypeMap;
use Battis\PHPGenerator\Method;
use Battis\PHPGenerator\Method\Parameter;
use Battis\PHPGenerator\Method\ReturnType;
use Battis\PHPGenerator\Property;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter as SpecParameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;

class Endpoint extends Writable
{
    protected string $url = "";

    public static function fromPathItem(
        string $path,
        PathItem $pathItem,
        EndpointMapper $mapper,
        string $url
    ): Endpoint {
        $typeMap = TypeMap::getInstance();
        $sanitize = Sanitize::getInstance();

        $class = new Endpoint();
        $class->description = $sanitize->stripHtml($pathItem->description);
        $class->baseType = $mapper->getBaseType();
        $class->addProperty(
            Property::protected("url", "string", "Endpoint URL pattern", "\"$url\"")
        );
        $class->url = $url;
        $class->path = static::normalizePath($path);
        $class->name = $sanitize->clean(basename($class->path));
        $dir = dirname($class->path);
        if ($dir === "." || $dir === "/") {
            $dir = null;
        }
        $class->namespace = Path::join("\\", [
          $mapper->getBaseNamespace(),
          $dir === null ? [] : explode("/", $dir),
        ]);

        preg_match_all("/\{([^}]+)\}\//", $class->url, $match, PREG_PATTERN_ORDER);
        $operationSuffix = Text::snake_case_to_PascalCase(
            (!empty($match[1]) ? "by_" : "") .
            join(
                "_and_",
                array_map(fn(string $p) => str_replace("_id", "", $p), $match[1])
            )
        );

        foreach ($mapper->supportedOperations() as $operation) {
            if ($pathItem->$operation) {
                Logger::log(strtoupper($operation) . " " . $url);

                $instantiate = false;

                /** @var \cebe\openapi\spec\Operation $op */
                $op = $pathItem->$operation;
                assert(
                    $op->responses !== null,
                    new SchemaException("$operation $url has no responses")
                );

                $parameters = self::methodParameters($op);

                $requestBody = $op->requestBody;
                assert($requestBody === null || $requestBody instanceof RequestBody, new GeneratorException('Not ready to handle schema ref for request body'));
                if ($requestBody !== null) {
                    $docType = null;
                    /** @psalm-suppress MixedAssignment we'll figure it out in a sec */
                    $schema =
                      $requestBody->content[$mapper->expectedContentType()]->schema;
                    assert($schema !== null, new SchemaException('Missing schema for response'));
                    if ($schema instanceof Reference) {
                        $type = $typeMap->getTypeFromSchema($schema->getReference());
                        assert($type !== null, new GeneratorException('Could not resolve type for request body'));
                        $class->addUses($type);
                    } else {
                        $method = $schema->type;
                        $type = $schema->type;
                        /** @var string $docType */
                        $docType = $typeMap->$method($schema, true);
                    }
                    $requestBody = Parameter::from(
                        "requestBody",
                        $type,
                        $requestBody->description
                    );
                    if ($docType !== null) {
                        $requestBody->setDocType($docType);
                    }
                }

                // return type
                $responses = $op->responses;
                /** @var ?\cebe\openapi\spec\Response $resp */
                $resp = is_array($responses)
                  ? $responses["200"] ?? $responses["201"]
                  : $responses->getResponse("200") ?? $responses->getResponse("201");
                assert(
                    $resp !== null,
                    new SchemaException("$operation $url has no OK response")
                );
                $content = $resp->content;
                $content = $content[$mapper->expectedContentType()] ?? null;
                $type = null;
                if ($content !== null) {
                    $schema = $content->schema;
                    if ($schema instanceof Reference) {
                        $ref = $schema->getReference();
                        $type = $typeMap->getTypeFromSchema($ref);
                        assert($type !== null, new GeneratorException('Could not resolve type for response'));
                        $class->addUses($type);
                        $instantiate = true;
                    } elseif ($schema instanceof Schema) {
                        $method = $schema->type;
                        $type = (string) $typeMap->$method($schema);
                        $t = substr($type, 0, -2);
                        $instantiate =
                          substr($type, -2) === "[]" &&
                          $typeMap->getClassFromType($t) !== null;
                        if ($instantiate) {
                            $class->addUses($t);
                        }
                    }
                } else {
                    $type = "void";
                }
                assert(is_string($type), new GeneratorException("type undefined"));

                $pathArg =
                  "[" .
                  join(
                      "," . PHP_EOL,
                      array_map(
                          fn(Parameter $p) => "\"{" .
                          $p->getName() .
                          "}\" => \$" .
                          $p->getName(),
                          $parameters["path"]
                      )
                  ) .
                  "]";
                $queryArg =
                  "[" .
                  join(
                      "," . PHP_EOL,
                      array_map(
                          fn(Parameter $p) => "\"" .
                          $p->getName() .
                          "\" => $" .
                          $p->getName(),
                          $parameters["query"]
                      )
                  ) .
                  "]";

                $body =
                  "return " .
                  self::instantiate(
                      $instantiate,
                      $type,
                      "\$this->send(\"$operation\", $pathArg, $queryArg" .
                      ($requestBody !== null ? ", $" . $requestBody->getName() : "") .
                      ")"
                  ) .
                  ";";

                if ($operation === "get") {
                    if (count($parameters["path"]) === 0) {
                        if (count($parameters["query"]) === 0) {
                            $operation .= "All";
                        } else {
                            $operation = "filterBy";
                        }
                    }
                }

                $params = array_merge($parameters["path"], $parameters["query"]);
                if ($requestBody !== null) {
                    assert(
                        !in_array(
                            $requestBody->getName(),
                            array_map(fn(Parameter $p) => $p->getName(), $params)
                        ),
                        new GeneratorException(
                            "requestBody already exists as path or query parameter"
                        )
                    );
                    $params[] = $requestBody;
                }
                $assertions = [];
                foreach ($params as $param) {
                    if (!$param->isOptional()) {
                        $assertions[] =
                          "assert(\$" .
                          $param->getName() .
                          " !== null, new ArgumentException(\"Parameter `" .
                          $param->getName() .
                          "` is required\"));" .
                          PHP_EOL;
                        $class->uses[] = ArgumentException::class;
                    }
                }
                $assertions = join($assertions);
                $throws = [];
                if (!empty($assertions)) {
                    $body = $assertions . PHP_EOL . $body;
                    $throws[] = ReturnType::from(
                        ArgumentException::class,
                        "if required parameters are not defined"
                    );
                }

                $docType = null;
                if (substr($type, -2) === "[]") {
                    $docType = $type;
                    $type = "array";
                }
                $returnType = ReturnType::from($type, $sanitize->stripHtml($resp->description), $docType);

                $method = Method::public(
                    $operation . $operationSuffix,
                    $returnType,
                    $body,
                    $op->description,
                    $params,
                    $throws
                );
                $class->addMethod($method);
            }
        }
        return $class;
    }

    protected static function instantiate(
        bool $instantiate,
        string $type,
        string $arg
    ): string {
        if ($instantiate) {
            if (substr($type, -2) === "[]") {
                return "array_map(fn(\$a) => new " .
                  Property::typeAs(substr($type, 0, -2), Property::TYPE_SHORT) .
                  "(\$a), {$arg})";
            } else {
                return "new " .
                  Property::typeAs($type, Property::TYPE_SHORT) .
                  "(" .
                  $arg .
                  ")";
            }
        } else {
            return $arg;
        }
    }

    /**
     * Parse parameter information from an operation
     *
     * @param \cebe\openapi\spec\Operation $operation
     *
     * @return array{path: Parameter[], query: Parameter[]}
     */
    protected static function methodParameters(Operation $operation): array
    {
        $typeMap = TypeMap::getInstance();
        $parameters = [
          "path" => [],
          "query" => [],
        ];
        foreach ($operation->parameters as $parameter) {
            assert($parameter instanceof SpecParameter, new GeneratorException('Not ready to deal with Parameters that are schema refs'));
            if ($parameter->schema instanceof Reference) {
                $ref = $parameter->schema->getReference();
                $parameterType = $typeMap->getTypeFromSchema($ref);
            } else {
                assert($parameter->schema !== null, new SchemaException("no schema provided for parameter"));
                $method = $parameter->schema->type;
                /** @var ?class-string<\Battis\OpenAPI\Client\Mappable> */
                $parameterType = $typeMap->$method($parameter);
            }
            assert($parameterType !== null, new GeneratorException('could not resolve parameter type'));
            if ($parameter->in === "path") {
                $parameters["path"][] = Parameter::from(
                    $parameter->name,
                    $parameterType,
                    ($parameter->required ? "" : "(Optional) ") . $parameter->description,
                    !$parameter->required
                );
            } elseif ($parameter->in === "query") {
                $parameters["query"][] = Parameter::from(
                    $parameter->name,
                    $parameterType,
                    ($parameter->required ? "" : "(Optional) ") . $parameter->description,
                    !$parameter->required
                );
            }
        }
        return $parameters;
    }

    /**
     * Calculate a "normalized" path to the directory containing the class
     * based on its URL
     *
     * The process removes all path parameters from the url:
     * `/foo/{foo_id}/bar/{bar_id}/{baz}` would normalize to `/foo` for a
     * class named `Bar`.
     *
     * @param string $path
     *
     * @return string
     */
    protected static function normalizePath(string $path): string
    {
        $parts = explode("/", $path);
        $namespaceParts = [];
        foreach ($parts as $part) {
            if (preg_match("/\{([^}]+)\}/", $part, $match)) {
            } else {
                $namespaceParts[] = Text::snake_case_to_PascalCase(
                    Text::camelCase_to_snake_case($part)
                );
            }
        }
        return (substr($path, 0, 1) === "/" ? "/" : "") .
          join("/", $namespaceParts);
    }
}
