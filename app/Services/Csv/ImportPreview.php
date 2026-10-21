<?php

namespace App\Services\Csv;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportPreview
{
    private array $catalogs;

    private array $issues = [];

    private string $separator;

    public function parse(string $csv, array $options, ?object $stream): array
    {
        $this->separator = $options['decimal_separator'];
        foreach (['currencies', 'fuel_sorts', 'fueling_types', 'expense_types', 'tire_types', 'driving_styles', 'repetition_intervals'] as $table) {
            $key = $table === 'currencies' ? 'code' : 'id';
            $this->catalogs[$table] = DB::table($table)->get()->keyBy($key)->all();
        }
        $records = (new StrictCsv)->parse($csv, $options['profile'] === 'spritmonitor_rfc' ? 'double' : 'backslash');
        $header = array_shift($records)['fields'];
        $map = [];
        $warnings = [];
        foreach ($header as $index => $name) {
            if (trim($name) === '' || mb_strlen($name) > 100) {
                $this->fail('Prázdný nebo příliš dlouhý název sloupce.');
            }
            $key = $this->header($name, $options['kind']);
            if (in_array($key, $map, true)) {
                $this->fail('Opakované nebo nejednoznačné záhlaví: '.$name);
            }
            $map[$index] = $key;
            if (str_starts_with($key, 'unknown:')) {
                $warnings[] = 'Nepoužitý sloupec zůstane zachován v původních datech: '.$name;
            }
        }
        if ($options['kind'] === 'expense' && in_array('quantity', $map, true) && in_array('fuel', $map, true)) {
            $this->fail('Soubor obsahuje Quantity a Fuel: zvolte tankování/nabíjení místo ostatních výdajů.');
        }
        $required = ['date', 'amount', 'currency', ...($options['kind'] === 'fueling' ? ['quantity', 'fueling_type', 'fuel'] : ['expense_type'])];
        foreach ($required as $key) {
            if (! in_array($key, $map, true)) {
                $this->fail('Chybí povinný sloupec: '.$key);
            }
        }
        if ($options['kind'] === 'expense' && ! in_array('repetition', $map, true)) {
            $warnings[] = 'Sloupec Repetition chybí; použije se interval 0.';
        }
        $rows = [];
        $seen = [];
        foreach ($records as $index => $record) {
            $this->issues = [];
            $source = [];
            $values = [];
            if (count($record['fields']) !== count($header)) {
                $this->issue('row', 'column_count', 'Počet hodnot neodpovídá záhlaví.');
                $data = ['event' => ['kind' => $options['kind']], 'detail' => []];
            } else {
                foreach ($map as $column => $key) {
                    $values[$key] = $record['fields'][$column];
                    $source[$header[$column]] = $record['fields'][$column];
                }
                $data = $this->normalize($values, $options, $stream);
            }
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (! $this->hasErrors()) {
                if (isset($seen[$fingerprint])) {
                    $this->issue('row', 'duplicate_in_file', 'Shodný obsah jako záznam '.$seen[$fingerprint].'. Zkontrolujte duplicitu.', 'warning');
                } else {
                    $seen[$fingerprint] = $index + 1;
                }
            }
            $rows[] = ['record_number' => $index + 1, 'physical_line_start' => $record['line'], 'raw_record' => $record['raw'], 'source_values' => $source, 'normalized' => $data, 'fingerprint' => $fingerprint, 'issues' => $this->issues];
        }
        // Compare chronology independently of the descending source order.
        $order = array_keys($rows);
        usort($order, fn ($a, $b) => strcmp($rows[$a]['normalized']['event']['occurred_on'] ?? '', $rows[$b]['normalized']['event']['occurred_on'] ?? ''));
        $previous = null;
        foreach ($order as $index) {
            $row = &$rows[$index];
            $event = $row['normalized']['event'];
            if (! empty($event['occurred_on']) && isset($event['odometer'])) {
                if ($previous && $event['occurred_on'] > $previous['occurred_on']) {
                    $delta = BigDecimal::of($event['odometer'])->minus($previous['odometer']);
                    if ($delta->isLessThan(0)) {
                        $row['issues'][] = ['field' => 'Odometer', 'code' => 'odometer_decrease', 'severity' => 'warning', 'message' => 'Tachometr je nižší než u předchozího záznamu v souboru.'];
                    }
                    $trip = $row['normalized']['detail']['reported_trip'] ?? null;
                    if ($options['kind'] === 'fueling' && $trip !== null && $delta->isGreaterThan(0) && $delta->minus($trip)->abs()->isGreaterThan(1)) {
                        $row['issues'][] = ['field' => 'Trip', 'code' => 'trip_difference', 'severity' => 'warning', 'message' => 'Trip se od rozdílu tachometrů liší o více než 1 jednotku vzdálenosti.'];
                    }
                }
                $previous = $event;
            }
            unset($row);
        }
        foreach ($rows as &$row) {
            $row['status'] = $this->status($row['issues']);
        }

        return ['rows' => $rows, 'warnings' => $warnings];
    }

    private function header(string $name, string $kind): string
    {
        $name = mb_strtolower(trim($name));

        return [
            'date' => 'date', 'odometer' => 'odometer', 'trip' => 'trip', 'distance' => 'trip', 'quantity' => 'quantity',
            'total price' => 'amount', 'currency' => 'currency', 'type' => $kind === 'fueling' ? 'fueling_type' : 'expense_type',
            'fueling type' => 'fueling_type', 'cost type' => 'expense_type', 'tires' => 'tires', 'roads' => 'roads',
            'driving style' => 'driving_style', 'fuel' => 'fuel', 'fuel sort' => 'fuel', 'note' => 'note',
            'consumption' => 'consumption', 'bc-consumption' => 'bc_consumption', 'bc-quantity' => 'bc_quantity', 'bc-speed' => 'bc_speed',
            'company' => 'company', 'country' => 'country', 'area' => 'area', 'location' => 'location', 'latitude' => 'latitude', 'longitude' => 'longitude', 'repetition' => 'repetition',
        ][$name] ?? 'unknown:'.$name;
    }

    private function normalize(array $v, array $options, ?object $stream): array
    {
        $date = trim($v['date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!d.m.Y', $date);
        if (! $parsed || $parsed->format('d.m.Y') !== $date || (int) $parsed->format('Y') < 1000) {
            $this->issue('Date', 'invalid_date', 'Datum musí být platné DD.MM.YYYY.');
            $parsed = null;
        }
        $currency = strtoupper(trim($v['currency'] ?? ''));
        if (! isset($this->catalogs['currencies'][$currency])) {
            $this->issue('Currency', 'unknown_currency', 'Neznámá měna; nejprve je nutné doplnit číselník.');
        }
        $note = $v['note'] ?? null;
        if ($note !== null && (mb_strlen($note) > 20000 || strlen($note) > 60000)) {
            $this->issue('Note', 'note_length', 'Poznámka je příliš dlouhá.');
        }
        $event = ['kind' => $options['kind'], 'occurred_on' => $parsed?->format('Y-m-d'), 'odometer' => $this->number($v, 'odometer', 14, 3), 'amount' => $this->number($v, 'amount', 18, 4, true, true), 'currency_code' => $currency, 'note' => $note === '' ? null : $note];
        $detail = ['reported_trip' => $this->number($v, 'trip', 14, 3)];
        if ($options['kind'] === 'expense') {
            $type = trim($v['expense_type'] ?? '');
            $typeId = null;
            foreach ($this->catalogs['expense_types'] as $id => $entry) {
                if ((ctype_digit($type) && (string) (int) $type === (string) $id) || mb_strtolower($type) === mb_strtolower($entry->name)) {
                    $typeId = $id;
                }
            }
            if ($typeId === null) {
                $this->issue('Cost type', 'unknown_type', 'Neznámá kategorie výdaje.');
            }
            $detail['expense_type_id'] = $typeId;
            if (isset($v['repetition']) && trim($v['repetition']) === '') {
                $this->issue('Repetition', 'empty_repetition', 'Prázdné opakování se interpretuje jako 0.', 'warning');
            }
            $detail['repetition_months'] = trim($v['repetition'] ?? '') === '' ? 0 : $this->code($v, 'repetition', 'repetition_intervals', true);
            if (in_array($typeId, [16, 23], true) && $event['amount'] !== null && BigDecimal::of($event['amount'])->isGreaterThan(0)) {
                $this->issue('Total price', 'credit_sign', 'Kladná částka u vratky nebo bonusu: ověřte znaménko.', 'warning');
            }
        } else {
            $detail['fuel_sort_id'] = $this->code($v, 'fuel', 'fuel_sorts', true);
            $detail['fueling_type_id'] = $this->code($v, 'fueling_type', 'fueling_types', true);
            $detail['quantity'] = $this->number($v, 'quantity', 14, 4, true);
            if ($detail['quantity'] !== null && BigDecimal::of($detail['quantity'])->isZero()) {
                $this->issue('Quantity', 'zero_quantity', 'Množství musí být kladné.');
            }
            $fuel = $this->catalogs['fuel_sorts'][$detail['fuel_sort_id']] ?? null;
            if ($fuel && ($fuel->medium_group !== $stream->medium_group || $fuel->quantity_unit !== $options['quantity_unit'])) {
                $this->issue('Fuel', 'stream_mismatch', 'Palivo nebo jednotka neodpovídají vybrané nádrži/médiu.');
            }
            $detail['energy_stream_id'] = (string) $stream->id;
            $detail['quantity_unit'] = $options['quantity_unit'];
            $detail['consumption_distance_unit'] = $options['distance_unit'];
            $detail['speed_unit'] = $options['distance_unit'].'/h';
            $detail['tire_type_id'] = $this->code($v, 'tires', 'tire_types');
            $detail['driving_style_id'] = $this->code($v, 'driving_style', 'driving_styles');
            $roads = trim($v['roads'] ?? '');
            $mask = $roads === '' ? 0 : (ctype_digit($roads) && strlen($roads) < 4 ? (int) $roads : -1);
            if (! in_array($mask, [0, 2, 4, 6, 8, 10, 12, 14], true)) {
                $this->issue('Roads', 'unknown_roads', 'Neplatná bitová maska silnic.');
            }
            $detail['roads'] = $mask < 0 ? [] : array_values(array_filter([2, 4, 8], fn ($bit) => (bool) ($mask & $bit)));
            $detail['reported_consumption'] = $this->number($v, 'consumption', 14, 4);
            $zeros = [];
            foreach (['bc_consumption', 'bc_quantity', 'bc_speed'] as $key) {
                $detail[$key] = $this->number($v, $key, 14, 4);
                if ($options['profile'] !== 'ccm' && $detail[$key] !== null && BigDecimal::of($detail[$key])->isZero()) {
                    $detail[$key] = null;
                    $zeros[] = $key;
                }
            }
            if ($zeros) {
                $this->issue('BC', 'bc_zero', 'Nulové BC údaje byly podle profilu SpritMonitor převedeny na nezadané: '.implode(', ', $zeros).'.', 'warning');
            }
            foreach (['company', 'area', 'location'] as $key) {
                $detail[$key] = ($v[$key] ?? '') === '' ? null : $v[$key];
                if (mb_strlen($detail[$key] ?? '') > 255) {
                    $this->issue($key, 'text_length', 'Text může mít nejvýše 255 znaků.');
                }
            }
            $country = strtoupper(trim($v['country'] ?? ''));
            if ($country === 'SLO') {
                $country = 'SI';
                $this->issue('Country', 'country_alias', 'SLO se normalizuje na SI (Slovinsko).', 'warning');
            }
            $iso = explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW');
            if ($country !== '' && ! in_array($country, $iso, true)) {
                $this->issue('Country', 'unknown_country', 'Neznámý kód země.');
            }
            $detail['country_code'] = $country === '' ? null : $country;
            $detail['latitude'] = $this->number($v, 'latitude', 9, 6, false, true);
            $detail['longitude'] = $this->number($v, 'longitude', 10, 6, false, true);
            foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
                if ($detail[$key] !== null && BigDecimal::of($detail[$key])->abs()->isGreaterThan($limit)) {
                    $this->issue($key, 'coordinate_range', 'Souřadnice je mimo povolený rozsah.');
                }
            }
            if (($detail['latitude'] === null) !== ($detail['longitude'] === null)) {
                $this->issue('Coordinates', 'coordinate_pair', 'Vyplňte obě souřadnice, nebo žádnou.');
            }
            if ($event['odometer'] === null) {
                $this->issue('Odometer', 'missing_odometer', 'Bez tachometru nelze vypočítat spotřebu plná–plná.', 'warning');
            }
        }

        return ['event' => $event, 'detail' => $detail];
    }

    private function number(array $v, string $field, int $precision, int $scale, bool $required = false, bool $signed = false): ?string
    {
        $value = trim($v[$field] ?? '');
        if ($value === '') {
            if ($required) {
                $this->issue($field, 'required', 'Povinná číselná hodnota chybí.');
            }

            return null;
        }
        if (strlen($value) > 64 || ! preg_match('/^-?\d+(?:'.preg_quote($this->separator, '/').'\d+)?$/D', $value)) {
            $this->issue($field, 'invalid_number', 'Neplatné číslo; ověřte desetinný oddělovač.');

            return null;
        }
        try {
            $number = BigDecimal::of(str_replace($this->separator, '.', $value))->toScale($scale, RoundingMode::Unnecessary);
            if ((! $signed && $number->isLessThan(0)) || $number->abs()->isGreaterThan(str_repeat('9', $precision - $scale).'.'.str_repeat('9', $scale))) {
                throw new \InvalidArgumentException;
            }

            return (string) $number;
        } catch (\Throwable) {
            $this->issue($field, 'number_range', 'Nepovolené znaménko, rozsah nebo přesnost čísla.');

            return null;
        }
    }

    private function code(array $v, string $key, string $table, bool $required = false): ?int
    {
        $value = trim($v[$key] ?? '');
        if ($value === '' && ! $required) {
            return null;
        }
        if (! ctype_digit($value) || strlen($value) > 8 || ! isset($this->catalogs[$table][(int) $value])) {
            $this->issue($key, 'unknown_code', 'Neznámý nebo chybějící kód číselníku.');

            return null;
        }

        return (int) $value;
    }

    private function issue(string $field, string $code, string $message, string $severity = 'error'): void
    {
        $field = ['amount' => 'Total price', 'quantity' => 'Quantity', 'odometer' => 'Odometer', 'trip' => 'Trip', 'fuel' => 'Fuel', 'fueling_type' => 'Type', 'tires' => 'Tires', 'driving_style' => 'Driving style', 'repetition' => 'Repetition', 'consumption' => 'Consumption', 'bc_consumption' => 'BC-Consumption', 'bc_quantity' => 'BC-Quantity', 'bc_speed' => 'BC-Speed', 'latitude' => 'Latitude', 'longitude' => 'Longitude'][$field] ?? $field;
        $this->issues[] = compact('field', 'code', 'message', 'severity');
    }

    private function hasErrors(): bool
    {
        return $this->status($this->issues) === 'error';
    }

    private function status(array $issues): string
    {
        return in_array('error', array_column($issues, 'severity'), true) ? 'error' : ($issues ? 'warning' : 'valid');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
