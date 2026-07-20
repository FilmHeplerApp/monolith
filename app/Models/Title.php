<?php

namespace App\Models;

use App\Enums\Title\TitleStatus;
use App\Enums\Title\TitleType;
use App\Enums\Title\TitleUpdatedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $external_id
 * @property string $title_ru
 * @property string|null $title_en
 * @property string|null $description_ru
 * @property string|null $description_en
 * @property string|null $short_plot_ru
 * @property int|null $duration
 * @property string $type
 * @property string $status
 * @property string|null $poster_url
 * @property string|null $banner_url
 * @property array|null $genres_ru
 * @property array|null $genres_en
 * @property array|null $mood
 * @property array|null $tags
 * @property float $rating_avg
 * @property int $rating_count
 * @property array|null $embedding
 * @property string $updated_by
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * @property-read Collection<int, TitleAttribute> $attributes
 */
class Title extends Model
{
    public const string FIELD_ID = 'id';
    public const string FIELD_EXTERNAL_ID = 'external_id';
    public const string FIELD_TITLE_RU = 'title_ru';
    public const string FIELD_TITLE_EN = 'title_en';
    public const string FIELD_DESCRIPTION_RU = 'description_ru';
    public const string FIELD_DESCRIPTION_EN = 'description_en';
    public const string FIELD_SHORT_PLOT_RU = 'short_plot_ru';
    public const string FIELD_DURATION = 'duration';
    public const string FIELD_TYPE = 'type';
    public const string FIELD_STATUS = 'status';
    public const string FIELD_POSTER_URL = 'poster_url';
    public const string FIELD_BANNER_URL = 'banner_url';
    public const string FIELD_RATING_AVG = 'rating_avg';
    public const string FIELD_RATING_COUNT = 'rating_count';
    public const string FIELD_EMBEDDING = 'embedding';
    public const string FIELD_UPDATED_BY = 'updated_by';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_DELETED_AT = 'deleted_at';

    protected $fillable = [
        self::FIELD_ID,
        self::FIELD_EXTERNAL_ID,
        self::FIELD_TITLE_RU,
        self::FIELD_TITLE_EN,
        self::FIELD_DESCRIPTION_RU,
        self::FIELD_DESCRIPTION_EN,
        self::FIELD_SHORT_PLOT_RU,
        self::FIELD_DURATION,
        self::FIELD_TYPE,
        self::FIELD_STATUS,
        self::FIELD_POSTER_URL,
        self::FIELD_BANNER_URL,
        self::FIELD_RATING_AVG,
        self::FIELD_RATING_COUNT,
        self::FIELD_EMBEDDING,
        self::FIELD_UPDATED_BY,
        self::FIELD_CREATED_AT,
        self::FIELD_DELETED_AT,
    ];

    protected function casts(): array
    {
        return [
            self::FIELD_TYPE => TitleType::class,
            self::FIELD_STATUS => TitleStatus::class,
            self::FIELD_EMBEDDING => 'array',
            self::FIELD_RATING_AVG => 'float',
            self::FIELD_UPDATED_BY => TitleUpdatedBy::class,
        ];
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(TitleAttribute::class);
    }
}
