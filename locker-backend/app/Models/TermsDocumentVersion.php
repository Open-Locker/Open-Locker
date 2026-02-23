<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TermsDocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'terms_document_id',
        'document_name_snapshot',
        'version',
        'content',
        'is_published',
        'is_active',
        'published_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<TermsDocument, TermsDocumentVersion>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(TermsDocument::class, 'terms_document_id');
    }

    /**
     * @return BelongsTo<User, TermsDocumentVersion>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<UserTermsAcceptance, TermsDocumentVersion>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(UserTermsAcceptance::class, 'terms_document_version_id');
    }
}
