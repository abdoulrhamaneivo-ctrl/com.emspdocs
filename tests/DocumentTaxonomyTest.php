<?php

use PHPUnit\Framework\TestCase;

final class DocumentTaxonomyTest extends TestCase
{
    public function testCollectIntIdsFiltersDuplicatesAndInvalidValues(): void
    {
        self::assertSame([4, 9], emsp_collect_int_ids(['4', '0', '-2', '4', 9]));
    }

    public function testNormalizeTaxonomyLabelRemovesAccentsAndExtraSpaces(): void
    {
        self::assertSame('comptabilite generale', emsp_normalize_taxonomy_label('  Comptabilité   générale  '));
    }
}
