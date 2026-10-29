<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models\Import;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Domain\Import\Enums\ImportRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    self::FIELD_PROVIDER,
    self::FIELD_DATA_SOURCE,
    self::FIELD_STATUS,
    self::FIELD_SNAPSHOT_ID,
    self::FIELD_OWNER_TOKEN,
    self::FIELD_LIMIT,
    self::FIELD_CHECKPOINT,
    self::FIELD_FETCHED,
    self::FIELD_ACCEPTED,
    self::FIELD_FLAGGED,
    self::FIELD_REJECTED,
    self::FIELD_ERROR_MESSAGE,
    self::FIELD_STARTED_AT,
    self::FIELD_FINISHED_AT,
])]
final class ImportRun extends Model
{
    public const string TABLE_NAME = 'import_runs';
    public const string FIELD_ID = 'id';
    public const string FIELD_PROVIDER = 'provider';
    public const string FIELD_DATA_SOURCE = 'data_source';
    public const string FIELD_STATUS = 'status';
    public const string FIELD_SNAPSHOT_ID = 'snapshot_id';
    public const string FIELD_OWNER_TOKEN = 'owner_token';
    public const string FIELD_LIMIT = 'limit';
    public const string FIELD_CHECKPOINT = 'checkpoint';
    public const string FIELD_FETCHED = 'fetched';
    public const string FIELD_ACCEPTED = 'accepted';
    public const string FIELD_FLAGGED = 'flagged';
    public const string FIELD_REJECTED = 'rejected';
    public const string FIELD_ERROR_MESSAGE = 'error_message';
    public const string FIELD_STARTED_AT = 'started_at';
    public const string FIELD_FINISHED_AT = 'finished_at';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';


    protected $table = self::TABLE_NAME;


    public function rejections(): HasMany
    {
        return $this->hasMany(ImportRejection::class, ImportRejection::FIELD_IMPORT_RUN_ID);
    }


    protected function casts(): array
    {
        return [
            self::FIELD_PROVIDER => ProviderSource::class,
            self::FIELD_DATA_SOURCE => ProviderDataSource::class,
            self::FIELD_STATUS => ImportRunStatus::class,
            self::FIELD_LIMIT => 'integer',
            self::FIELD_CHECKPOINT => 'integer',
            self::FIELD_FETCHED => 'integer',
            self::FIELD_ACCEPTED => 'integer',
            self::FIELD_FLAGGED => 'integer',
            self::FIELD_REJECTED => 'integer',
            self::FIELD_STARTED_AT => 'immutable_datetime',
            self::FIELD_FINISHED_AT => 'immutable_datetime',
            self::FIELD_CREATED_AT => 'immutable_datetime',
            self::FIELD_UPDATED_AT => 'immutable_datetime',
        ];
    }
}
