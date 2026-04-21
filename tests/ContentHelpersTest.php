<?php

use PHPUnit\Framework\TestCase;

final class ContentHelpersTest extends TestCase
{
    public function testMediaSrcNormalizesRelativeMediaPath(): void
    {
        self::assertSame('uploads/media/album/cover.jpg', emsp_media_src('album/cover.jpg'));
    }

    public function testUserPhotoSrcNormalizesExistingProfilePath(): void
    {
        $dir = dirname(__DIR__) . '/uploads/profiles';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir . '/avatar-test.png';
        file_put_contents($file, 'avatar');

        try {
            self::assertSame('uploads/profiles/avatar-test.png', emsp_user_photo_src('profiles/avatar-test.png'));
        } finally {
            @unlink($file);
        }
    }

    public function testUserPhotoSrcReturnsEmptyStringWhenFileIsMissing(): void
    {
        self::assertSame('', emsp_user_photo_src('profiles/avatar-missing.png'));
    }

    public function testExcerptTruncatesCleanText(): void
    {
        self::assertSame('Bonjour tou...', emsp_excerpt('Bonjour tout le monde', 12));
    }
}


