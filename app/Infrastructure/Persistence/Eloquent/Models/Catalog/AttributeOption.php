<?php

namespace App\Infrastructure\Persistence\Eloquent\Models\Catalog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $value_ru
 * @property string $value_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttributeOption extends Model
{
    use HasFactory;


    public const string TABLE_NAME = 'attribute_options';
    public const string FIELD_ID = 'id';
    public const string FIELD_ATTRIBUTE_ID = 'attribute_id';
    public const string FIELD_VALUE_RU = 'value_ru';
    public const string FIELD_VALUE_EN = 'value_en';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';
    public const string FIELD_DELETED_AT = 'deleted_at';


    protected $fillable = [
        self::FIELD_ID,
        self::FIELD_ATTRIBUTE_ID,
        self::FIELD_VALUE_RU,
        self::FIELD_VALUE_EN,
        self::FIELD_CREATED_AT,
        self::FIELD_UPDATED_AT,
        self::FIELD_DELETED_AT,
    ];
}
