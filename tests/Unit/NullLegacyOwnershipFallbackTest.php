<?php

namespace Sendtrap\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Support\NullLegacyOwnershipFallback;

/**
 * Plan 06 Phase 3b slice 3 (§3.2): the package-shipped default answers
 * every method with the contract's "no limit" answer, for a null $inbox
 * (H-N1) and a non-null one alike — it never resolves an owner at all.
 */
class NullLegacyOwnershipFallbackTest extends TestCase
{
    public function test_active_defaults_to_permitted(): void
    {
        $this->assertTrue((new NullLegacyOwnershipFallback)->active());
    }

    public function test_every_method_answers_no_limit_for_a_null_inbox(): void
    {
        $fallback = new NullLegacyOwnershipFallback;

        $this->assertNull($fallback->emailSizeLimitBytes(null));
        $this->assertNull($fallback->checkSend(null));
        $this->assertFalse($fallback->wouldExceedStorage(null, PHP_INT_MAX));
        $this->assertTrue($fallback->canForward(null));
        $this->assertSame([], $fallback->allowedIpsFallback(null));

        // No-ops must not throw (H-N1: never a TypeError, never a deny).
        $fallback->recordSend(null);
        $fallback->recordForward(null);

        $this->assertSame(0, $fallback->pruneLegacyOwned(true));
        $this->assertSame(0, $fallback->pruneLegacyOwned(false));
    }

    public function test_a_non_null_inbox_gets_the_identical_no_limit_answers(): void
    {
        $fallback = new NullLegacyOwnershipFallback;
        $inbox = new Inbox; // unsaved is fine — the null impl never reads it

        $this->assertNull($fallback->emailSizeLimitBytes($inbox));
        $this->assertNull($fallback->checkSend($inbox));
        $this->assertFalse($fallback->wouldExceedStorage($inbox, PHP_INT_MAX));
        $this->assertTrue($fallback->canForward($inbox));
        $this->assertSame([], $fallback->allowedIpsFallback($inbox));

        $fallback->recordSend($inbox);
        $fallback->recordForward($inbox);

        $this->assertSame(0, $fallback->pruneLegacyOwned(true));
    }
}
