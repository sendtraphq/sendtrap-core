<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

class ExtractApiTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function ingest(Inbox $inbox, string $raw, array $rcpt = ['alice@example.com']): Message
    {
        (new ProcessIncomingMessage($inbox->id, $raw, 'sender@example.com', $rcpt))->handle();

        return $inbox->messages()->orderByDesc('id')->firstOrFail();
    }

    /**
     * A realistic multipart/mixed signup mail: encoded subject, custom
     * header, text + HTML alternatives (both carrying the code), several
     * links including a duplicate and a relative one, non-ASCII prose, and
     * a PDF attachment.
     */
    protected function ingestSignupMail(Inbox $inbox): Message
    {
        $html = implode('', [
            '<html><body>',
            '<p>V&auml;lkommen! Your verification code is <b>482913</b>.</p>',
            '<p><a href="https://app.example.com/verify?token=abc123&amp;x=1">Verify my account</a></p>',
            '<p><a href="https://app.example.com/verify?token=abc123&amp;x=1">Verify my account</a></p>',
            '<p><a href="https://docs.example.com/help">Help &amp; docs</a></p>',
            '<p><a href="/unsubscribe?u=9">Unsubscribe</a></p>',
            '<p><a href="mailto:support@example.com">support</a></p>',
            '</body></html>',
        ]);

        return $this->ingest($inbox, implode("\r\n", [
            'From: Acme <no-reply@acme.example>',
            'To: alice@example.com',
            'Cc: Carol <carol@example.com>, dave@example.com',
            // "Din kod – Välkommen" RFC 2047-encoded
            'Subject: =?utf-8?B?RGluIGtvZCDigJMgVsOkbGtvbW1lbg==?=',
            'X-Acme-Run: run-42',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            '--outer',
            'Content-Type: multipart/alternative; boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            'Välkommen!',
            '',
            'Your verification code is 482913. It expires in 10 minutes.',
            'Order reference: 775533.',
            '',
            '--inner',
            'Content-Type: text/html; charset=utf-8',
            '',
            $html,
            '--inner--',
            '--outer',
            'Content-Type: application/pdf; name="invoice.pdf"',
            'Content-Disposition: attachment; filename="invoice.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 fake'),
            '--outer--',
            '',
        ]), ['alice@example.com', 'hidden-bcc@example.com']);
    }

    protected function extractJson(Inbox $inbox, Message $message, array $body)
    {
        return $this->withToken($inbox->api_token)
            ->postJson("/api/v1/messages/{$message->id}/extract", $body);
    }

    public function test_a_regex_capture_returns_the_group_with_bounded_context(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $this->extractJson($inbox, $message, [
            'extract' => ['order' => ['type' => 'regex', 'pattern' => 'Order reference: (\d+)']],
        ])
            ->assertOk()
            ->assertJsonPath('found_all', true)
            ->assertJsonPath('extract.order.found', true)
            ->assertJsonPath('extract.order.status', 'found')
            ->assertJsonPath('extract.order.value', '775533')
            ->assertJsonPath('extract.order.source', 'text')
            ->assertJsonPath('extract.order.matches', 1);

        $context = $this->extractJson($inbox, $message, [
            'extract' => ['order' => ['type' => 'regex', 'pattern' => 'Order reference: (\d+)']],
        ])->json('extract.order.context');

        $this->assertStringContainsString('Order reference: 775533', $context);
        $this->assertLessThan(200, strlen($context));
    }

    public function test_regex_sources_cover_html_subject_and_named_headers(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $this->extractJson($inbox, $message, [
            'extract' => [
                'token' => ['type' => 'regex', 'pattern' => 'token=([a-z0-9]+)', 'from' => 'html', 'select' => 'first'],
                'kod' => ['type' => 'regex', 'pattern' => 'Din (kod)', 'from' => 'subject'],
                'run' => ['type' => 'regex', 'pattern' => 'run-(\d+)', 'from' => 'header.X-Acme-Run'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('extract.token.value', 'abc123')
            ->assertJsonPath('extract.kod.value', 'kod')
            ->assertJsonPath('extract.run.value', '42')
            ->assertJsonPath('extract.run.source', 'header.X-Acme-Run');
    }

    public function test_a_six_digit_code_is_found_via_the_near_anchor(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        // Two 6-digit runs exist (code + order ref); `near` disambiguates.
        $this->extractJson($inbox, $message, [
            'extract' => ['code' => ['type' => 'code', 'near' => 'verification code']],
        ])
            ->assertOk()
            ->assertJsonPath('extract.code.found', true)
            ->assertJsonPath('extract.code.value', '482913')
            ->assertJsonPath('extract.code.source', 'text');
    }

    public function test_ambiguous_codes_are_reported_not_guessed(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $response = $this->extractJson($inbox, $message, [
            'extract' => ['code' => ['type' => 'code']],
        ]);

        $response->assertOk()
            ->assertJsonPath('found_all', false)
            ->assertJsonPath('extract.code.found', false)
            ->assertJsonPath('extract.code.status', 'ambiguous')
            ->assertJsonPath('extract.code.value', null)
            ->assertJsonPath('extract.code.matches', 2);

        $this->assertSame(['482913', '775533'], $response->json('extract.code.candidates'));

        // An explicit select resolves the ambiguity deterministically.
        $this->extractJson($inbox, $message, [
            'extract' => ['code' => ['type' => 'code', 'select' => 'first']],
        ])->assertJsonPath('extract.code.value', '482913');

        $this->extractJson($inbox, $message, [
            'extract' => ['all' => ['type' => 'code', 'select' => 'all']],
        ])->assertJsonPath('extract.all.value', ['482913', '775533']);
    }

    public function test_codes_are_found_in_html_only_and_subject_sources(): void
    {
        $inbox = $this->makeInbox();

        // HTML-only message: `auto` falls back to visible text, decoding
        // entities and ignoring markup and URL digits.
        $message = $this->ingest($inbox, implode("\r\n", [
            'From: a@example.com',
            'To: alice@example.com',
            'Subject: Kod 90210 =?utf-8?Q?f=C3=B6r?= dig',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<div id="x123456">Din kod &#228;r <strong>7 7 1 0 0 4</strong>'
            .'<br>eller: 771004 <a href="https://x.example/?y=888999">l&#228;nk</a></div>',
            '',
        ]));

        $this->extractJson($inbox, $message, [
            'extract' => [
                'code' => ['type' => 'code', 'near' => 'kod'],
                'zip' => ['type' => 'code', 'length' => 5, 'from' => 'subject'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('extract.code.value', '771004')
            ->assertJsonPath('extract.code.source', 'html')
            ->assertJsonPath('extract.zip.value', '90210');
    }

    public function test_the_intended_link_is_selectable_among_several(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $byText = $this->extractJson($inbox, $message, [
            'extract' => ['verify' => ['type' => 'link', 'text_contains' => 'verify my']],
        ]);

        $byText->assertOk()
            ->assertJsonPath('extract.verify.found', true)
            // Duplicate anchors dedupe to one candidate — no ambiguity.
            ->assertJsonPath('extract.verify.matches', 1)
            ->assertJsonPath('extract.verify.value.url', 'https://app.example.com/verify?token=abc123&x=1')
            ->assertJsonPath('extract.verify.value.text', 'Verify my account');

        $this->extractJson($inbox, $message, [
            'extract' => [
                'by_host' => ['type' => 'link', 'host' => 'APP.example.com'],
                'by_path' => ['type' => 'link', 'path_prefix' => '/verify'],
                'by_param' => ['type' => 'link', 'query_param' => 'token'],
                'by_param_value' => ['type' => 'link', 'query_param' => ['name' => 'token', 'value' => 'abc123']],
                'by_regex' => ['type' => 'link', 'matches' => 'verify\?token=[a-z0-9]+'],
                'by_url' => ['type' => 'link', 'url' => 'https://docs.example.com/help'],
                'relative' => ['type' => 'link', 'path_prefix' => '/unsubscribe'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('found_all', true)
            ->assertJsonPath('extract.by_host.value.url', 'https://app.example.com/verify?token=abc123&x=1')
            ->assertJsonPath('extract.by_path.value.url', 'https://app.example.com/verify?token=abc123&x=1')
            ->assertJsonPath('extract.by_param.value.url', 'https://app.example.com/verify?token=abc123&x=1')
            ->assertJsonPath('extract.by_param_value.value.url', 'https://app.example.com/verify?token=abc123&x=1')
            ->assertJsonPath('extract.by_regex.value.url', 'https://app.example.com/verify?token=abc123&x=1')
            ->assertJsonPath('extract.by_url.value.text', 'Help & docs')
            // No base href in the mail — the relative link stays relative.
            ->assertJsonPath('extract.relative.value.url', '/unsubscribe?u=9');
    }

    public function test_unfiltered_link_extraction_is_ambiguous_when_several_links_exist(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $this->extractJson($inbox, $message, [
            'extract' => ['link' => ['type' => 'link']],
        ])
            ->assertOk()
            ->assertJsonPath('extract.link.status', 'ambiguous')
            ->assertJsonPath('extract.link.matches', 3);
    }

    public function test_addresses_come_from_headers_and_envelope(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $this->extractJson($inbox, $message, [
            'extract' => [
                'sender' => ['type' => 'address', 'field' => 'from'],
                'carol' => ['type' => 'address', 'field' => 'cc', 'matches' => '^carol@'],
                // The BCC-style recipient only exists in the envelope.
                'bcc' => ['type' => 'address', 'field' => 'envelope_to', 'matches' => '^hidden-'],
                'all_cc' => ['type' => 'address', 'field' => 'cc', 'select' => 'all'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('extract.sender.value.address', 'no-reply@acme.example')
            ->assertJsonPath('extract.sender.value.name', 'Acme')
            ->assertJsonPath('extract.carol.value.address', 'carol@example.com')
            ->assertJsonPath('extract.bcc.value.address', 'hidden-bcc@example.com')
            ->assertJsonPath('extract.all_cc.matches', 2);
    }

    public function test_attachments_return_metadata_and_an_authenticated_url(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);
        $attachment = $message->attachments()->firstOrFail();

        $response = $this->extractJson($inbox, $message, [
            'extract' => [
                'invoice' => ['type' => 'attachment', 'content_type' => 'application/pdf'],
                'by_name' => ['type' => 'attachment', 'filename_contains' => '.PDF'],
                'wildcard' => ['type' => 'attachment', 'content_type' => 'application/*'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('extract.invoice.value.id', $attachment->id)
            ->assertJsonPath('extract.invoice.value.filename', 'invoice.pdf')
            ->assertJsonPath('extract.invoice.value.content_type', 'application/pdf')
            ->assertJsonPath('extract.invoice.value.checksum', $attachment->checksum)
            ->assertJsonPath('extract.by_name.value.id', $attachment->id)
            ->assertJsonPath('extract.wildcard.value.id', $attachment->id);

        $url = $response->json('extract.invoice.value.url');
        $this->assertSame(route('api.messages.attachment', [$message, $attachment]), $url);

        // The returned URL sits behind the same inbox-token auth.
        $this->withToken($inbox->api_token)->get($url)->assertOk();
        $this->withToken($this->makeInbox()->api_token)->get($url)->assertNotFound();
    }

    public function test_misses_are_explicit_and_strict_mode_turns_them_into_422(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $this->extractJson($inbox, $message, [
            'extract' => ['nope' => ['type' => 'regex', 'pattern' => 'no such text \d+']],
        ])
            ->assertOk()
            ->assertJsonPath('found_all', false)
            ->assertJsonPath('extract.nope.found', false)
            ->assertJsonPath('extract.nope.status', 'not_found')
            ->assertJsonPath('extract.nope.matches', 0);

        $this->extractJson($inbox, $message, [
            'extract' => ['nope' => ['type' => 'regex', 'pattern' => 'no such text \d+']],
            'mode' => 'strict',
        ])->assertStatus(422)->assertJsonPath('extract.nope.status', 'not_found');

        // An optional extractor's miss doesn't fail strict mode.
        $this->extractJson($inbox, $message, [
            'extract' => ['nope' => ['type' => 'regex', 'pattern' => 'no such text \d+', 'optional' => true]],
            'mode' => 'strict',
        ])->assertOk()->assertJsonPath('found_all', true);
    }

    public function test_validation_rejects_malformed_specs_with_clear_messages(): void
    {
        $inbox = $this->makeInbox();
        $message = $this->ingestSignupMail($inbox);

        $cases = [
            [[], null],
            [['extract' => []], null],
            [['extract' => ['a' => ['type' => 'ai']]], 'unknown type'],
            [['extract' => ['a' => ['type' => 'code', 'pattern' => 'x']]], 'unknown option'],
            [['extract' => ['a' => ['type' => 'regex']]], '"pattern" is required'],
            [['extract' => ['a' => ['type' => 'regex', 'pattern' => '([unclosed']]], 'not a valid regular expression'],
            [['extract' => ['a' => ['type' => 'regex', 'pattern' => str_repeat('x', 300)]]], 'capped at 256'],
            [['extract' => ['a' => ['type' => 'code', 'length' => 99]]], 'between 4 and 12'],
            [['extract' => ['a' => ['type' => 'code', 'charset' => 'emoji']]], 'charset'],
            [['extract' => ['a' => ['type' => 'address']]], '"field" must be one of'],
            [['extract' => ['a' => ['type' => 'attachment', 'content_type' => 'pdf']]], 'media type'],
            [['extract' => ['a' => ['type' => 'code', 'select' => 'best']]], 'select'],
            [['extract' => ['bad name!' => ['type' => 'code']]], 'Extractor names'],
            [['extract' => array_fill_keys(range('a', 'k'), ['type' => 'code'])], 'capped at 10'],
            [['extract' => ['a' => ['type' => 'code']], 'mode' => 'loose'], '"mode" must be'],
        ];

        foreach ($cases as [$body, $needle]) {
            $response = $this->extractJson($inbox, $message, $body);
            $response->assertStatus(422);

            if ($needle !== null) {
                $this->assertStringContainsString($needle, $response->json('message'), json_encode($body));
            }
        }
    }

    public function test_a_message_in_another_inbox_is_unreachable(): void
    {
        $a = $this->makeInbox();
        $b = $this->makeInbox();
        $message = $this->ingestSignupMail($b);

        $this->extractJson($a, $message, [
            'extract' => ['code' => ['type' => 'code']],
        ])->assertNotFound();
    }
}
