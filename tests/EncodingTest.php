<?php

use PHPUnit\Framework\TestCase;

final class EncodingTest extends TestCase
{
    public function testFixMojibakeRepairsCommonBrokenLabel(): void
    {
        self::assertSame('DÃ©poser', emsp_fix_mojibake('DÃƒÂ©poser'));
    }

    public function testFixMojibakeLeavesHealthyUtf8Untouched(): void
    {
        self::assertSame('MÃ©diathÃ¨que', emsp_fix_mojibake('MÃ©diathÃ¨que'));
    }
}


