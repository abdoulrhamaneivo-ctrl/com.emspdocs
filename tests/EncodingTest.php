<?php

use PHPUnit\Framework\TestCase;

final class EncodingTest extends TestCase
{
    public function testFixMojibakeRepairsCommonBrokenLabel(): void
    {
        self::assertSame('Déposer', emsp_fix_mojibake('DÃƒÆ’Ã‚Â©poser'));
    }

    public function testFixMojibakeLeavesHealthyUtf8Untouched(): void
    {
        self::assertSame('Médiathèque', emsp_fix_mojibake('Médiathèque'));
    }
}
