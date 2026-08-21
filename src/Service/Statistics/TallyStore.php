<?php

namespace App\Service\Statistics;

use App\Service\EventConfigStore;

/**
 * Reads and writes the hand-counted event figures.
 *
 * Everything is validated on the way back out rather than trusted, because these values sit in a
 * JSON configuration column that survives across releases: a slug retired from the catalog, or a
 * row left half-filled by an older version of the form, must not break the dashboard on the day it
 * is presented. Unknown slugs, blank labels and non-numeric amounts are dropped silently.
 *
 * Negative amounts are rejected too. None of these figures can be negative, and a stray minus sign
 * would otherwise reach a headline slide.
 */
final class TallyStore
{
    public function __construct(private readonly EventConfigStore $config)
    {
    }

    public function load(): Tallies
    {
        return new Tallies(
            known: $this->readKnown(),
            custom: $this->readCustom(),
            hourlyRate: $this->readRate(),
            currency: $this->config->getString(EventConfigStore::KEY_STATS_CURRENCY, EventConfigStore::DEFAULT_STATS_CURRENCY)
                ?: EventConfigStore::DEFAULT_STATS_CURRENCY,
        );
    }

    public function save(Tallies $tallies): void
    {
        $known = [];
        foreach ($tallies->known as $slug => $amount) {
            if (TallyCatalog::has((string) $slug) && $amount >= 0) {
                $known[(string) $slug] = (float) $amount;
            }
        }

        $custom = [];
        foreach ($tallies->custom as $row) {
            $label = trim($row['label'] ?? '');
            if ($label !== '' && ($row['amount'] ?? -1) >= 0) {
                $custom[] = ['label' => $label, 'amount' => (float) $row['amount']];
            }
        }

        $this->config->set(EventConfigStore::KEY_STATS_TALLIES, $known);
        $this->config->set(EventConfigStore::KEY_STATS_CUSTOM_TALLIES, $custom);
        $this->config->set(EventConfigStore::KEY_STATS_HOURLY_RATE, $tallies->hourlyRate);
        $this->config->set(EventConfigStore::KEY_STATS_CURRENCY, $tallies->currency);
        $this->config->flush();
    }

    /** @return array<string, float> */
    private function readKnown(): array
    {
        $stored = $this->config->get(EventConfigStore::KEY_STATS_TALLIES);
        if (!\is_array($stored)) {
            return [];
        }

        $known = [];
        foreach ($stored as $slug => $amount) {
            if (TallyCatalog::has((string) $slug) && is_numeric($amount) && $amount >= 0) {
                $known[(string) $slug] = (float) $amount;
            }
        }

        return $known;
    }

    /** @return list<array{label: string, amount: float}> */
    private function readCustom(): array
    {
        $stored = $this->config->get(EventConfigStore::KEY_STATS_CUSTOM_TALLIES);
        if (!\is_array($stored)) {
            return [];
        }

        $custom = [];
        foreach ($stored as $row) {
            if (!\is_array($row) || !\is_string($row['label'] ?? null) || !is_numeric($row['amount'] ?? null)) {
                continue;
            }
            $label = trim($row['label']);
            if ($label === '' || $row['amount'] < 0) {
                continue;
            }
            $custom[] = ['label' => $label, 'amount' => (float) $row['amount']];
        }

        return $custom;
    }

    private function readRate(): ?float
    {
        $stored = $this->config->get(EventConfigStore::KEY_STATS_HOURLY_RATE);

        return is_numeric($stored) && $stored > 0 ? (float) $stored : null;
    }
}
