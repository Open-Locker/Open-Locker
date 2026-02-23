<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTermsAcceptance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'terms_document_id',
        'terms_document_version_id',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, UserTermsAcceptance>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TermsDocument, UserTermsAcceptance>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(TermsDocument::class, 'terms_document_id');
    }

    /**
     * @return BelongsTo<TermsDocumentVersion, UserTermsAcceptance>
     */
    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(TermsDocumentVersion::class, 'terms_document_version_id');
    }
}
