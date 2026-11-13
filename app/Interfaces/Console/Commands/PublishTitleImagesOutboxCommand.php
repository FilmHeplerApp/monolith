<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Infrastructure\Media\Outbox\PublishTitleImagesOutbox;
use Illuminate\Console\Command;

final class PublishTitleImagesOutboxCommand extends Command
{
    protected $signature = 'images:publish-outbox {--limit=100 : Maximum number of outbox entries to examine}';
    protected $description = 'Publish durable title image requests to the image queue';


    public function handle(PublishTitleImagesOutbox $publisher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            $this->error('Limit must be an integer between 1 and 10000.');

            return self::FAILURE;
        }
        $this->info('Published image requests: '.$publisher->publish($limit));

        return self::SUCCESS;
    }
}
