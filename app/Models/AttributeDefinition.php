<?php

namespace App\Models;

use App\Enums\Title\AttributeType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $content_type_id
 * @property string $code
 * @property string $name_ru
 * @property string $name_en
 * @property string $type
 * @property bool $is_filterable
 * @property bool $is_required
 * @property int $order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Collection<int, TitleAttribute> $titleAttributes
 * @property-read Collection<int, AttributeOption> $options
 */
class AttributeDefinition extends Model
{
    public const string FIELD_ID = 'id';
    public const string FIELD_CONTENT_TYPE_ID = 'content_type_id';
    public const string FIELD_CODE = 'code';
    public const string FIELD_NAME_RU = 'name_ru';
    public const string FIELD_NAME_EN = 'name_en';
    public const string FIELD_TYPE = 'type';
    public const string FIELD_IS_FILTERABLE = 'is_filterable';
    public const string FIELD_IS_REQUIRED = 'is_required';
    public const string FIELD_ORDER = 'order';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_DELETED_AT = 'deleted_at';

    protected $fillable = [
        self::FIELD_ID,
        self::FIELD_CONTENT_TYPE_ID,
        self::FIELD_CODE,
        self::FIELD_NAME_RU,
        self::FIELD_NAME_EN,
        self::FIELD_TYPE,
        self::FIELD_IS_FILTERABLE,
        self::FIELD_IS_REQUIRED,
        self::FIELD_ORDER,
        self::FIELD_CREATED_AT,
        self::FIELD_DELETED_AT,
    ];

    protected function casts(): array
    {
        return [
            self::FIELD_CONTENT_TYPE_ID => 'integer',
            self::FIELD_TYPE => AttributeType::class,
            self::FIELD_IS_FILTERABLE => 'boolean',
            self::FIELD_IS_REQUIRED => 'boolean',
            self::FIELD_ORDER => 'integer',
        ];
    }

    public function titleAttributes(): HasMany
    {
        return $this->hasMany(TitleAttribute::class, TitleAttribute::FIELD_ATTRIBUTE_ID);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class, AttributeOption::FIELD_ATTRIBUTE_ID);
    }
}
