<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models\Media;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $canonical_key
 * @property string|null $poster_url
 * @property string|null $banner_url
 * @property int $revision
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    self::FIELD_CANONICAL_KEY,
    self::FIELD_POSTER_URL,
    self::FIELD_BANNER_URL,
    self::FIELD_REVISION,
])]
final class TitleImageIngestion extends Model
{
    public const string TABLE_NAME = 'title_image_ingestions';
    public const string FIELD_ID = 'id';
    public const string FIELD_CANONICAL_KEY = 'canonical_key';
    public const string FIELD_POSTER_URL = 'poster_url';
    public const string FIELD_BANNER_URL = 'banner_url';
    public const string FIELD_REVISION = 'revision';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';


    protected $table = self::TABLE_NAME;


    protected function casts(): array
    {
        return [
            self::FIELD_REVISION => 'integer',
            self::FIELD_CREATED_AT => 'immutable_datetime',
            self::FIELD_UPDATED_AT => 'immutable_datetime',
        ];
    }
}
