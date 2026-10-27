<?php
declare(strict_types=1);

const SHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const FORM3_ACTIVITY_ROWS = [44, 55];

function reportMonthStart(?string $value, DateTimeZone $timezone): DateTimeImmutable
{
    if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
        return new DateTimeImmutable($value . '-01', $timezone);
    }

    return new DateTimeImmutable('first day of this month midnight', $timezone);
}

// Regular Pagsamba, EWS and PNK services from the Suguan week that contains the 1st of the month.
function monthlyScheduleServices(PDO $pdo, DateTimeImmutable $month): array
{
    $services = loadSuguanWeek($pdo, suguanWeekStart($month->format('Y-m-d')))['services'];

    return array_values(array_filter(
        $services,
        static fn(array $service): bool => $service['regular'] && (isSuguanWorship($service) || $service['type'] === 'pnk')
    ));
}

// Officer counts for TSV Form 3, including inactive officers. Officers whose oath-taking falls after the month are not counted yet.
function form3OfficerCounts(array $officers, DateTimeImmutable $month): array
{
    $monthEnd = $month->modify('last day of this month');
    $current = 0;
    $newlySworn = 0;
    foreach ($officers as $officer) {
        $oathDate = $officer['oath_taking_date'] ? new DateTimeImmutable($officer['oath_taking_date'], $month->getTimezone()) : null;
        $oathYear = $oathDate ? (int) $oathDate->format('Y') : (preg_match('/\b(19|20)\d{2}\b/', (string) $officer['oath_taking_text'], $match) ? (int) $match[0] : null);
        if ($oathDate ? $oathDate > $monthEnd : ($oathYear !== null && $oathYear > (int) $month->format('Y'))) {
            continue;
        }
        $current++;
        if ($oathDate && $oathDate->format('Y-m') === $month->format('Y-m')) {
            $newlySworn++;
        }
    }

    return [
        'previous' => $current - $newlySworn,
        'transfer_in' => 0,
        'newly_sworn' => $newlySworn,
        'removed' => 0,
        'current' => $current,
    ];
}

function setXlsxCell(DOMXPath $xpath, string $reference, string|int|null $value): void
{
    $cell = $xpath->query("//s:sheetData/s:row/s:c[@r='{$reference}']")->item(0);
    if (!$cell instanceof DOMElement) {
        throw new RuntimeException("Cell {$reference} was not found in the TSV Form 3 template.");
    }
    while ($cell->firstChild) {
        $cell->removeChild($cell->firstChild);
    }
    $document = $cell->ownerDocument;
    if ($value === null || $value === '') {
        $cell->removeAttribute('t');
        return;
    }
    if (is_int($value)) {
        $cell->removeAttribute('t');
        $cell->appendChild($document->createElementNS(SHEET_NAMESPACE, 'v', (string) $value));
        return;
    }
    $cell->setAttribute('t', 'inlineStr');
    $inline = $document->createElementNS(SHEET_NAMESPACE, 'is');
    $text = $document->createElementNS(SHEET_NAMESPACE, 't');
    $text->appendChild($document->createTextNode($value));
    $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $inline->appendChild($text);
    $cell->appendChild($inline);
}

function generateForm3Xlsx(array $report, array $settings, string $templatePath): string
{
    $files = readDocxZip($templatePath);
    $sheet = new DOMDocument();
    $sheet->preserveWhiteSpace = true;
    if (!$sheet->loadXML($files['xl/worksheets/sheet1.xml'], LIBXML_NONET)) {
        throw new RuntimeException('Could not read the TSV Form 3 worksheet.');
    }
    $xpath = new DOMXPath($sheet);
    $xpath->registerNamespace('s', SHEET_NAMESPACE);

    $month = $report['month'];
    $counts = $report['counts'];
    setXlsxCell($xpath, 'A7', 'Distrito: ' . placeNameText($settings['district_name']));
    setXlsxCell($xpath, 'A8', 'Lokal: ' . placeNameText($settings['locale_name']));
    setXlsxCell($xpath, 'A9', 'Buwan: ' . $month->format('F'));
    setXlsxCell($xpath, 'A10', 'Taon: ' . $month->format('Y'));
    setXlsxCell($xpath, 'G14', $report['weekend_services']);
    setXlsxCell($xpath, 'G21', $counts['previous']);
    setXlsxCell($xpath, 'G22', $counts['transfer_in']);
    setXlsxCell($xpath, 'G23', $counts['newly_sworn']);
    setXlsxCell($xpath, 'G24', $counts['removed']);
    setXlsxCell($xpath, 'G25', $counts['current']);
    // Drop the cached percentage so Excel recalculates it on open.
    foreach ($xpath->query("//s:c[@r='G26']/s:v") as $cachedValue) {
        $cachedValue->parentNode->removeChild($cachedValue);
    }
    setXlsxCell($xpath, 'A41', $report['webex_name']);
    setXlsxCell($xpath, 'C41', $report['webex_count'] === '' ? null : (ctype_digit($report['webex_count']) ? (int) $report['webex_count'] : $report['webex_count']));
    setXlsxCell($xpath, 'F41', $report['webex_reason']);

    [$firstActivityRow, $lastActivityRow] = FORM3_ACTIVITY_ROWS;
    for ($row = $firstActivityRow; $row <= $lastActivityRow; $row++) {
        $activity = $report['activities'][$row - $firstActivityRow] ?? ['', '', ''];
        setXlsxCell($xpath, "A{$row}", $activity[0]);
        setXlsxCell($xpath, "C{$row}", $activity[1]);
        setXlsxCell($xpath, "F{$row}", $activity[2]);
    }

    setXlsxCell($xpath, 'A59', (string) ($settings['tsv_lead'] ?? ''));
    setXlsxCell($xpath, 'C59', (string) ($settings['pd_tagasubaybay'] ?? ''));
    setXlsxCell($xpath, 'F59', (string) ($settings['pastor'] ?? ''));

    $files['xl/worksheets/sheet1.xml'] = $sheet->saveXML();
    $files['xl/workbook.xml'] = str_replace('<calcPr calcId="191029"/>', '<calcPr calcId="191029" fullCalcOnLoad="1"/>', $files['xl/workbook.xml']);

    return zipFiles($files);
}

function generateDirectoryReportDocx(array $schedule, array $settings, DateTimeImmutable $month, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($schedule, $settings, $month): void {
            $paragraphs = iterator_to_array($xpath->query('/w:document/w:body/w:p'));
            replaceWordText($xpath, $paragraphs[2], 'Marikina', placeNameText($settings['district_name']));
            replaceWordText($xpath, $paragraphs[3], 'FYM Avenue', placeNameText($settings['locale_name']));
            // The letter is dated the day it is generated; the schedule still comes from the chosen month.
            setWordText($xpath, $paragraphs[5], 'Petsa: ' . (new DateTimeImmutable('today', $month->getTimezone()))->format('F d, Y'));
            if (trim((string) ($settings['district_supervisor'] ?? '')) !== '') {
                replaceWordText($xpath, $paragraphs[7], 'CYRUS JOSE E. DIZON', mb_strtoupper($settings['district_supervisor']));
            }
            $signatureLine = current(array_filter(
                $paragraphs,
                static fn(DOMElement $paragraph): bool => str_contains($paragraph->textContent, 'Benjamin Arceo Jr.')
            ));
            foreach (['Charles Andrei Galvan' => 'tsv_lead', 'Benjamin Arceo Jr.' => 'pastor'] as $needle => $settingKey) {
                if ($signatureLine && trim((string) ($settings[$settingKey] ?? '')) !== '') {
                    replaceWordText($xpath, $signatureLine, $needle, $settings[$settingKey]);
                }
            }

            $table = docxTableAt($xpath, 0);
            $rows = array_slice(docxRows($xpath, $table), 2);
            while (count($rows) < count($schedule)) {
                $copy = end($rows)->cloneNode(true);
                $table->appendChild($copy);
                $rows[] = $copy;
            }
            foreach ($rows as $index => $row) {
                $service = $schedule[$index] ?? null;
                $values = $service === null ? ['', '', ''] : [
                    (new DateTimeImmutable($service['date']))->format('l') . ($service['type'] === 'pnk' ? ' (PNK)' : ''),
                    DateTimeImmutable::createFromFormat('!H:i', $service['time'])->format('g:iA'),
                    $service['ews'] ? 'English' : 'Tagalog',
                ];
                foreach ($values as $offset => $value) {
                    setWordText($xpath, docxCell($xpath, $row, 3 + $offset), $value);
                }
            }
        }
    );
}
