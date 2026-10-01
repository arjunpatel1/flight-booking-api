<?php

namespace Tests\Unit\Support;

use Modules\Support\InputLimit;
use Tests\TestCase;

/**
 * Contract for the shared validation fragments.
 *
 * These assert rule *composition*, not validator behaviour. That is deliberate:
 * `Validator::make()` resolves messages through this platform's DB-backed
 * translation loader, which makes it unrunnable wherever the database is not
 * reachable. Composition is also what actually guards the mistake this file was
 * written for — `lineItems()` was once spread in place of `"required|array"`,
 * and because `array` is not an implicit rule, a missing key silently skipped
 * validation altogether.
 *
 * Behavioural checks (rejects 1.5, rejects 101, rejects >3dp) were verified
 * against the real validator and belong in a feature test once the DB is
 * available in CI.
 */
class InputLimitTest extends TestCase
{
    /**
     * Built in the test body, not a data provider: providers are static and run
     * before the application boots, so `config()` is not yet bound.
     *
     * @return array<string, array>
     */
    private function fragments(): array
    {
        return [
            'money' => InputLimit::money(),
            'money with min' => InputLimit::money(min: 0.01),
            'quantity' => InputLimit::quantity(),
            'quantity integer' => InputLimit::quantity(min: 1, integer: true),
            'percent' => InputLimit::percent(),
            'text' => InputLimit::text('note'),
            'bulkIds' => InputLimit::bulkIds(),
            'lineItems' => InputLimit::lineItems(),
        ];
    }

    /**
     * The regression guard. Presence is the form request's decision; a fragment
     * that carried or implied it would let a caller drop `required` by accident.
     */
    public function test_fragments_never_decide_whether_a_field_is_present(): void
    {
        foreach ($this->fragments() as $name => $fragment) {
            foreach (['required', 'nullable', 'sometimes', 'present', 'filled'] as $presence) {
                $this->assertNotContains(
                    $presence,
                    $fragment,
                    "{$name} must not carry '{$presence}' — presence belongs to the form request."
                );
            }
        }
    }

    public function test_every_fragment_imposes_an_upper_bound(): void
    {
        foreach ($this->fragments() as $name => $fragment) {
            $this->assertTrue(
                collect($fragment)->contains(fn ($rule) => is_string($rule) && str_starts_with($rule, 'max:')),
                "{$name} must impose a maximum — that is the whole purpose of a limit fragment."
            );
        }
    }

    public function test_money_is_numeric_bounded_and_precision_capped(): void
    {
        $rules = InputLimit::money(min: 0.01);

        $this->assertContains('numeric', $rules);
        $this->assertContains('min:0.01', $rules);
        $this->assertContains('max:' . config('validation.money.max'), $rules);
        $this->assertContains('decimal:0,' . config('validation.money.decimals'), $rules);
    }

    public function test_integer_quantity_excludes_fractions(): void
    {
        $whole = InputLimit::quantity(min: 1, integer: true);
        $fractional = InputLimit::quantity(min: 0.01);

        $this->assertContains('integer', $whole);
        $this->assertNotContains('numeric', $whole, 'Whole-unit quantities must not accept 1.5.');

        $this->assertContains('numeric', $fractional, 'Weighed goods legitimately need fractions.');
        $this->assertNotContains('integer', $fractional);
    }

    public function test_percent_is_bounded_to_a_real_percentage(): void
    {
        $rules = InputLimit::percent();

        $this->assertContains('min:0', $rules);
        $this->assertContains('max:100', $rules);
    }

    public function test_text_kinds_resolve_to_distinct_configured_ceilings(): void
    {
        $this->assertContains('max:' . config('validation.text.name'), InputLimit::text('name'));
        $this->assertContains('max:' . config('validation.text.note'), InputLimit::text('note'));
        $this->assertContains('max:' . config('validation.text.message'), InputLimit::text('message'));

        // An unknown kind must still be bounded rather than falling open.
        $this->assertContains('max:255', InputLimit::text('not-a-configured-kind'));
    }

    public function test_limits_come_from_config_so_a_deployment_can_tune_them(): void
    {
        config()->set('validation.money.max', 500);
        $this->assertContains('max:500', InputLimit::money());

        config()->set('validation.counts.bulk_ids', 7);
        $this->assertContains('max:7', InputLimit::bulkIds());
    }

    public function test_batch_fragments_require_at_least_one_element(): void
    {
        $this->assertContains('min:1', InputLimit::bulkIds());
        $this->assertContains('min:1', InputLimit::lineItems());
    }
}
