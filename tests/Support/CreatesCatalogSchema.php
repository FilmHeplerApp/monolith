<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\TitleAttribute;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesCatalogSchema
{
    private function createCatalogSchema(): void
    {
        Schema::create(AttributeDefinition::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->string(AttributeDefinition::FIELD_CONTENT_TYPE_CODE);
            $table->string(AttributeDefinition::FIELD_CODE);
            $table->unique([
                AttributeDefinition::FIELD_CONTENT_TYPE_CODE,
                AttributeDefinition::FIELD_CODE,
            ]);
            $table->string(AttributeDefinition::FIELD_NAME_RU);
            $table->string(AttributeDefinition::FIELD_NAME_EN);
            $table->string(AttributeDefinition::FIELD_VALUE_TYPE);
            $table->boolean(AttributeDefinition::FIELD_IS_FILTERABLE)->default(true);
            $table->boolean(AttributeDefinition::FIELD_IS_REQUIRED)->default(false);
            $table->unsignedInteger(AttributeDefinition::FIELD_ORDER)->default(0);
            $table->timestamps();
        });

        Schema::create(Title::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->uuid(Title::FIELD_UUID)->unique();
            $table->string(Title::FIELD_CANONICAL_KEY)->unique();
            $table->string(Title::FIELD_TITLE_RU)->nullable();
            $table->string(Title::FIELD_TITLE_EN)->nullable();
            $table->text(Title::FIELD_DESCRIPTION_RU)->nullable();
            $table->text(Title::FIELD_DESCRIPTION_EN)->nullable();
            $table->text(Title::FIELD_SHORT_PLOT_RU)->nullable();
            $table->unsignedInteger(Title::FIELD_DURATION)->nullable();
            $table->unsignedSmallInteger(Title::FIELD_RELEASE_YEAR)->nullable();
            $table->string(Title::FIELD_TYPE);
            $table->string(Title::FIELD_STATUS);
            $table->string(Title::FIELD_POSTER_KEY)->nullable();
            $table->string(Title::FIELD_POSTER_THUMB_KEY)->nullable();
            $table->string(Title::FIELD_BANNER_KEY)->nullable();
            $table->char(Title::FIELD_POSTER_SOURCE_HASH, 64)->nullable();
            $table->char(Title::FIELD_BANNER_SOURCE_HASH, 64)->nullable();
            $table->decimal(Title::FIELD_RATING_AVG, 3)->default(0);
            $table->unsignedInteger(Title::FIELD_RATING_COUNT)->default(0);
            $table->text(Title::FIELD_EMBEDDING)->nullable();
            $table->boolean(Title::FIELD_IS_INCOMPLETE)->default(false);
            $table->string(Title::FIELD_UPDATED_BY)->default('process');
            $table->timestamp(Title::FIELD_LAST_SYNCED_AT)->nullable();
            $table->timestamps();
        });

        Schema::create(AttributeOption::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->foreignId(AttributeOption::FIELD_ATTRIBUTE_ID)
                ->constrained(AttributeDefinition::TABLE_NAME)
                ->cascadeOnDelete();
            $table->string(AttributeOption::FIELD_VALUE_RU);
            $table->string(AttributeOption::FIELD_VALUE_EN)->nullable();
            $table->timestamps();
            $table->unique([AttributeOption::FIELD_ATTRIBUTE_ID, AttributeOption::FIELD_VALUE_RU]);
        });

        Schema::create(TitleAttribute::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->foreignId(TitleAttribute::FIELD_TITLE_ID)->constrained(Title::TABLE_NAME)->cascadeOnDelete();
            $table->foreignId(TitleAttribute::FIELD_ATTRIBUTE_ID)
                ->constrained(AttributeDefinition::TABLE_NAME)
                ->cascadeOnDelete();
            $table->string(TitleAttribute::FIELD_VALUE_TEXT)->nullable();
            $table->json(TitleAttribute::FIELD_VALUE_ARRAY)->nullable();
            $table->decimal(TitleAttribute::FIELD_VALUE_NUMBER, 15, 4)->nullable();
            $table->boolean(TitleAttribute::FIELD_VALUE_BOOLEAN)->nullable();
            $table->text(TitleAttribute::FIELD_SEARCHABLE_TEXT)->nullable();
            $table->timestamps();
            $table->unique([TitleAttribute::FIELD_TITLE_ID, TitleAttribute::FIELD_ATTRIBUTE_ID]);
        });
    }
}
