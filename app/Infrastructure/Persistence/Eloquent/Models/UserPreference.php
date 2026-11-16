<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Domain\Users\Enums\UserPreference\InterfaceType;
use App\Domain\Users\Enums\UserPreference\Language;
use App\Domain\Users\Enums\UserPreference\Theme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $timezone
 * @property Theme $theme
 * @property Language $language
 * @property InterfaceType $interface_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class UserPreference extends Model
{
    use HasFactory;


    public const string TABLE_NAME = 'user_preferences';
    public const string FIELD_ID = 'id';
    public const string FIELD_USER_ID = 'user_id';
    public const string FIELD_TIMEZONE = 'timezone';
    public const string FIELD_THEME = 'theme';
    public const string FIELD_LANGUAGE = 'language';
    public const string FIELD_INTERFACE_TYPE = 'interface_type';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';


    protected $table = self::TABLE_NAME;
    protected $fillable = [
        self::FIELD_ID,
        self::FIELD_USER_ID,
        self::FIELD_TIMEZONE,
        self::FIELD_THEME,
        self::FIELD_LANGUAGE,
        self::FIELD_INTERFACE_TYPE,
        self::FIELD_CREATED_AT,
        self::FIELD_UPDATED_AT,
    ];


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    protected function casts(): array
    {
        return [
            self::FIELD_THEME => Theme::class,
            self::FIELD_LANGUAGE => Language::class,
            self::FIELD_INTERFACE_TYPE => InterfaceType::class,
        ];
    }
}
