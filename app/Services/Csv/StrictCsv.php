<?php

namespace App\Services\Csv;

use Illuminate\Validation\ValidationException;

/** Bounded parser: preserves quoted newlines and rejects malformed CSV. */
final class StrictCsv
{
    public function parse(string $text, string $quoteStyle): array
    {
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            $this->fail('Soubor musí obsahovat platný text UTF-8 bez nulových bajtů.');
        }
        $text = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
        $rows = [];
        $fields = [];
        $field = '';
        $state = 'start';
        $line = 1;
        $startLine = 1;
        $start = 0;
        $length = strlen($text);
        $finishField = function () use (&$fields, &$field, &$state) {
            if (count($fields) >= 64 || strlen($field) > 100000) {
                $this->fail('Příliš mnoho sloupců nebo příliš dlouhé pole.');
            }
            $fields[] = $field;
            $field = '';
            $state = 'start';
        };
        $finishRow = function (int $end) use (&$rows, &$fields, &$startLine, &$start, $text, $finishField) {
            $finishField();
            if ($fields !== ['']) {
                $rows[] = ['fields' => $fields, 'line' => $startLine, 'raw' => substr($text, $start, $end - $start)];
            }
            $fields = [];
            if (count($rows) > 5001) {
                $this->fail('Nejvýše 5 000 datových záznamů v jedné dávce.');
            }
        };
        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            if ($state === 'quoted') {
                if ($quoteStyle === 'backslash' && $c === '\\' && $i + 1 < $length && in_array($text[$i + 1], ['"', '\\'], true)) {
                    $field .= $text[++$i];
                } elseif ($c === '"') {
                    if ($quoteStyle === 'double' && $i + 1 < $length && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                } elseif ($c === "\r" || $c === "\n") {
                    $field .= $c;
                    if ($c === "\r" && $i + 1 < $length && $text[$i + 1] === "\n") {
                        $field .= $text[++$i];
                    }
                    $line++;
                } else {
                    $field .= $c;
                }

                continue;
            }
            if ($c === ';') {
                $finishField();

                continue;
            }
            if ($c === "\r" || $c === "\n") {
                $finishRow($i);
                if ($c === "\r" && $i + 1 < $length && $text[$i + 1] === "\n") {
                    $i++;
                }
                $line++;
                $startLine = $line;
                $start = $i + 1;

                continue;
            }
            if ($state === 'closed') {
                if ($c === ' ' || $c === "\t") {
                    continue;
                }
                $this->fail("Neočekávaný znak za uvozovkou na řádku $line. Ověřte profil uvozovek.");
            }
            if ($c === '"') {
                if ($state !== 'start') {
                    $this->fail("Neescapovaná uvozovka na řádku $line.");
                }
                $state = 'quoted';
            } else {
                $field .= $c;
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            $this->fail("Neuzavřené uvozovky od řádku $startLine.");
        }
        if ($start < $length) {
            $finishRow($length);
        }
        if (count($rows) < 2) {
            $this->fail('Soubor musí obsahovat záhlaví a alespoň jeden datový záznam.');
        }

        return $rows;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
