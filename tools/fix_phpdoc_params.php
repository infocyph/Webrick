<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return list<string> */
function phpFiles(string $root): array
{
    $files = [];
    foreach (['benchmark', 'benchmarks', 'src'] as $directory) {
        $path = $root . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    foreach (['routes.php', 'index.php'] as $rootFile) {
        $path = $root . DIRECTORY_SEPARATOR . $rootFile;
        if (is_file($path)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

function nativeDocType(Node $type): string
{
    if ($type instanceof Node\Identifier) {
        return $type->name;
    }
    if ($type instanceof Node\Name) {
        return $type->toString();
    }
    if ($type instanceof Node\NullableType) {
        return '?' . nativeDocType($type->type);
    }
    if ($type instanceof Node\IntersectionType) {
        return implode('&', array_map(nativeDocType(...), $type->types));
    }
    if ($type instanceof Node\UnionType) {
        $parts = [];
        foreach ($type->types as $part) {
            $rendered = nativeDocType($part);
            $parts[] = $part instanceof Node\IntersectionType ? '(' . $rendered . ')' : $rendered;
        }

        return implode('|', $parts);
    }

    throw new RuntimeException('Unsupported native parameter type: ' . $type::class);
}

/** @param list<string> $tags */
function addParamTags(string $doc, string $indent, array $tags): string
{
    if ($tags === []) {
        return $doc;
    }

    if (!str_contains($doc, "\n")) {
        $inner = trim(substr($doc, 3, -2));
        $lines = ["/**"];
        if ($inner !== '') {
            $lines[] = $indent . ' * ' . $inner;
        }
        foreach ($tags as $tag) {
            $lines[] = $indent . ' * ' . $tag;
        }
        $lines[] = $indent . ' */';

        return implode("\n", $lines);
    }

    $closing = strrpos($doc, '*/');
    if ($closing === false) {
        throw new RuntimeException('Malformed PHPDoc block.');
    }

    $prefix = rtrim(substr($doc, 0, $closing));
    foreach ($tags as $tag) {
        $prefix .= "\n" . $indent . ' * ' . $tag;
    }

    return $prefix . "\n" . $indent . ' */';
}

$root = dirname(__DIR__);
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$updated = 0;

foreach (phpFiles($root) as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) {
        throw new RuntimeException("Unable to read {$file}");
    }

    $nodes = $parser->parse($source);
    if ($nodes === null) {
        continue;
    }

    /** @var list<array{start:int,end:int,replacement:string}> $replacements */
    $replacements = [];
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new class($source, $replacements) extends NodeVisitorAbstract {
        /** @param list<array{start:int,end:int,replacement:string}> $replacements */
        public function __construct(
            private readonly string $source,
            private array &$replacements,
        ) {}

        public function enterNode(Node $node): null
        {
            if (!$node instanceof Stmt\Function_ && !$node instanceof Stmt\ClassMethod) {
                return null;
            }

            $doc = $node->getDocComment();
            if ($doc === null || $node->getParams() === []) {
                return null;
            }

            $text = $doc->getText();
            preg_match_all('/@param\b[^\r\n]*\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches);
            $documented = array_fill_keys($matches[1] ?? [], true);
            $tags = [];

            foreach ($node->getParams() as $param) {
                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }
                $name = $param->var->name;
                if (isset($documented[$name])) {
                    continue;
                }

                $type = $param->type instanceof Node ? nativeDocType($param->type) : 'mixed';
                $variable = ($param->variadic ? '...' : '') . '$' . $name;
                $tags[] = '@param ' . $type . ' ' . $variable;
            }

            if ($tags === []) {
                return null;
            }

            $start = $doc->getStartFilePos();
            $end = $doc->getEndFilePos();
            if ($start < 0 || $end < $start) {
                throw new RuntimeException('PHP parser did not expose PHPDoc file positions.');
            }

            $lineStart = strrpos(substr($this->source, 0, $start), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $indent = substr($this->source, $lineStart, $start - $lineStart);
            $this->replacements[] = [
                'start' => $start,
                'end' => $end,
                'replacement' => addParamTags($text, $indent, $tags),
            ];

            return null;
        }
    });
    $traverser->traverse($nodes);

    if ($replacements === []) {
        continue;
    }

    usort($replacements, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
    foreach ($replacements as $replacement) {
        $source = substr($source, 0, $replacement['start'])
            . $replacement['replacement']
            . substr($source, $replacement['end'] + 1);
    }

    if (file_put_contents($file, $source) === false) {
        throw new RuntimeException("Unable to write {$file}");
    }
    ++$updated;
}

fwrite(STDOUT, "Updated {$updated} PHP file(s).\n");
