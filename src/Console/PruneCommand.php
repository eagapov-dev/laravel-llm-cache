<?php

namespace Yegoragapov\LlmCache\Console;

use Illuminate\Console\Command;
use Yegoragapov\LlmCache\Contracts\VectorStore;

/**
 * Reclaim expired cache entries from the active vector store.
 *
 * Expired entries are already excluded from lookups, but nothing deletes them
 * on the read path — over time they bloat the store and its ANN index (and, for
 * Redis, leak memory). Schedule this daily.
 */
class PruneCommand extends Command
{
    protected $signature = 'llm-cache:prune';

    protected $description = 'Delete expired llm-cache entries from the configured vector store.';

    public function handle(VectorStore $store): int
    {
        $removed = $store->purgeExpired();

        $this->info("llm-cache: purged {$removed} expired " . ($removed === 1 ? 'entry' : 'entries') . '.');

        return self::SUCCESS;
    }
}
