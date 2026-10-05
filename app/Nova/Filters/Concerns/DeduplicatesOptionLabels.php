<?php

namespace App\Nova\Filters\Concerns;

use Illuminate\Support\Collection;

/**
 * Opzioni di un filtro Nova `etichetta => id` (oc:8676): a parità di
 * etichetta si aggiunge ` (#id)`, altrimenti le chiavi dell'array si
 * sovrascriverebbero; ordinamento naturale, senza distinzione di maiuscole.
 */
trait DeduplicatesOptionLabels
{
    /**
     * @param  Collection<int, array{id: int, label: string}>  $items
     * @return array<string, int>
     */
    protected function optionsFromLabels(Collection $items): array
    {
        $duplicates = $items->countBy('label')->filter(fn (int $count) => $count > 1);

        return $items
            ->map(fn (array $item) => $item + ['key' => isset($duplicates[$item['label']]) ? $item['label'].' (#'.$item['id'].')' : $item['label']])
            ->sort(fn (array $a, array $b) => strnatcasecmp($a['key'], $b['key']))
            ->mapWithKeys(fn (array $item) => [$item['key'] => $item['id']])
            ->all();
    }
}
