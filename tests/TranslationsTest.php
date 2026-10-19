<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase
{
    public function test_every_language_has_the_english_keys_in_order(): void
    {
        $languages = array_diff(array_map(basename(...), glob(__DIR__ . '/../lang/*', GLOB_ONLYDIR) ?: []), ['en']);
        $this->assertNotEmpty($languages);

        foreach (glob(__DIR__ . '/../lang/en/*.php') ?: [] as $path) {
            $english = require $path;

            foreach ($languages as $language) {
                $where = "lang/{$language}/" . basename($path);
                $this->assertFileExists(__DIR__ . "/../{$where}");
                $lines = require __DIR__ . "/../{$where}";

                $this->assertSame(array_keys($english), array_keys($lines), $where);

                foreach ($lines as $key => $line) {
                    $this->assertNotSame('', trim($line), "Empty: {$key} in {$where}");
                }
            }
        }
    }
}
