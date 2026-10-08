<?php
declare(strict_types=1);

const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function appendWordText(DOMXPath $xpath, DOMNode $node, string $value): void
{
    if ($value === '') {
        return;
    }
    $textNodes = [];
    foreach ($xpath->query('.//w:t', $node) as $textNode) {
        $textNodes[] = $textNode;
    }
    if ($textNodes) {
        $lastTextNode = end($textNodes);
        $lastTextNode->nodeValue = $lastTextNode->textContent . $value;
        $lastTextNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        return;
    }

    $paragraph = $node instanceof DOMElement && $node->localName === 'p'
        ? $node
        : $xpath->query('.//w:p', $node)->item(0);
    if (!$paragraph instanceof DOMElement) {
        throw new RuntimeException('A DOCX template field has no paragraph to fill.');
    }
    $run = $paragraph->ownerDocument->createElementNS(WORD_NAMESPACE, 'w:r');
    $textNode = $paragraph->ownerDocument->createElementNS(WORD_NAMESPACE, 'w:t');
    $textNode->appendChild($paragraph->ownerDocument->createTextNode($value));
    $run->appendChild($textNode);
    $paragraph->appendChild($run);
}

function setWordText(DOMXPath $xpath, DOMNode $node, string $value): void
{
    $textNodes = [];
    foreach ($xpath->query('.//w:t', $node) as $textNode) {
        $textNodes[] = $textNode;
    }

    if (!$textNodes) {
        appendWordText($xpath, $node, $value);
        return;
    }

    foreach ($textNodes as $index => $textNode) {
        $textNode->nodeValue = $index === 0 ? $value : '';
        if ($index === 0 && preg_match('/^\s|\s$/u', $value)) {
            $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        }
    }
}

function replaceWordText(
    DOMXPath $xpath,
    DOMNode $node,
    string $needle,
    string $replacement,
    bool $appendWhenMissing = false
): void {
    $textNodes = [];
    $fullText = '';
    foreach ($xpath->query('.//w:t', $node) as $textNode) {
        $textNodes[] = $textNode;
        $fullText .= $textNode->textContent;
    }

    if (!$textNodes) {
        if ($appendWhenMissing) {
            appendWordText($xpath, $node, $replacement);
            return;
        }
        throw new RuntimeException('A required text field was not found in a DOCX template.');
    }

    $matchStart = strpos($fullText, $needle);
    if ($matchStart === false) {
        if ($appendWhenMissing) {
            appendWordText($xpath, $node, $replacement);
            return;
        }
        throw new RuntimeException('A required template placeholder was not found: ' . $needle);
    }

    $matchEnd = $matchStart + strlen($needle);
    $globalOffset = 0;
    $inserted = false;
    foreach ($textNodes as $textNode) {
        $text = $textNode->textContent;
        $nodeEnd = $globalOffset + strlen($text);
        $overlapStart = max($matchStart, $globalOffset);
        $overlapEnd = min($matchEnd, $nodeEnd);

        if ($overlapStart < $overlapEnd) {
            $localStart = $overlapStart - $globalOffset;
            $localEnd = $overlapEnd - $globalOffset;
            $prefix = substr($text, 0, $localStart);
            $suffix = substr($text, $localEnd);
            $textNode->nodeValue = $prefix . ($inserted ? '' : $replacement) . $suffix;
            $inserted = true;
            if (preg_match('/^\s|\s$/u', (string) $textNode->nodeValue)) {
                $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            }
        }
        $globalOffset = $nodeEnd;
    }
}

function docxTableAt(DOMXPath $xpath, int $index): DOMElement
{
    $table = $xpath->query('/w:document/w:body/w:tbl')->item($index);
    if (!$table instanceof DOMElement) {
        throw new RuntimeException('A required table was not found in a DOCX template.');
    }

    return $table;
}

function docxRows(DOMXPath $xpath, DOMElement $table): array
{
    $rows = [];
    foreach ($xpath->query('./w:tr', $table) as $row) {
        if ($row instanceof DOMElement) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function docxCell(DOMXPath $xpath, DOMElement $row, int $index): DOMElement
{
    $cell = $xpath->query('./w:tc', $row)->item($index);
    if (!$cell instanceof DOMElement) {
        throw new RuntimeException('A required table cell was not found in a DOCX template.');
    }

    return $cell;
}

function generateDocxFromTemplate(string $templatePath, callable $populate): string
{
    if (!is_file($templatePath) || !class_exists(PharData::class) || !class_exists(DOMDocument::class)) {
        throw new RuntimeException('A required DOCX template or PHP document-processing extension is unavailable.');
    }

    $temporaryBase = tempnam(sys_get_temp_dir(), 'tsv-docx-');
    if ($temporaryBase === false) {
        throw new RuntimeException('Could not create a temporary DOCX file.');
    }
    $temporaryPath = $temporaryBase . '.docx';
    if (!rename($temporaryBase, $temporaryPath)) {
        unlink($temporaryBase);
        throw new RuntimeException('Could not prepare a temporary DOCX file.');
    }

    try {
        if (!copy($templatePath, $temporaryPath)) {
            throw new RuntimeException('Could not copy the DOCX template for generation.');
        }

        $archive = new PharData($temporaryPath, 0, null, Phar::ZIP);
        if (!isset($archive['word/document.xml'])) {
            throw new RuntimeException('The DOCX template is missing its main document part.');
        }
        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        if (!$document->loadXML($archive['word/document.xml']->getContent(), LIBXML_NONET)) {
            throw new RuntimeException('Could not parse the main document in a DOCX template.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', WORD_NAMESPACE);
        $populate($xpath, $document);
        $archive['word/document.xml'] = $document->saveXML();
        unset($archive);
        clearstatcache(true, $temporaryPath);
        $docx = file_get_contents($temporaryPath);
        if ($docx === false) {
            throw new RuntimeException('Could not read the completed DOCX document.');
        }

        return $docx;
    } finally {
        unset($archive);
        unlink($temporaryPath);
    }
}

function masterlistDateText(array $officer, string $textKey, string $dateKey): string
{
    $sourceText = trim((string) ($officer[$textKey] ?? ''));
    if ($sourceText !== '') {
        return $sourceText;
    }
    $date = (string) ($officer[$dateKey] ?? '');

    return $date === '' ? '' : (new DateTimeImmutable($date))->format('F j, Y');
}

function generateOfficerMasterlistDocx(array $officers, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($officers): void {
            $table = docxTableAt($xpath, 0);
            $rows = docxRows($xpath, $table);
            while (count($rows) < count($officers) + 1) {
                $lastRow = end($rows);
                $clone = $lastRow->cloneNode(true);
                $table->appendChild($clone);
                $rows[] = $clone;
            }

            for ($index = 0; $index < count($rows) - 1; $index++) {
                $officer = $officers[$index] ?? null;
                $values = $officer === null
                    ? ['', '', '', '', '', '', '']
                    : [
                        (string) ($officer['masterlist_number'] ?? ($index + 1)),
                        (string) $officer['name'],
                        masterlistDateText($officer, 'oath_taking_text', 'oath_taking_date'),
                        masterlistDateText($officer, 'ndi_signing_text', 'ndi_signing_date'),
                        !empty($officer['birthday'])
                            ? (new DateTimeImmutable((string) $officer['birthday']))->format('F j, Y')
                            : '',
                        (string) ($officer['contact_telegram'] ?? ''),
                        (int) $officer['is_active'] === 1 ? 'Active' : 'Inactive',
                    ];

                foreach ($values as $cellIndex => $value) {
                    setWordText($xpath, docxCell($xpath, $rows[$index + 1], $cellIndex), $value);
                }
            }
        }
    );
}

function generateWeeklyMonitoringDocx(
    array $officers,
    string $period,
    string $weekNumber,
    string $templatePath
): string {
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($officers, $period, $weekNumber): void {
            $headerRows = docxRows($xpath, docxTableAt($xpath, 0));
            replaceWordText($xpath, docxCell($xpath, $headerRows[0], 1), 'Petsang Saklaw ng Ulat:', ' ' . $period, true);
            replaceWordText($xpath, docxCell($xpath, $headerRows[0], 2), 'Week no.:', ' ' . $weekNumber, true);

            $groups = [
                'Locale TSV Lead' => [],
                'Locale TSV Assistant Lead' => [],
                'Locale TSV Secretary' => [],
                'Locale TSV Officer' => [],
            ];
            foreach ($officers as $officer) {
                if ((int) $officer['is_active'] === 1 && array_key_exists($officer['tungkulin'], $groups)) {
                    $groups[$officer['tungkulin']][] = $officer;
                }
            }
            $orderedOfficers = array_merge(
                $groups['Locale TSV Lead'],
                $groups['Locale TSV Assistant Lead'],
                $groups['Locale TSV Secretary'],
                $groups['Locale TSV Officer']
            );
            $rows = docxRows($xpath, docxTableAt($xpath, 1));
            if (count($orderedOfficers) > count($rows) - 1) {
                throw new RuntimeException('The weekly monitoring template does not have enough officer rows.');
            }
            foreach ($orderedOfficers as $index => $officer) {
                setWordText($xpath, docxCell($xpath, $rows[$index + 1], 0), (string) ($index + 1));
                setWordText($xpath, docxCell($xpath, $rows[$index + 1], 1), (string) $officer['name']);
            }

            $summaryRows = docxRows($xpath, docxTableAt($xpath, 2));
            setWordText($xpath, docxCell($xpath, $summaryRows[1], 0), (string) count($groups['Locale TSV Officer']));
        }
    );
}

function generateWeeklyAttendanceDocx(
    array $officers,
    array $signatories,
    string $meetingDateTime,
    string $templatePath
): string {
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($officers, $signatories, $meetingDateTime): void {
            $paragraphs = $xpath->query('/w:document/w:body/w:p');
            replaceWordText($xpath, $paragraphs->item(1), '________________________', 'FYM AVENUE');
            $rows = docxRows($xpath, docxTableAt($xpath, 0));
            replaceWordText($xpath, docxCell($xpath, $rows[0], 0), 'Petsa at Oras', $meetingDateTime);

            $groups = [
                'Locale TSV Lead' => [],
                'Locale TSV Assistant Lead' => [],
                'Locale TSV Secretary' => [],
                'Locale TSV Officer' => [],
            ];
            foreach ($officers as $officer) {
                if ((int) $officer['is_active'] === 1 && array_key_exists($officer['tungkulin'], $groups)) {
                    $groups[$officer['tungkulin']][] = $officer;
                }
            }
            $orderedOfficers = array_merge(
                $groups['Locale TSV Lead'],
                $groups['Locale TSV Assistant Lead'],
                $groups['Locale TSV Secretary'],
                $groups['Locale TSV Officer']
            );
            if (count($orderedOfficers) > count($rows) - 2) {
                throw new RuntimeException('The weekly attendance template does not have enough officer rows.');
            }
            foreach ($orderedOfficers as $index => $officer) {
                setWordText($xpath, docxCell($xpath, $rows[$index + 2], 1), (string) $officer['name']);
            }

            $signatureRows = docxRows($xpath, docxTableAt($xpath, 1));
            foreach ([
                'tsv_lead' => 0,
                'pd_tagasubaybay' => 1,
                'pastor' => 2,
            ] as $settingKey => $cellIndex) {
                setWordText($xpath, docxCell($xpath, $signatureRows[0], $cellIndex), (string) ($signatories[$settingKey] ?? ''));
            }
        }
    );
}

function generateWeeklyAgendaDocx(
    string $meetingDateTime,
    string $venue,
    string $firstPrayer,
    string $lastPrayer,
    string $lecture,
    array $topics,
    string $templatePath
): string {
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use (
            $meetingDateTime,
            $venue,
            $firstPrayer,
            $lastPrayer,
            $lecture,
            $topics
        ): void {
            $paragraphs = [];
            foreach ($xpath->query('/w:document/w:body/w:p') as $paragraph) {
                $paragraphs[] = $paragraph;
            }
            replaceWordText($xpath, $paragraphs[2], '__________________', 'FYM AVENUE');
            replaceWordText($xpath, $paragraphs[3], 'Petsa, Araw, Oras ng Pulong', ': ' . $meetingDateTime, true);
            replaceWordText($xpath, $paragraphs[4], 'Dako ng Pulong', ': ' . $venue, true);
            replaceWordText($xpath, $paragraphs[6], 'Unang Panalangin:', ' ' . $firstPrayer, true);
            replaceWordText($xpath, $paragraphs[7], 'Huling Panalangin:', ' ' . $lastPrayer, true);
            replaceWordText($xpath, $paragraphs[8], 'Pastor ng Lokal/Manggagawa (name)', $lecture);

            foreach ([10, 14, 18] as $index => $paragraphIndex) {
                replaceWordText($xpath, $paragraphs[$paragraphIndex], 'PAKSA NG AGENDA ' . ($index + 1), (string) ($topics[$index] ?? ''));
                setWordText($xpath, $paragraphs[$paragraphIndex + 1], '');
                setWordText($xpath, $paragraphs[$paragraphIndex + 2], '');
            }
        }
    );
}

function weeklyReportPeriod(DateTimeImmutable $reference): array
{
    $manila = new DateTimeZone('Asia/Manila');
    $localDate = $reference->setTimezone($manila);
    $daysAfterMonday = (int) $localDate->format('N') - 1;
    $monday = $localDate->modify('-' . $daysAfterMonday . ' days')->setTime(0, 0);
    $sunday = $monday->modify('+6 days');

    if ($monday->format('Y') !== $sunday->format('Y')) {
        $period = $monday->format('F j, Y') . '-' . $sunday->format('F j, Y');
    } elseif ($monday->format('n') !== $sunday->format('n')) {
        $period = $monday->format('F j') . '-' . $sunday->format('F j, Y');
    } else {
        $period = $monday->format('F j') . '-' . $sunday->format('j, Y');
    }

    return ['period' => $period, 'week_number' => $monday->format('W')];
}
