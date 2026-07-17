<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

class SendTestCommandTest extends PackageTestCase
{
    protected function makeInbox(string $project = 'P', string $name = 'Inbox'): Inbox
    {
        $workspace = Workspace::factory()->create();

        return $workspace->projects()->create(['name' => $project])
            ->inboxes()->create(['name' => $name]);
    }

    public function test_it_seeds_the_rich_fixture_into_the_only_inbox(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        $this->artisan('sendtrap:send-test')->assertSuccessful();

        $message = $inbox->messages()->sole();
        $this->assertSame('send-test', $message->test_id);
        $this->assertSame('Your first Sendtrap message 🎉', $message->subject);
        $this->assertTrue($message->has_html);
        $this->assertTrue($message->has_text);
        $this->assertTrue($message->has_attachments);
        $this->assertTrue($message->has_unresolved_merge_tags);
        $this->assertContains('{{first_name}}', $message->unresolved_merge_tags);

        // BCC: on the envelope, never in the To/Cc headers.
        $this->assertContains('audit@example.com', $message->envelope_to);
        $addresses = collect($message->to)->merge($message->cc)->pluck('address');
        $this->assertNotContains('audit@example.com', $addresses);

        // One inline cid: part + one file attachment.
        $attachments = $message->attachments;
        $this->assertCount(2, $attachments);
        $this->assertTrue($attachments->firstWhere('filename', 'sendtrap-logo.png')->is_inline);
        $this->assertFalse($attachments->firstWhere('filename', 'sendtrap-demo.txt')->is_inline);

        $this->assertNotEmpty($message->links());
    }

    public function test_it_resolves_an_inbox_by_id_and_by_name(): void
    {
        Storage::fake('local');
        $a = $this->makeInbox('P1', 'Alpha');
        $b = $this->makeInbox('P2', 'Beta');

        $this->artisan('sendtrap:send-test', ['--inbox' => (string) $a->id])->assertSuccessful();
        $this->artisan('sendtrap:send-test', ['--inbox' => 'Beta'])->assertSuccessful();

        $this->assertSame(1, $a->messages()->count());
        $this->assertSame(1, $b->messages()->count());
    }

    public function test_it_prompts_when_several_inboxes_exist(): void
    {
        Storage::fake('local');
        $a = $this->makeInbox('P1', 'Alpha');
        $b = $this->makeInbox('P2', 'Beta');

        $this->artisan('sendtrap:send-test')
            ->expectsChoice(
                'Which inbox should receive the test message?',
                "#{$b->id}",
                ["#{$a->id}" => 'P1 / Alpha', "#{$b->id}" => 'P2 / Beta'],
            )
            ->assertSuccessful();

        $this->assertSame(1, $b->messages()->count());
    }

    public function test_it_fails_cleanly_with_no_inboxes_or_an_unknown_inbox(): void
    {
        $this->artisan('sendtrap:send-test')->assertFailed();

        $this->makeInbox();
        $this->artisan('sendtrap:send-test', ['--inbox' => 'nope'])->assertFailed();
    }
}
