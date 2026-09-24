<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models\Import;

use App\Domain\Import\Enums\RejectionReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $import_run_id
 * @property string $external_id
 * @property RejectionReason $reason
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ImportRun $run
 */
#[Fillable([
    self::FIELD_IMPORT_RUN_ID,
    self::FIELD_EXTERNAL_ID,
    self::FIELD_REASON,
    self::FIELD_CONTEXT,
])]
final class ImportRejection extends Model
{
    public const string TABLE_NAME = 'import_rejections';
    public const string FIELD_ID = 'id';
    public const string FIELD_IMPORT_RUN_ID = 'import_run_id';
    public const string FIELD_EXTERNAL_ID = 'external_id';
    public const string FIELD_REASON = 'reason';
    public const string FIELD_CONTEXT = 'context';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';


    protected $table = self::TABLE_NAME;

    protected function casts(): array
    {
        return [
            self::FIELD_IMPORT_RUN_ID => 'integer',
            self::FIELD_REASON        => RejectionReason::class,
            self::FIELD_CONTEXT       => 'array',
            self::FIELD_CREATED_AT    => 'immutable_datetime',
            self::FIELD_UPDATED_AT    => 'immutable_datetime',
        ];
    }


    /** @return BelongsTo<ImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, self::FIELD_IMPORT_RUN_ID);
    }
}
