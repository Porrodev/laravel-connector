<?php

namespace Porro\Laravel\Activity;

use Illuminate\Support\Facades\DB;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\EntryStore;

/**
 * Blocklist or safelist entries in a table, so every server of a site
 * sees the same list.
 */
class DatabaseEntryStore implements EntryStore
{
    public function __construct(private string $table) {}

    public function all(): array
    {
        return DB::table($this->table)->orderBy('id')->get()
            ->map(fn (object $row) => Entry::fromArray((array) $row))
            ->all();
    }

    public function put(Entry $entry): void
    {
        $row = $entry->toArray();

        DB::table($this->table)->updateOrInsert(['cidr' => $row['cidr']], $row);
    }

    public function remove(string $cidr): void
    {
        DB::table($this->table)->where('cidr', $cidr)->delete();
    }
}
