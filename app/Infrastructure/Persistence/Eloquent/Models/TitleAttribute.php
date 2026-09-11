<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $title_id
 * @property int $attribute_id
 * @property string|null $value_text
 * @property array|null $value_array
 * @property float|null $value_number
 * @property bool|null $value_boolean
 * @property string|null $searchable_text
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Title $title
 * @property-read AttributeDefinition $attribute
 */
class TitleAttribute extends Model
{
    use HasFactory;


    public const string TABLE_NAME = 'title_attributes';
    public const string FIELD_ID = 'id';
    public const string FIELD_TITLE_ID = 'title_id';
    public const string FIELD_ATTRIBUTE_ID = 'attribute_id';
    public const string FIELD_VALUE_TEXT = 'value_text';
    public const string FIELD_VALUE_ARRAY = 'value_array';
    public const string FIELD_VALUE_NUMBER = 'value_number';
    public const string FIELD_VALUE_BOOLEAN = 'value_boolean';
    public const string FIELD_SEARCHABLE_TEXT = 'searchable_text';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';
    public const string FIELD_DELETED_AT = 'deleted_at';

    protected $fillable = [
        self::FIELD_ID,
        self::FIELD_TITLE_ID,
        self::FIELD_ATTRIBUTE_ID,
        self::FIELD_VALUE_TEXT,
        self::FIELD_VALUE_ARRAY,
        self::FIELD_VALUE_NUMBER,
        self::FIELD_VALUE_BOOLEAN,
        self::FIELD_SEARCHABLE_TEXT,
        self::FIELD_CREATED_AT,
        self::FIELD_UPDATED_AT,
        self::FIELD_DELETED_AT,
    ];

    protected function casts(): array
    {
        return [
            self::FIELD_TITLE_ID      => 'integer',
            self::FIELD_ATTRIBUTE_ID  => 'integer',
            self::FIELD_VALUE_ARRAY   => 'array',
            self::FIELD_VALUE_NUMBER  => 'float',
            self::FIELD_VALUE_BOOLEAN => 'boolean',
        ];
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, self::FIELD_ATTRIBUTE_ID);
    }


    protected static function booted(): void
    {
        static::saving(function (TitleAttribute $attribute): void {
            $attribute->searchable_text = match (true) {
                $attribute->value_text !== null => $attribute->value_text,
                $attribute->value_array !== null => implode(' ', $attribute->value_array),
                $attribute->value_number !== null => (string) $attribute->value_number,
                $attribute->value_boolean !== null => $attribute->value_boolean ? 'true' : 'false',
                default => null,
            };
        });
    }
}
