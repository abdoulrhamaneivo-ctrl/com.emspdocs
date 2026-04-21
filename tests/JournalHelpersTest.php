<?php

use PHPUnit\Framework\TestCase;

final class JournalHelpersTest extends TestCase
{
    public function testJournalStateDetectsScheduledArticle(): void
    {
        $state = emsp_journal_state(
            ['status' => 'published', 'starts_at' => '2030-05-01 10:00:00'],
            new DateTimeImmutable('2030-05-01 09:00:00')
        );

        self::assertSame('scheduled', $state['code']);
        self::assertTrue($state['is_scheduled']);
    }

    public function testJournalStateDetectsExpiredArticle(): void
    {
        $state = emsp_journal_state(
            ['status' => 'published', 'ends_at' => '2030-05-01 10:00:00'],
            new DateTimeImmutable('2030-05-01 11:00:00')
        );

        self::assertSame('expired', $state['code']);
        self::assertTrue($state['is_expired']);
    }

    public function testJournalFixPathsRebasesUploadsAndAssets(): void
    {
        $html = '<img src="../uploads/journal/image.jpg"><img src="../assets/img.png">';
        self::assertSame('<img src="uploads/journal/image.jpg"><img src="assets/img.png">', emsp_journal_fix_paths($html));
    }
}


