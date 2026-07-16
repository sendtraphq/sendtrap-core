<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Sendtrap\Core\Database\Factories\MessageFactory;
use Sendtrap\Core\Support\HtmlCompatibility\CaniemailDataset;
use Sendtrap\Core\Support\HtmlCompatibility\HtmlCompatibilityChecker;
use Sendtrap\Core\Support\LinkExtractor;
use Sendtrap\Core\Support\MessageStorage;
use Sendtrap\Core\Support\SpamCheck;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message as MimeMessage;

class Message extends Model
{
    protected ?IMessage $mime = null;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }

    protected $fillable = [
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
        'raw_path',
        'received_at',
        'spam_score',
        'spam_report',
        'spam_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'envelope_to' => 'array',
            'is_read' => 'boolean',
            'has_html' => 'boolean',
            'has_text' => 'boolean',
            'has_attachments' => 'boolean',
            'has_unresolved_merge_tags' => 'boolean',
            'unresolved_merge_tags' => 'array',
            'received_at' => 'datetime',
            'spam_score' => 'float',
            'spam_checked_at' => 'datetime',
        ];
    }

    /** Whether the SpamAssassin score is at/over the spam threshold. */
    public function isSpam(): bool
    {
        return $this->spam_score !== null
            && $this->spam_score >= SpamCheck::threshold();
    }

    protected static function booted(): void
    {
        static::deleting(function (Message $message) {
            // Remove on-disk artifacts; attachment DB rows cascade via FK.
            MessageStorage::disk()->delete($message->raw_path);
            MessageStorage::disk()->deleteDirectory('messages/attachments/'.$message->id);
        });
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(MessageShare::class);
    }

    public function htmlCheck(): HasOne
    {
        return $this->hasOne(MessageHtmlCheck::class);
    }

    /** Whether the cached HTML Check result predates the current caniemail dataset. */
    public function htmlCheckStale(): bool
    {
        return $this->htmlCheck === null || $this->htmlCheck->data_version !== CaniemailDataset::version();
    }

    /**
     * The cached HTML Check result, recomputing it first if missing/stale.
     * Shared by the web controller, the API controller, and `assert`'s
     * min_compatibility_score gating.
     */
    public function resolveHtmlCheck(): MessageHtmlCheck
    {
        if ($this->htmlCheckStale()) {
            $result = HtmlCompatibilityChecker::run($this);

            $this->htmlCheck()->updateOrCreate([], [
                'compatibility_ratio' => $result['compatibility_ratio'],
                'report' => $result['issues'],
                'data_version' => CaniemailDataset::version(),
                'checked_at' => now(),
            ]);
            $this->unsetRelation('htmlCheck');
        }

        return $this->htmlCheck;
    }

    /** Raw RFC822 source of the message. */
    public function raw(): string
    {
        return MessageStorage::disk()->get($this->raw_path) ?? '';
    }

    /** Lazily parse (and memoize) the raw MIME. */
    public function mime(): IMessage
    {
        return $this->mime ??= MimeMessage::from($this->raw(), true);
    }

    public function htmlBody(): ?string
    {
        return $this->mime()->getHtmlContent();
    }

    public function textBody(): ?string
    {
        return $this->mime()->getTextContent();
    }

    /**
     * Hrefs pulled from the HTML body, deduped, excluding mailto:/tel:/#-only anchors.
     *
     * @return list<string>
     */
    public function links(): array
    {
        return LinkExtractor::extract($this->htmlBody());
    }

    /**
     * All header lines as name/value pairs, in order.
     *
     * @return list<array{name: string, value: string}>
     */
    public function headerLines(): array
    {
        return collect($this->mime()->getAllHeaders())
            ->map(fn ($h) => ['name' => $h->getName(), 'value' => $h->getValue()])
            ->all();
    }

    /**
     * Rendered HTML with inline cid: references rewritten to attachment URLs,
     * ready to be displayed inside a sandboxed iframe. Pass $attachmentUrl to
     * point cid: references somewhere other than the authenticated route
     * (e.g. a public share link).
     */
    public function renderedHtml(?\Closure $attachmentUrl = null): string
    {
        $html = $this->htmlBody();

        if ($html === null || $html === '') {
            $text = $this->textBody() ?? '';

            return '<pre style="white-space:pre-wrap;font-family:monospace;">'
                .e($text).'</pre>';
        }

        $attachmentUrl ??= fn (Attachment $attachment) => route('messages.attachment', [$this, $attachment]);

        foreach ($this->attachments()->whereNotNull('content_id')->get() as $attachment) {
            $html = str_replace('cid:'.$attachment->content_id, $attachmentUrl($attachment), $html);
        }

        return $html;
    }
}
