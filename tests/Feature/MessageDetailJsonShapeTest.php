<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Locks down the full JSON shape of GET /api/v1/messages/{id}
 * (Sendtrap\Core\Http\Resources\MessageDetailResource), which previously
 * only had spot-checks.
 */
class MessageDetailJsonShapeTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function emlWithHtmlTextAndAttachment(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Cc: carol@example.com',
            'Subject: Hello multipart',
            'Message-ID: <shape-1@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="OUTER"',
            '',
            '--OUTER',
            'Content-Type: multipart/alternative; boundary="INNER"',
            '',
            '--INNER',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Hi there',
            '--INNER',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><h1>Hi</h1></body></html>',
            '--INNER--',
            '--OUTER',
            'Content-Type: text/plain; name="note.txt"',
            'Content-Disposition: attachment; filename="note.txt"',
            '',
            'This is an attachment.',
            '--OUTER--',
            '',
        ]);
    }

    public function test_the_message_detail_response_matches_the_full_expected_json_shape(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->emlWithHtmlTextAndAttachment());
        $message = $inbox->messages()->first();

        $response = $this->withToken($inbox->api_token)
            ->getJson("/api/v1/messages/{$message->id}")
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'id',
                'inbox_id',
                'message_id',
                'test_id',
                'envelope_from',
                'envelope_to',
                'from_address',
                'from_name',
                'to',
                'cc',
                'subject',
                'size',
                'is_read',
                'has_html',
                'has_text',
                'has_attachments',
                'has_unresolved_merge_tags',
                'unresolved_merge_tags',
                'received_at',
                'html',
                'text',
                'links',
                'checks' => [
                    '*' => ['key', 'passed', 'severity'],
                ],
                'headers' => [
                    '*' => ['name', 'value'],
                ],
                'attachments' => [
                    '*' => ['id', 'filename', 'content_type', 'size', 'checksum', 'is_inline', 'url'],
                ],
                'urls' => ['raw', 'html'],
            ],
        ]);

        // No key beyond the ones asserted above — a stray/renamed key would
        // otherwise slip past assertJsonStructure's '*' wildcards silently.
        $this->assertSame([
            'id', 'inbox_id', 'message_id', 'test_id', 'envelope_from', 'envelope_to',
            'from_address', 'from_name', 'to', 'cc', 'subject', 'size', 'is_read',
            'has_html', 'has_text', 'has_attachments', 'has_unresolved_merge_tags',
            'unresolved_merge_tags', 'received_at', 'html', 'text', 'links', 'checks',
            'headers', 'attachments', 'urls',
        ], array_keys($response->json('data')));

        $this->assertSame(
            ['id', 'filename', 'content_type', 'size', 'checksum', 'is_inline', 'url'],
            array_keys($response->json('data.attachments.0'))
        );
        $this->assertSame(['raw', 'html'], array_keys($response->json('data.urls')));

        $response->assertJsonCount(1, 'data.attachments');
        $response->assertJsonPath('data.attachments.0.filename', 'note.txt');
        $response->assertJsonPath('data.has_html', true);
        $response->assertJsonPath('data.has_text', true);
        $response->assertJsonPath('data.has_attachments', true);
        $response->assertJsonPath('data.subject', 'Hello multipart');
        $response->assertJsonPath('data.message_id', 'shape-1@example.com');
    }

    /**
     * CHARACTERIZATION: package's own UnlimitedEntitlements reference fake
     * (§5.3) grants hasHtmlCheckApi() unconditionally — there is no "free
     * plan" concept in the package at all (that's Cloud's billing.php/
     * TeamPlan, never bound here). The host's own free-plan characterization
     * of this same behavior (checks excludes html_compatibility on the free
     * tier) stays host-side; this package-side twin instead characterizes
     * the *other* half of MessageDetailResource::checks()'s gate condition —
     * it never appends the summary entry at all when no MessageHtmlCheck row
     * exists yet, regardless of plan, since neither GET
     * /api/v1/messages/{id} nor ingestion computes one automatically.
     */
    public function test_checks_excludes_html_compatibility_when_no_check_has_run_yet(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->emlWithHtmlTextAndAttachment());
        $message = $inbox->messages()->first();

        $response = $this->withToken($inbox->api_token)
            ->getJson("/api/v1/messages/{$message->id}")
            ->assertOk();

        $checkKeys = collect($response->json('data.checks'))->pluck('key')->all();

        $this->assertSame([
            'missing_text_part',
            'oversized_html',
            'missing_list_unsubscribe',
            'from_address_present',
        ], $checkKeys);
        $this->assertNotContains('html_compatibility', $checkKeys);
    }
}
