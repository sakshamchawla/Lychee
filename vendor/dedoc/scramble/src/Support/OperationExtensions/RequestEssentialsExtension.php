<?php

namespace Dedoc\Scramble\Support\OperationExtensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Infer\Infer;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\ServerFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class RequestEssentialsExtension extends OperationExtension
{
    private OpenApi $openApi;

    private ServerFactory $serverFactory;

    public function __construct(
        Infer $infer,
        TypeTransformer $openApiTransformer,
        OpenApi $openApi,
        ServerFactory $serverFactory
    ) {
        parent::__construct($infer, $openApiTransformer);
        $this->openApi = $openApi;
        $this->serverFactory = $serverFactory;
    }

    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        [$pathParams, $pathAliases] = $this->getRoutePathParameters($routeInfo->route, $routeInfo->phpDoc());

        $operation
            ->setMethod(strtolower($routeInfo->route->methods()[0]))
            ->setPath(Str::replace(
                collect($pathAliases)->keys()->map(fn ($k) => '{'.$k.'}')->all(),
                collect($pathAliases)->values()->map(fn ($v) => '{'.$v.'}')->all(),
                $routeInfo->route->uri,
            ))
            ->setTags([
                ...$this->extractTagsForMethod($routeInfo->class->phpDoc()),
                Str::of(class_basename($routeInfo->className()))->replace('Controller', ''),
            ])
            ->servers($this->getAlternativeServers($routeInfo->route))
            ->addParameters($pathParams);

        if (count($routeInfo->phpDoc()->getTagsByName('@unauthenticated'))) {
            $operation->addSecurity([]);
        }
    }

    /**
     * Checks if route domain needs to have alternative servers defined. Route needs to have alternative servers defined if
     * the route has not matching domain to any servers in the root.
     *
     * Domain is matching if all the server variables matching.
     */
    private function getAlternativeServers(Route $route)
    {
        if (! $route->getDomain()) {
            return [];
        }

        [$protocol] = explode('://', url('/'));
        $expectedServer = $this->serverFactory->make($protocol.'://'.$route->getDomain().'/'.config('scramble.api_path', 'api'));

        if ($this->isServerMatchesAllGivenServers($expectedServer, $this->openApi->servers)) {
            return [];
        }

        $matchingServers = collect($this->openApi->servers)->filter(fn (Server $s) => $this->isMatchingServerUrls($expectedServer->url, $s->url));
        if ($matchingServers->count()) {
            return $matchingServers->values()->toArray();
        }

        return [$expectedServer];
    }

    private function isServerMatchesAllGivenServers(Server $expectedServer, array $actualServers)
    {
        return collect($actualServers)->every(fn (Server $s) => $this->isMatchingServerUrls($expectedServer->url, $s->url));
    }

    private function isMatchingServerUrls(string $expectedUrl, string $actualUrl)
    {
        $mask = function (string $url) {
            [, $urlPart] = explode('://', $url);
            [$domain, $path] = count($parts = explode('/', $urlPart, 2)) !== 2 ? [$parts[0], ''] : $parts;

            $params = Str::of($domain)->matchAll('/\{(.*?)\}/');

            return $params->join('.').'/'.$path;
        };

        return $mask($expectedUrl) === $mask($actualUrl);
    }

    private function extractTagsForMethod(PhpDocNode $classPhpDoc)
    {
        if (! count($tagNodes = $classPhpDoc->getTagsByName('@tags'))) {
            return [];
        }

        return explode(',', $tagNodes[0]->value->value);
    }

    private function getParametersFromString(?string $str)
    {
        return Str::of($str)->matchAll('/\{(.*?)\}/')->values()->toArray();
    }

    private function getRoutePathParameters(Route $route, ?PhpDocNode $methodPhpDocNode)
    {
        $paramNames = $route->parameterNames();
        $paramsWithRealNames = ($reflectionParams = collect($route->signatureParameters())
            ->filter(function (\ReflectionParameter $v) {
                if (($type = $v->getType()) && $typeName = $type->getName()) {
                    if (is_a($typeName, Request::class, true)) {
                        return false;
                    }
                }

                return true;
            })
            ->values())
            ->map(fn (\ReflectionParameter $v) => $v->name)
            ->all();

        if (count($paramNames) !== count($paramsWithRealNames)) {
            $paramsWithRealNames = $paramNames;
        }

        $aliases = collect($paramNames)->mapWithKeys(fn ($name, $i) => [$name => $paramsWithRealNames[$i]])->all();

        $reflectionParamsByKeys = $reflectionParams->keyBy->name;
        $phpDocTypehintParam = $methodPhpDocNode
            ? collect($methodPhpDocNode->getParamTagValues())->keyBy(fn (ParamTagValueNode $n) => Str::replace('$', '', $n->parameterName))
            : collect();

        /*
         * Figure out param type based on importance priority:
         * 1. Typehint (reflection)
         * 2. PhpDoc Typehint
         * 3. String (?)
         */
        $params = array_map(function (string $paramName) use ($aliases, $reflectionParamsByKeys, $phpDocTypehintParam) {
            $paramName = $aliases[$paramName];

            $description = '';
            $type = null;

            if (isset($reflectionParamsByKeys[$paramName]) || isset($phpDocTypehintParam[$paramName])) {
                /** @var ParamTagValueNode $docParam */
                if ($docParam = $phpDocTypehintParam[$paramName] ?? null) {
                    if ($docType = $docParam->type) {
                        $type = (string) $docType;
                    }
                    if ($docParam->description) {
                        $description = $docParam->description;
                    }
                }

                if (
                    ($reflectionParam = $reflectionParamsByKeys[$paramName] ?? null)
                    && ($reflectionParam->hasType())
                ) {
                    /** @var \ReflectionParameter $reflectionParam */
                    $type = $reflectionParam->getType()->getName();
                }
            }

            $schemaTypesMap = [
                'int' => new IntegerType(),
                'float' => new NumberType(),
                'string' => new StringType(),
                'bool' => new BooleanType(),
            ];
            $schemaType = $type ? ($schemaTypesMap[$type] ?? new IntegerType) : new StringType;

            if ($type && ! isset($schemaTypesMap[$type]) && $description === '') {
                $description = 'The '.Str::of($paramName)->kebab()->replace(['-', '_'], ' ').' ID';

                $schemaType->setAttribute('isModelId', true);
            }

            return Parameter::make($paramName, 'path')
                ->description($description)
                ->setSchema(Schema::fromType($schemaType));
        }, array_values(array_diff($route->parameterNames(), $this->getParametersFromString($route->getDomain()))));

        return [$params, $aliases];
    }
}
