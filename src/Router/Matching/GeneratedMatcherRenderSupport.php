<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;

final class GeneratedMatcherRenderSupport
{
    /**
     * @param list<array{type:'lit',val:string}|array{type:'var',name:string,regex?:string,call?:callable-string}> $segments
     */
    public static function renderDynamicEntryCondition(array $segments, string $indent): string
    {
        $checks = [];
        foreach ($segments as $i => $part) {
            if ($part['type'] === 'lit') {
                $checks[] = "(\$segments[{$i}] ?? null) === " . \var_export($part['val'], true);

                continue;
            }

            if (isset($part['regex'])) {
                $checks[] = '\\preg_match(' . \var_export($part['regex'], true) . ", (string)(\$segments[{$i}] ?? '')) === 1";

                continue;
            }

            if (isset($part['call'])) {
                $checks[] = '\\call_user_func(' . \var_export($part['call'], true) . ", (string)(\$segments[{$i}] ?? ''))";
            }
        }

        return $checks === []
            ? 'true'
            : \implode(" &&\n" . $indent . '            ', $checks);
    }

    /**
     * @param array<string,int> $verbs
     */
    public static function renderVerbDispatch(array $verbs, string $indent): string
    {
        $firstIdx = (int) \reset($verbs);
        $code = $indent . "switch (\$verb) {\n";
        foreach ($verbs as $method => $idx) {
            $code .= $indent . '    case ' . \var_export($method, true) . ":\n";
            $code .= $indent . "        return ['hit' => \$routes[{$idx}], 'params' => \$params, 'allowed' => []];\n";
        }

        if (!isset($verbs[HttpMethodEnum::HEAD->value]) && isset($verbs[HttpMethodEnum::GET->value])) {
            $getIdx = $verbs[HttpMethodEnum::GET->value];
            $code .= $indent . '    case ' . \var_export(HttpMethodEnum::HEAD->value, true) . ":\n";
            $code .= $indent . "        return ['hit' => \$routes[{$getIdx}], 'params' => \$params, 'allowed' => []];\n";
        }

        $code .= $indent . '    case ' . \var_export(HttpMethodEnum::OPTIONS->value, true) . ":\n";
        $code .= $indent . "        return ['hit' => \$routes[{$firstIdx}], 'params' => \$params, 'allowed' => []];\n";
        $code .= $indent . "    default:\n";
        foreach ($verbs as $method => $_idx) {
            $code .= $indent . '        $allowed[' . \var_export($method, true) . "] = true;\n";
        }
        if (isset($verbs[HttpMethodEnum::GET->value])) {
            $code .= $indent . '        $allowed[' . \var_export(HttpMethodEnum::HEAD->value, true) . "] = true;\n";
        }
        $code .= $indent . "        break;\n";

        return $code . ($indent . "}\n");
    }
}
