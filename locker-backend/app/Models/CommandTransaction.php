<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable inbox/tracker row for a command transaction (dedup for QoS1).
 *
 * @property int $id
 * @property string $locker_uuid
 * @property string $transaction_id
 * @property string|null $action
 * @property string|null $result
 * @property string|null $error_code
 * @property string|null $source_topic
 * @property string|null $payload_hash
 * @property \Illuminate\Support\Carbon|null $first_seen_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class CommandTransaction extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'locker_uuid',
        'transaction_id',
        'action',
        'result',
        'error_code',
        'source_topic',
        'payload_hash',
        'first_seen_at',
        'last_seen_at',
        'completed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
