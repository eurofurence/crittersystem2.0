<?php

namespace App\Form\Model;

use App\Service\Statistics\Tallies;
use App\Service\Statistics\TallyCatalog;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The hand-counted figures behind the fun section, as the admin form edits them.
 *
 * Every figure is optional. A blank one is not zero: it means this event did not count that thing,
 * and the dashboard leaves it out entirely rather than announcing "0 cups of coffee".
 */
final class StatisticsTalliesData
{
    /**
     * Catalog slug => amount, with every known slug present so the form renders a field for each.
     *
     * @var array<string, float|null>
     */
    #[Assert\All([new Assert\PositiveOrZero()])]
    public array $known = [];

    /** @var list<CustomTallyData> */
    #[Assert\Valid]
    public array $custom = [];

    #[Assert\PositiveOrZero]
    public ?float $hourlyRate = null;

    #[Assert\Length(max: 3)]
    public ?string $currency = null;

    public static function fromTallies(Tallies $tallies): self
    {
        $data = new self();
        foreach (TallyCatalog::slugs() as $slug) {
            $data->known[$slug] = $tallies->get($slug);
        }

        foreach ($tallies->custom as $row) {
            $custom = new CustomTallyData();
            $custom->label = $row['label'];
            $custom->amount = $row['amount'];
            $data->custom[] = $custom;
        }

        $data->hourlyRate = $tallies->hourlyRate;
        $data->currency = $tallies->currency;

        return $data;
    }

    public function toTallies(): Tallies
    {
        $known = [];
        foreach ($this->known as $slug => $amount) {
            if ($amount !== null && TallyCatalog::has((string) $slug)) {
                $known[(string) $slug] = (float) $amount;
            }
        }

        $custom = [];
        foreach ($this->custom as $row) {
            if ($row->isComplete()) {
                $custom[] = ['label' => trim((string) $row->label), 'amount' => (float) $row->amount];
            }
        }

        return new Tallies(
            known: $known,
            custom: $custom,
            hourlyRate: $this->hourlyRate,
            currency: $this->currency !== null && trim($this->currency) !== '' ? strtoupper(trim($this->currency)) : 'EUR',
        );
    }
}
