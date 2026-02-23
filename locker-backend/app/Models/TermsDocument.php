<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TermsDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * @return HasMany<TermsDocumentVersion, TermsDocument>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TermsDocumentVersion::class);
    }

    /**
     * @return HasOne<TermsDocumentVersion, TermsDocument>
     */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(TermsDocumentVersion::class)->where('is_active', true);
    }
}
