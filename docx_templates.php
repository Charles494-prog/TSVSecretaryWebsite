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

function readDocxZip(string $path): array
{
    $archive = file_get_contents($path);
    if ($archive === false) {
        throw new RuntimeException('Could not read a DOCX template.');
    }

    $searchOffset = max(0, strlen($archive) - 65557);
    $endOffset = strrpos(substr($archive, $searchOffset), "PK\x05\x06");
    if ($endOffset === false) {
        throw new RuntimeException('A DOCX template has an invalid ZIP directory.');
    }
    $endOffset += $searchOffset;
    $endRecord = unpack(
        'vdiskNumber/vdirectoryDisk/ventriesOnDisk/ventriesTotal/VdirectorySize/VdirectoryOffset/vcommentLength',
        substr($archive, $endOffset + 4, 18)
    );
    if (!$endRecord
        || $endRecord['entriesOnDisk'] !== $endRecord['entriesTotal']
        || $endRecord['directoryOffset'] + $endRecord['directorySize'] > $endOffset
    ) {
        throw new RuntimeException('A DOCX template has an unsupported or invalid ZIP directory.');
    }

    $files = [];
    $offset = $endRecord['directoryOffset'];
    for ($entryIndex = 0; $entryIndex < $endRecord['entriesTotal']; $entryIndex++) {
        $header = substr($archive, $offset, 46);
        if (strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") {
            throw new RuntimeException('A DOCX template contains an invalid ZIP entry.');
        }
        $entry = unpack(
            'Vsignature/vversionMade/vversionNeeded/vflags/vmethod/vmodifiedTime/vmodifiedDate/'
                . 'Vcrc/VcompressedSize/VuncompressedSize/vfilenameLength/vextraLength/'
                . 'vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset',
            $header
        );
        if (!$entry || ($entry['flags'] & 1) !== 0 || $entry['compressedSize'] === 0xffffffff) {
            throw new RuntimeException('A DOCX template uses an unsupported ZIP feature.');
        }

        $name = substr($archive, $offset + 46, $entry['filenameLength']);
        $localHeader = substr($archive, $entry['localOffset'], 30);
        if (strlen($localHeader) !== 30 || substr($localHeader, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('A DOCX template contains an invalid local ZIP entry.');
        }
        $local = unpack(
            'Vsignature/vversionNeeded/vflags/vmethod/vmodifiedTime/vmodifiedDate/'
                . 'Vcrc/VcompressedSize/VuncompressedSize/vfilenameLength/vextraLength',
            $localHeader
        );
        if (!$local || $local['method'] !== $entry['method']) {
            throw new RuntimeException('A DOCX template has mismatched ZIP entry metadata.');
        }
        $dataOffset = $entry['localOffset'] + 30 + $local['filenameLength'] + $local['extraLength'];
        $compressedData = substr($archive, $dataOffset, $entry['compressedSize']);
        if (strlen($compressedData) !== $entry['compressedSize']) {
            throw new RuntimeException('A DOCX template contains a truncated ZIP entry.');
        }
        if ($entry['method'] === 0) {
            $contents = $compressedData;
        } elseif ($entry['method'] === 8) {
            $contents = gzinflate($compressedData);
        } else {
            throw new RuntimeException('A DOCX template uses an unsupported compression method.');
        }
        if ($contents === false
            || strlen($contents) !== $entry['uncompressedSize']
            || sprintf('%u', crc32($contents)) !== sprintf('%u', $entry['crc'])
        ) {
            throw new RuntimeException('A DOCX template contains a corrupt ZIP entry.');
        }
        if (!str_ends_with($name, '/')) {
            $files[$name] = $contents;
        }

        $offset += 46 + $entry['filenameLength'] + $entry['extraLength'] + $entry['commentLength'];
    }

    return $files;
}

function generateDocxFromTemplate(string $templatePath, callable $populate): string
{
    if (!is_file($templatePath) || !class_exists(DOMDocument::class)) {
        throw new RuntimeException('A required DOCX template or PHP document-processing extension is unavailable.');
    }
    $files = readDocxZip($templatePath);
    if (!isset($files['word/document.xml'])) {
        throw new RuntimeException('The DOCX template is missing its main document part.');
    }

    $document = new DOMDocument();
    $document->preserveWhiteSpace = true;
    $document->formatOutput = false;
    if (!$document->loadXML($files['word/document.xml'], LIBXML_NONET)) {
        throw new RuntimeException('Could not parse the main document in a DOCX template.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', WORD_NAMESPACE);
    $populate($xpath, $document, $files);
    $files['word/document.xml'] = $document->saveXML();

    return zipFiles($files);
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
    int $totalOfficers,
    array $signatories,
    string $period,
    string $weekNumber,
    string $templatePath
): string {
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($officers, $totalOfficers, $signatories, $period, $weekNumber): void {
            $titleParagraph = $xpath->query('/w:document/w:body/w:p')->item(0);
            replaceWordText($xpath, $titleParagraph, 'MARIKINA', mb_strtoupper($signatories['district_name']), true);
            $headerRows = docxRows($xpath, docxTableAt($xpath, 0));
            setDocxCellLine($xpath, docxCell($xpath, $headerRows[0], 0), 1, mb_strtoupper($signatories['locale_name']));
            replaceWordText($xpath, docxCell($xpath, $headerRows[0], 1), 'Petsang Saklaw ng Ulat:', 'Petsang Saklaw ng Ulat: ' . $period, true);
            replaceWordText($xpath, docxCell($xpath, $headerRows[0], 2), 'Week no.:', 'Week no.: ' . $weekNumber, true);

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
            $roleLabels = [
                'Locale TSV Lead' => 'TSV Lead',
                'Locale TSV Assistant Lead' => 'TSV Asst Lead',
                'Locale TSV Secretary' => 'TSV Secretary',
                'Locale TSV Officer' => 'TSV Officer',
            ];
            // The BLG column numbers itself (Word list numbering), so only the name and tungkulin are written.
            foreach ($orderedOfficers as $index => $officer) {
                setWordText($xpath, docxCell($xpath, $rows[$index + 1], 1), (string) $officer['name']);
                setWordText($xpath, docxCell($xpath, $rows[$index + 1], 2), $roleLabels[$officer['tungkulin']]);
            }
            foreach (array_slice($rows, count($orderedOfficers) + 1) as $unusedRow) {
                $unusedRow->parentNode->removeChild($unusedRow);
            }

            // Kabuuang blg counts every officer record, inactive ones included.
            $summaryRows = docxRows($xpath, docxTableAt($xpath, 2));
            setWordText($xpath, docxCell($xpath, $summaryRows[1], 0), (string) $totalOfficers);

            $signatureRow = docxRows($xpath, docxTableAt($xpath, 3))[0];
            foreach (['tsv_secretary', 'tsv_lead', 'pastor'] as $cellIndex => $settingKey) {
                addDocxSignatureName($xpath, docxCell($xpath, $signatureRow, $cellIndex), (string) ($signatories[$settingKey] ?? ''), null);
            }
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
            replaceWordText($xpath, $paragraphs->item(1), '________________________', (string) ($signatories['locale_name'] ?? ''));
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
            $roleLabels = [
                'Locale TSV Lead' => 'TSV Lead',
                'Locale TSV Assistant Lead' => 'TSV Assistant Lead',
                'Locale TSV Secretary' => 'TSV Secretary',
                'Locale TSV Officer' => 'TSV Officer',
            ];
            foreach ($orderedOfficers as $index => $officer) {
                setWordText($xpath, docxCell($xpath, $rows[$index + 2], 1), (string) $officer['name']);
                setWordText($xpath, docxCell($xpath, $rows[$index + 2], 2), $roleLabels[$officer['tungkulin']]);
            }
            // The template has pre-numbered "TSV Officer" rows; drop the ones without an officer.
            foreach (array_slice($rows, count($orderedOfficers) + 2) as $unusedRow) {
                $unusedRow->parentNode->removeChild($unusedRow);
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

// Agenda text: topics separated by a blank line; the first line of each is the title, the rest are its points.
function parseAgendaTopics(string $text): array
{
    $topics = [];
    foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $block) {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $block) ?: []),
            static fn(string $line): bool => $line !== ''
        ));
        if ($lines) {
            $topics[] = ['title' => $lines[0], 'points' => array_slice($lines, 1)];
        }
    }

    return $topics;
}

// A borderless table of signatures: each column holds a name centered above its bold title.
// $columns is a list of [name, title]; an empty slot ['', ''] leaves its column blank.
function createDocxSignatureTable(DOMXPath $xpath, DOMElement $titleRunSource, array $columns, int $textWidth): DOMElement
{
    $document = $titleRunSource->ownerDocument;
    $make = static function (string $name, array $attributes = [], array $children = []) use ($document): DOMElement {
        $element = $document->createElementNS(WORD_NAMESPACE, "w:{$name}");
        foreach ($attributes as $attribute => $value) {
            $element->setAttributeNS(WORD_NAMESPACE, "w:{$attribute}", (string) $value);
        }
        foreach ($children as $child) {
            $element->appendChild($child);
        }
        return $element;
    };
    $titleProperties = $xpath->query('./w:r/w:rPr', $titleRunSource)->item(0);
    $runProperties = static function (bool $bold) use ($xpath, $titleProperties): ?DOMElement {
        if (!$titleProperties) {
            return null;
        }
        $properties = $titleProperties->cloneNode(true);
        if (!$bold) {
            foreach (iterator_to_array($xpath->query('./w:b|./w:bCs', $properties)) as $boldProperty) {
                $properties->removeChild($boldProperty);
            }
        }
        return $properties;
    };
    $columnWidth = intdiv($textWidth, count($columns));

    $borders = $make('tblBorders', [], array_map(
        static fn(string $side): DOMElement => $make($side, ['val' => 'nil']),
        ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']
    ));
    $table = $make('tbl', [], [
        $make('tblPr', [], [
            $make('tblW', ['w' => $columnWidth * count($columns), 'type' => 'dxa']),
            $make('jc', ['val' => 'center']),
            $borders,
            $make('tblLayout', ['type' => 'fixed']),
            $make('tblCellMar', [], [$make('left', ['w' => 0, 'type' => 'dxa']), $make('right', ['w' => 0, 'type' => 'dxa'])]),
        ]),
        $make('tblGrid', [], array_map(static fn(): DOMElement => $make('gridCol', ['w' => $columnWidth]), $columns)),
    ]);
    foreach ([0 => false, 1 => true] as $line => $bold) {
        $row = $make('tr');
        foreach ($columns as $column) {
            $run = $make('r');
            if ($properties = $runProperties($bold)) {
                $run->appendChild($properties);
            }
            $text = $make('t');
            $text->appendChild($document->createTextNode($column[$line]));
            $run->appendChild($text);
            $row->appendChild($make('tc', [], [
                $make('tcPr', [], [$make('tcW', ['w' => $columnWidth, 'type' => 'dxa'])]),
                $make('p', [], [
                    $make('pPr', [], [$make('spacing', ['before' => 0, 'after' => 0]), $make('jc', ['val' => 'center'])]),
                    $run,
                ]),
            ]));
        }
        $table->appendChild($row);
    }

    return $table;
}

// The template has three "PAKSA NG AGENDA" sections; more are copied from the last one. Each copy gets its own
// list in numbering.xml so its points start again at 1.
function addAgendaTopicSections(DOMXPath $xpath, array &$files, int $topicCount): void
{
    $headings = [];
    foreach ($xpath->query('/w:document/w:body/w:p') as $paragraph) {
        if (preg_match('/^PAKSA NG AGENDA \d$/', trim($paragraph->textContent))) {
            $headings[] = $paragraph;
        }
    }
    $missing = $topicCount - count($headings);
    if ($missing <= 0 || !$headings) {
        return;
    }

    $lastHeading = end($headings);
    $item = $lastHeading->nextSibling;
    $blank = $item?->nextSibling;
    while ($blank instanceof DOMElement && $xpath->query('./w:pPr/w:numPr', $blank)->length) {
        $blank = $blank->nextSibling;
    }
    $itemNumber = $xpath->query('./w:pPr/w:numPr/w:numId', $item)->item(0);
    if (!$itemNumber || !isset($files['word/numbering.xml'])) {
        throw new RuntimeException('The agenda template has no numbered points to copy.');
    }

    $numbering = $files['word/numbering.xml'];
    $templateNumId = $itemNumber->getAttributeNS(WORD_NAMESPACE, 'val');
    preg_match('/<w:num w:numId="' . preg_quote($templateNumId, '/') . '"[^>]*>\s*<w:abstractNumId w:val="(\d+)"/', $numbering, $abstract);
    preg_match_all('/<w:num w:numId="(\d+)"/', $numbering, $numIds);
    $nextNumId = max(array_map('intval', $numIds[1])) + 1;

    $insertBefore = $blank?->nextSibling;
    $newLists = '';
    for ($copy = 0; $copy < $missing; $copy++, $nextNumId++) {
        foreach ([$lastHeading, $item, $blank] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $clone = $node->cloneNode(true);
            if ($node === $item) {
                $xpath->query('./w:pPr/w:numPr/w:numId', $clone)->item(0)->setAttributeNS(WORD_NAMESPACE, 'w:val', (string) $nextNumId);
            }
            $lastHeading->parentNode->insertBefore($clone, $insertBefore);
        }
        $newLists .= '<w:num w:numId="' . $nextNumId . '"><w:abstractNumId w:val="' . $abstract[1] . '"/>'
            . '<w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride></w:num>';
    }
    $files['word/numbering.xml'] = str_replace('</w:numbering>', $newLists . '</w:numbering>', $numbering);
}

/**
 * Turns a recurring meeting text such as "Sunday, 3:30 PM" into
 * "November 08, 2026, Sunday, 3:30 PM" using the upcoming occurrence of that
 * weekday (today if it matches). Text that already holds a date is unchanged.
 */
function withUpcomingMeetingDate(string $meetingDateTime, ?DateTimeImmutable $today = null): string
{
    $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|ene|abr|ago|set|okt|dis';
    if (preg_match('/\d{4}|\d{1,2}\/\d{1,2}|\b(' . $months . ')[a-z]*\b/i', $meetingDateTime)) {
        return $meetingDateTime;
    }

    $weekdays = [
        'sunday' => 'Sunday', 'linggo' => 'Sunday',
        'monday' => 'Monday', 'lunes' => 'Monday',
        'tuesday' => 'Tuesday', 'martes' => 'Tuesday',
        'wednesday' => 'Wednesday', 'miyerkules' => 'Wednesday', 'miyerkoles' => 'Wednesday',
        'thursday' => 'Thursday', 'huwebes' => 'Thursday',
        'friday' => 'Friday', 'biyernes' => 'Friday',
        'saturday' => 'Saturday', 'sabado' => 'Saturday',
    ];
    if (!preg_match('/\b(' . implode('|', array_keys($weekdays)) . ')\b/i', $meetingDateTime, $match)) {
        return $meetingDateTime;
    }

    $today ??= new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $weekday = $weekdays[strtolower($match[1])];
    $date = $today->format('l') === $weekday ? $today : $today->modify('next ' . $weekday);

    return $date->format('F d, Y') . ', ' . $meetingDateTime;
}

function generateWeeklyAgendaDocx(
    string $meetingDateTime,
    string $venue,
    string $firstPrayer,
    string $lastPrayer,
    string $lecture,
    array $topics,
    array $settings,
    string $templatePath
): string {
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath, DOMDocument $document, array &$files) use (
            $meetingDateTime,
            $venue,
            $firstPrayer,
            $lastPrayer,
            $lecture,
            $topics,
            $settings
        ): void {
            addAgendaTopicSections($xpath, $files, count($topics));
            $paragraphs = iterator_to_array($xpath->query('/w:document/w:body/w:p'));
            $find = static function (string $prefix) use ($paragraphs): DOMElement {
                foreach ($paragraphs as $paragraph) {
                    if (str_starts_with(trim($paragraph->textContent), $prefix)) {
                        return $paragraph;
                    }
                }
                throw new RuntimeException("The agenda template has no \"{$prefix}\" line.");
            };

            setWordText($xpath, $find('Lokal ng'), 'Lokal ng ' . mb_strtoupper((string) $settings['locale_name']));
            replaceWordText($xpath, $find('Petsa, Araw, Oras'), 'Petsa, Araw, Oras ng Pulong', 'Petsa, Araw, Oras ng Pulong: ' . withUpcomingMeetingDate($meetingDateTime));
            replaceWordText($xpath, $find('Dako ng Pulong'), 'Dako ng Pulong', 'Dako ng Pulong: ' . $venue);
            replaceWordText($xpath, $find('Unang Panalangin:'), 'Unang Panalangin:', 'Unang Panalangin: ' . $firstPrayer);
            replaceWordText($xpath, $find('Huling Panalangin:'), 'Huling Panalangin:', 'Huling Panalangin: ' . $lastPrayer);
            replaceWordText($xpath, $find('Pagpapayo/Lektura:'), ' Pastor ng Lokal/Manggagawa (name)', $lecture === '' ? '' : ' ' . $lecture);

            // Each "PAKSA NG AGENDA n" heading is followed by its numbered points and a blank line.
            $headingNumber = 0;
            foreach ($paragraphs as $paragraph) {
                if (!preg_match('/^PAKSA NG AGENDA \d$/', trim($paragraph->textContent))) {
                    continue;
                }
                $topic = $topics[$headingNumber++] ?? null;
                $items = [];
                for ($next = $paragraph->nextSibling; $next instanceof DOMElement && $xpath->query('./w:pPr/w:numPr', $next)->length; $next = $next->nextSibling) {
                    $items[] = $next;
                }
                if ($topic === null) {
                    $blankAfter = $next instanceof DOMElement && trim($next->textContent) === '' ? [$next] : [];
                    foreach (array_merge([$paragraph], $items, $blankAfter) as $node) {
                        $node->parentNode->removeChild($node);
                    }
                    continue;
                }
                setWordText($xpath, $paragraph, $topic['title']);
                $itemTemplate = $items[0];
                foreach ($topic['points'] as $index => $point) {
                    if (!isset($items[$index])) {
                        $items[$index] = $itemTemplate->cloneNode(true);
                        $items[$index - 1]->parentNode->insertBefore($items[$index], $items[$index - 1]->nextSibling);
                    }
                    setWordText($xpath, $items[$index], $point);
                }
                foreach (array_slice($items, count($topic['points'])) as $unusedItem) {
                    $unusedItem->parentNode->removeChild($unusedItem);
                }
            }

            // Signatures as borderless tables with each name centered above its title; the Pastor sits in the middle column.
            $sectionProperties = $xpath->query('/w:document/w:body/w:sectPr')->item(0);
            $pageWidth = (int) $xpath->query('./w:pgSz', $sectionProperties)->item(0)->getAttributeNS(WORD_NAMESPACE, 'w');
            $pageMargins = $xpath->query('./w:pgMar', $sectionProperties)->item(0);
            $textWidth = $pageWidth - (int) $pageMargins->getAttributeNS(WORD_NAMESPACE, 'left') - (int) $pageMargins->getAttributeNS(WORD_NAMESPACE, 'right');
            $name = static fn(string $key): string => (string) ($settings[$key] ?? '');
            foreach ([
                'LOCAL TSV SECRETARY' => [
                    [$name('tsv_secretary'), 'LOCAL TSV SECRETARY'],
                    [$name('assistant_tsv_lead'), 'TSV ASST LEAD'],
                    [$name('tsv_lead'), 'TSV LEAD'],
                ],
                'PASTOR NG LOKAL' => [['', ''], [$name('pastor'), 'PASTOR NG LOKAL'], ['', '']],
            ] as $prefix => $columns) {
                $titleLine = $find($prefix);
                $titleLine->parentNode->insertBefore(createDocxSignatureTable($xpath, $titleLine, $columns, $textWidth), $titleLine);
                // The blank line that used to hold the names goes. The title line is emptied rather than removed,
                // because Word needs a paragraph after a table that ends the document.
                $blankAbove = $titleLine->previousSibling->previousSibling;
                if ($blankAbove instanceof DOMElement && $blankAbove->localName === 'p' && trim($blankAbove->textContent) === '') {
                    $blankAbove->parentNode->removeChild($blankAbove);
                }
                foreach (iterator_to_array($xpath->query('./w:r', $titleLine)) as $run) {
                    $titleLine->removeChild($run);
                }
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
