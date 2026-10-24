<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Domain\Users\Enums\User\UserRole;
use App\Domain\Users\Enums\User\UserStatus;
use App\Infrastructure\Persistence\Eloquent\Casts\VectorCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nickname
 * @property string $email
 * @property string $password
 * @property string|null $avatar_key
 * @property list<float>|null $embedding
 * @property UserStatus $status
 * @property bool $is_active
 * @property UserRole $role
 * @property Carbon|null $last_active_at
 * @property Carbon $birthday
 * @property string $secret_phrase_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read UserPreference|null $preferences
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;


    public const string TABLE_NAME = 'users';
    public const string FIELD_ID = 'id';
    public const string FIELD_NICKNAME = 'nickname';
    public const string NICKNAME_LOWER_UNIQUE_INDEX = 'users_nickname_lower_unique';
    public const string FIELD_EMAIL = 'email';
    public const string FIELD_PASSWORD = 'password';
    public const string FIELD_AVATAR_KEY = 'avatar_key';
    public const string FIELD_EMBEDDING = 'embedding';
    public const string FIELD_STATUS = 'status';
    public const string FIELD_IS_ACTIVE = 'is_active';
    public const string FIELD_ROLE = 'role';
    public const string FIELD_LAST_ACTIVE_AT = 'last_active_at';
    public const string FIELD_BIRTHDAY = 'birthday';
    public const string FIELD_SECRET_PHRASE_HASH = 'secret_phrase_hash';
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_UPDATED_AT = 'updated_at';
    public const string FIELD_DELETED_AT = 'deleted_at';


    protected $table = self::TABLE_NAME;
    protected $fillable = [
        self::FIELD_ID,
        self::FIELD_NICKNAME,
        self::FIELD_EMAIL,
        self::FIELD_PASSWORD,
        self::FIELD_AVATAR_KEY,
        self::FIELD_EMBEDDING,
        self::FIELD_STATUS,
        self::FIELD_IS_ACTIVE,
        self::FIELD_ROLE,
        self::FIELD_LAST_ACTIVE_AT,
        self::FIELD_BIRTHDAY,
        self::FIELD_SECRET_PHRASE_HASH,
        self::FIELD_CREATED_AT,
        self::FIELD_UPDATED_AT,
        self::FIELD_DELETED_AT,
    ];
    protected $hidden = [
        self::FIELD_PASSWORD,
        self::FIELD_SECRET_PHRASE_HASH,
    ];


    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }


    protected function casts(): array
    {
        return [
            self::FIELD_EMBEDDING => VectorCast::class,
            self::FIELD_STATUS => UserStatus::class,
            self::FIELD_IS_ACTIVE => 'boolean',
            self::FIELD_ROLE => UserRole::class,
            self::FIELD_LAST_ACTIVE_AT => 'datetime',
            self::FIELD_BIRTHDAY => 'date',
        ];
    }
}
