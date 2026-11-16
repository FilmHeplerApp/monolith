<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReconstitutionBoundaryTest extends TestCase
{
    private const string METHOD = 'reconstitute';
    private const string ALLOWED_DIRECTORY = 'app/Infrastructure/Persistence/';


    #[Test]
    public function reconstitute_is_called_only_from_persistence_adapters(): void
    {
        $root = dirname(__DIR__, 3);
        $allowedCalls = 0;
        $violations = [];

        foreach ($this->phpFiles($root.'/app') as $file) {
            $relativePath = substr($file->getPathname(), strlen($root) + 1);
            $calls = $this->countStaticCalls((string) file_get_contents($file->getPathname()));

            if ($calls === 0) {
                continue;
            }

            if (str_starts_with($relativePath, self::ALLOWED_DIRECTORY)) {
                $allowedCalls += $calls;

                continue;
            }

            $violations[] = $relativePath;
        }

        self::assertSame([], $violations, 'reconstitute() may be called only from '.self::ALLOWED_DIRECTORY);
        self::assertGreaterThan(0, $allowedCalls, 'The scan must find the repository calls; otherwise it checks nothing.');
    }


    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    private function countStaticCalls(string $source): int
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $calls = 0;

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;

            if (is_array($token) && $token[0] === T_DOUBLE_COLON
                && is_array($next) && $next[0] === T_STRING && $next[1] === self::METHOD) {
                $calls++;
            }
        }

        return $calls;
    }
}
