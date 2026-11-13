<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models\Media;

use App\Application\Media\Enums\TitleImageKind;
use App\Application\Media\Enums\TitleImageStatus;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TitleImageRequest extends Model
{
    protected $table = 'title_image_requests';


    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }


    protected function casts(): array
    {
        return [
            'kind' => TitleImageKind::class,
            'status' => TitleImageStatus::class,
            'version' => 'integer',
            'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime',
        ];
    }
}
