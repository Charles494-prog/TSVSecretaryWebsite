<?php
declare(strict_types=1);

// Order matches the Wingdings checkboxes in the Suguan template.
const SUGUAN_TYPES = [
    'katandaan' => 'Pagsamba',
    'pnk' => 'PNK',
    'pv' => 'PV',
    'vsls' => 'VS/LS',
    'kasal' => 'Kasal',
    'pamamahayag' => 'Pamamahayag',
    'bautismo' => 'Bautismo',
    'tanging' => 'Tanging Pagtitipon',
    'pagpapahid' => 'Pagpapahid ng Langis',
    'pulong' => 'Pulong',
];
const SUGUAN_ROLES = ['computer', 'video', 'audio'];
const SUGUAN_EWS_TYPES = ['katandaan', 'pnk'];
const SUGUAN_MAX_SERVICES = 40;
const WORD_DRAWING_NAMESPACE = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
const WORD_2010_NAMESPACE = 'http://schemas.microsoft.com/office/word/2010/wordml';

function suguanWeekStart(?string $value): DateTimeImmutable
{
    $manila = new DateTimeZone('Asia/Manila');
    $date = null;
    if (is_string($value) && $value !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $manila);
        if ($parsed && $parsed->format('Y-m-d') === $value) {
            $date = $parsed;
        }
    }
    $date ??= new DateTimeImmutable('today', $manila);

    return $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->setTime(0, 0);
}

function suguanServiceId(): string
{
    return bin2hex(random_bytes(6));
}

function defaultSuguanServices(DateTimeImmutable $monday): array
{
    $schedule = [
        [2, '19:30', 'katandaan'],
        [3, '05:30', 'katandaan'],
        [3, '19:45', 'katandaan'],
        [5, '18:00', 'katandaan'],
        [6, '06:00', 'katandaan'],
        [6, '08:00', 'pnk'],
        [6, '10:00', 'katandaan'],
    ];
    $services = [];
    foreach ($schedule as [$dayOffset, $time, $type]) {
        $services[] = [
            'id' => suguanServiceId(),
            'date' => $monday->modify("+{$dayOffset} days")->format('Y-m-d'),
            'time' => $time,
            'type' => $type,
            'ews' => false,
            'regular' => true,
            'computer' => '',
            'video' => '',
            'audio' => '',
        ];
    }

    return $services;
}

function rotateSuguanRoles(array $service): array
{
    // Computer operator becomes audio, audio becomes video, video becomes computer.
    return array_merge($service, [
        'computer' => $service['video'],
        'audio' => $service['computer'],
        'video' => $service['audio'],
    ]);
}

function sanitizeSuguanServices(mixed $rawServices): array
{
    if (!is_array($rawServices)) {
        throw new InvalidArgumentException('The Suguan data is not a list of services.');
    }
    if (count($rawServices) > SUGUAN_MAX_SERVICES) {
        throw new InvalidArgumentException('A week can have at most ' . SUGUAN_MAX_SERVICES . ' services.');
    }

    $services = [];
    foreach (array_values($rawServices) as $raw) {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('A Suguan service is invalid.');
        }
        $id = is_string($raw['id'] ?? null) && preg_match('/^[a-z0-9]{1,40}$/', $raw['id']) ? $raw['id'] : suguanServiceId();
        $dateValue = is_string($raw['date'] ?? null) ? $raw['date'] : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue) {
            throw new InvalidArgumentException('Every service needs a valid date.');
        }
        $time = is_string($raw['time'] ?? null) ? $raw['time'] : '';
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('Every service needs a valid time.');
        }
        $type = is_string($raw['type'] ?? null) && array_key_exists($raw['type'], SUGUAN_TYPES) ? $raw['type'] : null;
        if ($type === null) {
            throw new InvalidArgumentException('A service has an unknown type.');
        }
        $service = [
            'id' => $id,
            'date' => $dateValue,
            'time' => $time,
            'type' => $type,
            'ews' => in_array($type, SUGUAN_EWS_TYPES, true) && ($raw['ews'] ?? false) === true,
            'regular' => ($raw['regular'] ?? false) === true,
        ];
        foreach (SUGUAN_ROLES as $role) {
            $name = is_string($raw[$role] ?? null) ? trim($raw[$role]) : '';
            if (mb_strlen($name) > 150) {
                throw new InvalidArgumentException('Operator names must be 150 characters or fewer.');
            }
            $service[$role] = $name;
        }
        $services[] = $service;
    }

    return $services;
}

function loadSuguanWeek(PDO $pdo, DateTimeImmutable $monday): array
{
    $statement = $pdo->prepare('SELECT services FROM suguan_weeks WHERE week_start = :week_start');
    $statement->execute(['week_start' => $monday->format('Y-m-d')]);
    $saved = $statement->fetchColumn();
    if (is_string($saved)) {
        return ['services' => sanitizeSuguanServices(json_decode($saved, true)), 'saved' => true];
    }

    // A new week starts from the most recent earlier week: regular services carry over
    // (keeping EWS toggles and edited times) with the operator roles rotated.
    $statement = $pdo->prepare(
        'SELECT week_start, services FROM suguan_weeks WHERE week_start < :week_start ORDER BY week_start DESC LIMIT 1'
    );
    $statement->execute(['week_start' => $monday->format('Y-m-d')]);
    $previous = $statement->fetch();
    if (!$previous) {
        return ['services' => defaultSuguanServices($monday), 'saved' => false];
    }

    $previousMonday = new DateTimeImmutable($previous['week_start'], $monday->getTimezone());
    $dayShift = (int) $previousMonday->diff($monday)->days;
    $services = [];
    foreach (sanitizeSuguanServices(json_decode($previous['services'], true)) as $service) {
        if (!$service['regular']) {
            continue;
        }
        $service = rotateSuguanRoles($service);
        $service['id'] = suguanServiceId();
        $service['date'] = (new DateTimeImmutable($service['date']))->modify("+{$dayShift} days")->format('Y-m-d');
        $services[] = $service;
    }

    return ['services' => $services ?: defaultSuguanServices($monday), 'saved' => false];
}

function saveSuguanWeek(PDO $pdo, DateTimeImmutable $monday, array $services): void
{
    $statement = $pdo->prepare(
        'INSERT INTO suguan_weeks (week_start, services) VALUES (:week_start, :services)
         ON DUPLICATE KEY UPDATE services = VALUES(services)'
    );
    $statement->execute([
        'week_start' => $monday->format('Y-m-d'),
        'services' => json_encode(array_values($services), JSON_THROW_ON_ERROR),
    ]);
}

// Worship services get a checklist page and a Resumen row: every Pagsamba, plus anything marked EWS.
function isSuguanWorship(array $service): bool
{
    return $service['type'] === 'katandaan' || $service['ews'];
}

function suguanTimeText(array $service): string
{
    $time = DateTimeImmutable::createFromFormat('!H:i', $service['time'])->format('g:i A');

    return $service['ews'] ? $time . ' (English)' : $time;
}

function suguanWeekLabel(DateTimeImmutable $monday): string
{
    $sunday = $monday->modify('+6 days');
    if ($monday->format('Y') !== $sunday->format('Y')) {
        return $monday->format('M j, Y') . ' – ' . $sunday->format('M j, Y');
    }
    if ($monday->format('n') !== $sunday->format('n')) {
        return $monday->format('M j') . ' – ' . $sunday->format('M j, Y');
    }

    return $monday->format('M j') . ' – ' . $sunday->format('j, Y');
}

function docxBodyChildren(DOMXPath $xpath): array
{
    $children = [];
    foreach ($xpath->query('/w:document/w:body/*') as $child) {
        if ($child instanceof DOMElement && $child->localName !== 'sectPr') {
            $children[] = $child;
        }
    }

    return $children;
}

function docxTablesIn(array $nodes): array
{
    return array_values(array_filter($nodes, static fn(DOMElement $node): bool => $node->localName === 'tbl'));
}

// Inserts copies of $nodes before $before and returns the copies.
function cloneDocxNodes(array $nodes, DOMNode $before): array
{
    $clones = [];
    foreach ($nodes as $node) {
        $clone = $node->cloneNode(true);
        $before->parentNode->insertBefore($clone, $before);
        $clones[] = $clone;
    }

    return $clones;
}

function startDocxPageAt(DOMXPath $xpath, DOMElement $paragraph): void
{
    $document = $paragraph->ownerDocument;
    $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
    if (!$properties) {
        $properties = $document->createElementNS(WORD_NAMESPACE, 'w:pPr');
        $paragraph->insertBefore($properties, $paragraph->firstChild);
    }
    $properties->insertBefore($document->createElementNS(WORD_NAMESPACE, 'w:pageBreakBefore'), $properties->firstChild);
}

// Cloned pages repeat drawing and paragraph IDs; give every drawing a unique ID and drop the optional paragraph IDs.
function makeDocxIdsUnique(DOMXPath $xpath): void
{
    $xpath->registerNamespace('wp', WORD_DRAWING_NAMESPACE);
    $nextId = 1;
    foreach ($xpath->query('//wp:docPr') as $docPr) {
        $docPr->setAttribute('id', (string) $nextId++);
    }
    foreach ($xpath->query('//w:p') as $paragraph) {
        $paragraph->removeAttributeNS(WORD_2010_NAMESPACE, 'paraId');
        $paragraph->removeAttributeNS(WORD_2010_NAMESPACE, 'textId');
    }
}

// Adds a bold name line above a signature label in a bottom-aligned cell; $halfPoints null keeps the label's size.
function addDocxSignatureName(DOMXPath $xpath, DOMElement $cell, string $name, ?int $halfPoints = 18): void
{
    if ($name === '') {
        return;
    }
    $label = $xpath->query('./w:p', $cell)->item(0);
    $nameParagraph = $label->cloneNode(true);
    setWordText($xpath, $nameParagraph, $name);
    foreach ($xpath->query('.//w:r', $nameParagraph) as $run) {
        $properties = $xpath->query('./w:rPr', $run)->item(0);
        if (!$properties) {
            $properties = $run->ownerDocument->createElementNS(WORD_NAMESPACE, 'w:rPr');
            $run->insertBefore($properties, $run->firstChild);
        }
        foreach ($halfPoints === null ? [] : $xpath->query('./w:sz|./w:szCs', $properties) as $size) {
            $size->setAttributeNS(WORD_NAMESPACE, 'w:val', (string) $halfPoints);
        }
        $properties->insertBefore($run->ownerDocument->createElementNS(WORD_NAMESPACE, 'w:b'), $properties->firstChild);
    }
    $cell->insertBefore($nameParagraph, $label);
}

// Fills an empty cell using the run formatting of $styleSource (or the cell's own paragraph mark), like typing into it in Word.
function setDocxCellText(DOMXPath $xpath, DOMElement $cell, string $value, ?DOMElement $styleSource = null): void
{
    $paragraph = $xpath->query('./w:p', $cell)->item(0);
    if ($value === '' || $xpath->query('.//w:t', $cell)->length > 0 || !$paragraph) {
        setWordText($xpath, $cell, $value);
        return;
    }
    $document = $cell->ownerDocument;
    $run = $document->createElementNS(WORD_NAMESPACE, 'w:r');
    $sourceProperties = $styleSource
        ? $xpath->query('.//w:r/w:rPr', $styleSource)->item(0)
        : $xpath->query('./w:pPr/w:rPr', $paragraph)->item(0);
    if ($sourceProperties) {
        $runProperties = $document->createElementNS(WORD_NAMESPACE, 'w:rPr');
        foreach ($sourceProperties->childNodes as $property) {
            if (!in_array($property->localName, ['ins', 'del', 'moveFrom', 'moveTo', 'rPrChange'], true)) {
                $runProperties->appendChild($property->cloneNode(true));
            }
        }
        $run->appendChild($runProperties);
    }
    $text = $document->createElementNS(WORD_NAMESPACE, 'w:t');
    $text->appendChild($document->createTextNode($value));
    $run->appendChild($text);
    $paragraph->appendChild($run);
}

// Paragraph properties must keep the schema order, so each one is inserted before the properties that follow it.
const WORD_PARAGRAPH_PROPERTY_ORDER = [
    'pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr', 'suppressLineNumbers',
    'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE',
    'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind', 'contextualSpacing', 'mirrorIndents',
    'suppressOverlap', 'jc', 'textDirection', 'textAlignment', 'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle',
    'rPr', 'sectPr', 'pPrChange',
];

function setDocxParagraphProperty(DOMXPath $xpath, DOMElement $paragraph, string $name, array $attributes): void
{
    $document = $paragraph->ownerDocument;
    $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
    if (!$properties) {
        $properties = $document->createElementNS(WORD_NAMESPACE, 'w:pPr');
        $paragraph->insertBefore($properties, $paragraph->firstChild);
    }
    foreach ($xpath->query("./w:{$name}", $properties) as $existing) {
        $properties->removeChild($existing);
    }
    $property = $document->createElementNS(WORD_NAMESPACE, "w:{$name}");
    foreach ($attributes as $attribute => $value) {
        $property->setAttributeNS(WORD_NAMESPACE, "w:{$attribute}", $value);
    }
    $later = array_slice(WORD_PARAGRAPH_PROPERTY_ORDER, array_search($name, WORD_PARAGRAPH_PROPERTY_ORDER, true) + 1);
    $before = null;
    foreach ($properties->childNodes as $child) {
        if ($child instanceof DOMElement && in_array($child->localName, $later, true)) {
            $before = $child;
            break;
        }
    }
    $properties->insertBefore($property, $before);
}

// Labels the ticked gathering-type box, e.g. "Katandaan" becomes "Katandaan (English)".
function markDocxCheckboxLabel(DOMXPath $xpath, DOMElement $checkbox, string $suffix): void
{
    for ($run = $checkbox->parentNode->nextSibling; $run; $run = $run->nextSibling) {
        $text = $run instanceof DOMElement ? $xpath->query('./w:t', $run)->item(0) : null;
        if (!$text || trim($text->textContent) === '') {
            continue;
        }
        $label = $text->textContent;
        $trailing = substr($label, strlen(rtrim($label)));
        $text->nodeValue = rtrim($label) . $suffix . $trailing;
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        return;
    }
}

// Gives every run in the paragraph the same font and size (in half-points), replacing their own.
function setDocxRunFont(DOMXPath $xpath, DOMElement $paragraph, string $font, int $halfPoints): void
{
    $document = $paragraph->ownerDocument;
    foreach ($xpath->query('./w:r', $paragraph) as $run) {
        foreach ($xpath->query('./w:rPr', $run) as $old) {
            $run->removeChild($old);
        }
        $properties = $document->createElementNS(WORD_NAMESPACE, 'w:rPr');
        $fonts = $document->createElementNS(WORD_NAMESPACE, 'w:rFonts');
        foreach (['ascii', 'hAnsi', 'cs'] as $slot) {
            $fonts->setAttributeNS(WORD_NAMESPACE, "w:{$slot}", $font);
        }
        $properties->appendChild($fonts);
        foreach (['sz', 'szCs'] as $sizeName) {
            $size = $document->createElementNS(WORD_NAMESPACE, "w:{$sizeName}");
            $size->setAttributeNS(WORD_NAMESPACE, 'w:val', (string) $halfPoints);
            $properties->appendChild($size);
        }
        $run->insertBefore($properties, $run->firstChild);
    }
}

// Moves $amount twips of width from one table column to another, in the grid and in every cell, so
// "Wednesday" fits in the Resumen's Araw column without shrinking the text.
function widenDocxTableColumn(DOMXPath $xpath, DOMElement $table, int $widen, int $narrow, int $amount): void
{
    $adjust = static function (?DOMElement $element, int $change): void {
        if ($element) {
            $element->setAttributeNS(WORD_NAMESPACE, 'w:w', (string) ((int) $element->getAttributeNS(WORD_NAMESPACE, 'w') + $change));
        }
    };
    $gridColumns = $xpath->query('./w:tblGrid/w:gridCol', $table);
    $adjust($gridColumns->item($widen), $amount);
    $adjust($gridColumns->item($narrow), -$amount);
    foreach (docxRows($xpath, $table) as $row) {
        $adjust($xpath->query('./w:tcPr/w:tcW', docxCell($xpath, $row, $widen))->item(0), $amount);
        $adjust($xpath->query('./w:tcPr/w:tcW', docxCell($xpath, $row, $narrow))->item(0), -$amount);
    }
}

function setDocxCellLine(DOMXPath $xpath, DOMElement $cell, int $lineIndex, string $value): void
{
    $paragraphs = iterator_to_array($xpath->query('./w:p', $cell));
    while (count($paragraphs) <= $lineIndex) {
        $copy = end($paragraphs)->cloneNode(true);
        $cell->appendChild($copy);
        $paragraphs[] = $copy;
    }
    setWordText($xpath, $paragraphs[$lineIndex], $value);
}

function generateSuguanDocx(array $services, array $settings, string $templatePath): string
{
    if (!$services) {
        throw new InvalidArgumentException('Add at least one service to the Suguan first.');
    }

    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($services, $settings): void {
            $children = docxBodyChildren($xpath);
            $tables = docxTablesIn($children);
            $signatureTable = end($tables);
            $pageEnd = $signatureTable->previousSibling;
            while ($pageEnd && !$pageEnd instanceof DOMElement) {
                $pageEnd = $pageEnd->previousSibling;
            }
            $pageNodes = array_slice($children, 0, array_search($pageEnd, $children, true));
            $slotsPerPage = intdiv(count(docxTablesIn($pageNodes)), 2);

            $pages = [$pageNodes];
            for ($page = 1; $page < (int) ceil(count($services) / $slotsPerPage); $page++) {
                $clones = cloneDocxNodes($pageNodes, $pageEnd);
                startDocxPageAt($xpath, $clones[0]);
                $pages[] = $clones;
            }

            $slotIndex = 0;
            $unusedFrom = null;
            foreach ($pages as $pageNodes) {
                replaceWordText($xpath, $pageNodes[0], '_______________________', ' ' . $settings['locale_name'], true);
                $pageTables = docxTablesIn($pageNodes);
                for ($pair = 0; $pair + 1 < count($pageTables); $pair += 2) {
                    $service = $services[$slotIndex++] ?? null;
                    if ($service === null) {
                        $unusedFrom ??= [$pageNodes, $pageTables[$pair]];
                        continue;
                    }
                    $date = new DateTimeImmutable($service['date']);
                    $headerRows = docxRows($xpath, $pageTables[$pair]);
                    // The time stays plain here; the ticked "Katandaan (English)" box below already marks English services.
                    $time = DateTimeImmutable::createFromFormat('!H:i', $service['time'])->format('g:i A');
                    foreach ([$date->format('M j, Y'), $date->format('l'), $time, $date->format('W')] as $cellIndex => $value) {
                        appendWordText($xpath, docxCell($xpath, $headerRows[0], $cellIndex), ' ' . $value);
                    }
                    $typeIndex = array_search($service['type'], array_keys(SUGUAN_TYPES), true);
                    $checkbox = $xpath->query('.//w:sym', docxCell($xpath, $headerRows[1], 0))->item($typeIndex);
                    $checkbox?->setAttributeNS(WORD_NAMESPACE, 'w:char', 'F0FE');
                    if ($checkbox && $service['ews']) {
                        markDocxCheckboxLabel($xpath, $checkbox, ' (English)');
                        // The row has no spare width; 7.5pt instead of 8pt keeps "(English)" from wrapping it.
                        foreach ($xpath->query('.//w:sz|.//w:szCs', docxCell($xpath, $headerRows[1], 0)) as $size) {
                            if ($size->getAttributeNS(WORD_NAMESPACE, 'val') === '16') {
                                $size->setAttributeNS(WORD_NAMESPACE, 'w:val', '15');
                            }
                        }
                    }

                    $roleRows = docxRows($xpath, $pageTables[$pair + 1]);
                    foreach (SUGUAN_ROLES as $roleIndex => $role) {
                        $nameCell = docxCell($xpath, $roleRows[$roleIndex + 1], 1);
                        setDocxCellText($xpath, $nameCell, $service[$role], docxCell($xpath, $roleRows[$roleIndex + 1], 0));
                        setDocxParagraphProperty($xpath, $xpath->query('./w:p', $nameCell)->item(0), 'ind', ['left' => '144']);
                    }
                }
            }

            // Drop the blank slots left on the last page so the signatures stay on it.
            if ($unusedFrom) {
                [$lastPageNodes, $firstUnusedTable] = $unusedFrom;
                foreach (array_slice($lastPageNodes, array_search($firstUnusedTable, $lastPageNodes, true)) as $node) {
                    $node->parentNode->removeChild($node);
                }
            }

            $signatureRows = docxRows($xpath, $signatureTable);
            foreach (['tsv_lead', 'pd_tagasubaybay', 'pastor'] as $cellIndex => $settingKey) {
                $signatureCell = docxCell($xpath, $signatureRows[1], $cellIndex);
                setWordText($xpath, $signatureCell, (string) ($settings[$settingKey] ?? ''));
                foreach ($xpath->query('./w:p', $signatureCell) as $paragraph) {
                    setDocxParagraphProperty($xpath, $paragraph, 'jc', ['val' => 'center']);
                }
            }
            makeDocxIdsUnique($xpath);
        }
    );
}

function generateChecklistDocx(array $services, array $settings, string $templatePath): string
{
    $worshipServices = array_values(array_filter($services, 'isSuguanWorship'));
    if (!$worshipServices) {
        throw new InvalidArgumentException('The checklist needs at least one worship service (Pagsamba or EWS) in the Suguan.');
    }

    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($worshipServices, $settings): void {
            $originals = docxBodyChildren($xpath);
            $sectionProperties = $xpath->query('/w:document/w:body/w:sectPr')->item(0);
            foreach ($worshipServices as $index => $service) {
                $pageNodes = cloneDocxNodes($originals, $sectionProperties);
                if ($index > 0) {
                    startDocxPageAt($xpath, $pageNodes[0]);
                }
                $tables = docxTablesIn($pageNodes);
                $date = new DateTimeImmutable($service['date']);

                $headerRow = docxRows($xpath, $tables[0])[0];
                $timeCell = docxCell($xpath, $headerRow, 3);
                if ($xpath->query('./w:p', $timeCell)->length < 2) {
                    // The ORAS cell has no value line; borrow the ARAW cell's so the time matches the other values.
                    $timeCell->appendChild($xpath->query('./w:p', docxCell($xpath, $headerRow, 2))->item(1)->cloneNode(true));
                }
                foreach ([$settings['locale_name'], $date->format('F j, Y'), $date->format('l'), suguanTimeText($service)] as $cellIndex => $value) {
                    $cell = docxCell($xpath, $headerRow, $cellIndex);
                    setDocxCellLine($xpath, $cell, 1, $value);
                    // The template's value lines have an exact height meant for its small labels, which clips larger text in Word.
                    $line = $xpath->query('./w:p', $cell)->item(1);
                    setDocxParagraphProperty($xpath, $line, 'spacing', ['before' => '20', 'after' => '20', 'line' => '240', 'lineRule' => 'auto']);
                    setDocxRunFont($xpath, $line, 'Arial', 20);
                }

                $dutyRows = docxRows($xpath, $tables[1]);
                foreach (['computer' => 1, 'video' => 2, 'audio' => 3] as $role => $rowIndex) {
                    setDocxCellText($xpath, docxCell($xpath, $dutyRows[$rowIndex], 1), $service[$role]);
                }

                $signatureRows = docxRows($xpath, end($tables));
                addDocxSignatureName($xpath, docxCell($xpath, $signatureRows[0], 1), $service['audio']);
                addDocxSignatureName($xpath, docxCell($xpath, $signatureRows[0], 2), $service['video']);
                addDocxSignatureName($xpath, docxCell($xpath, $signatureRows[0], 3), $service['computer']);
                addDocxSignatureName($xpath, docxCell($xpath, $signatureRows[1], 3), (string) ($settings['pastor'] ?? ''));
            }
            foreach ($originals as $node) {
                $node->parentNode->removeChild($node);
            }
            makeDocxIdsUnique($xpath);
        }
    );
}

// Fills an empty template cell line with the run formatting of its paragraph mark (the template's
// 9pt Bookman Old Style), so the text matches the printed column instead of Word's default font.
function setDocxCellTextLines(DOMXPath $xpath, DOMElement $cell, array $lines): void
{
    $paragraphs = iterator_to_array($xpath->query('./w:p', $cell));
    $template = $paragraphs[0]->cloneNode(true);
    foreach ($xpath->query('./w:r', $template) as $run) {
        $template->removeChild($run);
    }
    foreach (array_slice($paragraphs, 1) as $extra) {
        $cell->removeChild($extra);
    }
    $cell->removeChild($paragraphs[0]);
    $markFormat = $xpath->query('./w:pPr/w:rPr', $template)->item(0);
    foreach ($lines ?: [''] as $line) {
        $paragraph = $template->cloneNode(true);
        if ($line !== '') {
            $document = $cell->ownerDocument;
            $run = $document->createElementNS(WORD_NAMESPACE, 'w:r');
            if ($markFormat) {
                $run->appendChild($markFormat->cloneNode(true));
            }
            $text = $document->createElementNS(WORD_NAMESPACE, 'w:t');
            $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $text->appendChild($document->createTextNode($line));
            $run->appendChild($text);
            $paragraph->appendChild($run);
        }
        $cell->appendChild($paragraph);
    }
}

// $recruits holds [name, birthday, wedding date (Y-m-d or '')] rows for the Recruits table.
function generateMonthlyAttendanceDocx(array $officers, array $recruits, array $settings, string $meetingDate, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($officers, $recruits, $settings, $meetingDate): void {
            $headerRow = docxRows($xpath, docxTableAt($xpath, 0))[0];
            setDocxCellLine($xpath, docxCell($xpath, $headerRow, 0), 1, $settings['district_name']);
            appendWordText($xpath, docxCell($xpath, $headerRow, 1), $settings['locale_name']);
            setDocxCellLine($xpath, docxCell($xpath, $headerRow, 2), 1, $meetingDate);

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
            $summaryRows = docxRows($xpath, docxTableAt($xpath, 1));
            setWordText($xpath, docxCell($xpath, $summaryRows[1], 1), (string) array_sum(array_map('count', $groups)));

            $table = docxTableAt($xpath, 3);
            $rows = docxRows($xpath, $table);
            // Birthday as "July 08, 2003"; a wedding anniversary goes on its own line below it.
            $fill = static function (DOMElement $row, string $name, ?string $birthday, ?string $wedding = null) use ($xpath): void {
                setDocxCellTextLines($xpath, docxCell($xpath, $row, 0), [$name]);
                $dates = [];
                if ($birthday) {
                    $dates[] = (new DateTimeImmutable($birthday))->format('F d, Y');
                }
                if ($wedding) {
                    $dates[] = 'Wedding: ' . (new DateTimeImmutable($wedding))->format('F d, Y');
                }
                setDocxCellTextLines($xpath, docxCell($xpath, $row, 3), $dates);
            };
            $fill(
                $rows[1],
                (string) ($settings['pd_tagasubaybay'] ?? ''),
                ($settings['pd_tagasubaybay_birthday'] ?? '') ?: null,
                ($settings['pd_tagasubaybay_wedding'] ?? '') ?: null
            );
            foreach (['Locale TSV Lead' => 2, 'Locale TSV Assistant Lead' => 3, 'Locale TSV Secretary' => 4] as $role => $rowIndex) {
                $officer = $groups[$role][0] ?? null;
                if ($officer) {
                    $fill($rows[$rowIndex], (string) $officer['name'], $officer['birthday'], $officer['wedding_anniversary'] ?? null);
                }
            }
            $officerRows = array_slice($rows, 5);
            while (count($officerRows) < count($groups['Locale TSV Officer'])) {
                $copy = end($officerRows)->cloneNode(true);
                $table->appendChild($copy);
                $officerRows[] = $copy;
            }
            foreach ($groups['Locale TSV Officer'] as $index => $officer) {
                $fill($officerRows[$index], (string) $officer['name'], $officer['birthday'], $officer['wedding_anniversary'] ?? null);
            }
            // Pre-printed "TSV Officer" rows without an officer are removed; the Recruits table below stays for write-ins.
            foreach (array_slice($officerRows, count($groups['Locale TSV Officer'])) as $unusedRow) {
                $unusedRow->parentNode->removeChild($unusedRow);
            }

            // Recruits: filled rows replace the blank ones; with no recruits the blank rows stay for writing in.
            if ($recruits) {
                $recruitTable = docxTableAt($xpath, 5);
                $recruitRows = array_slice(docxRows($xpath, $recruitTable), 1);
                while (count($recruitRows) < count($recruits)) {
                    $copy = end($recruitRows)->cloneNode(true);
                    $recruitTable->appendChild($copy);
                    $recruitRows[] = $copy;
                }
                foreach ($recruits as $index => $recruit) {
                    [$name, $birthday, $wedding] = array_pad($recruit, 3, '');
                    $fill($recruitRows[$index], $name, $birthday !== '' ? $birthday : null, $wedding !== '' ? $wedding : null);
                }
                foreach (array_slice($recruitRows, count($recruits)) as $unusedRow) {
                    $unusedRow->parentNode->removeChild($unusedRow);
                }
            }

            // Names centered over their titles, in the template's three equal columns.
            $signatureRows = docxRows($xpath, docxTableAt($xpath, 6));
            foreach (['tsv_lead', 'pd_tagasubaybay', 'pastor'] as $cellIndex => $settingKey) {
                setWordText($xpath, docxCell($xpath, $signatureRows[0], $cellIndex), (string) ($settings[$settingKey] ?? ''));
                foreach ([$signatureRows[0], $signatureRows[1]] as $row) {
                    foreach ($xpath->query('./w:p', docxCell($xpath, $row, $cellIndex)) as $paragraph) {
                        setDocxParagraphProperty($xpath, $paragraph, 'ind', ['left' => '0', 'right' => '0', 'firstLine' => '0']);
                        setDocxParagraphProperty($xpath, $paragraph, 'jc', ['val' => 'center']);
                    }
                }
            }
        }
    );
}

// "FYM AVENUE" -> "FYM Avenue", "MARIKINA" -> "Marikina"; names typed in mixed case are kept as typed.
function placeNameText(string $name): string
{
    if ($name !== mb_strtoupper($name)) {
        return $name;
    }

    return implode(' ', array_map(
        static fn(string $word): string => mb_strlen($word) <= 3 ? $word : mb_convert_case($word, MB_CASE_TITLE),
        explode(' ', $name)
    ));
}

// One week of the TSV Form 6 Resumen on a half sheet (8.5 × 7 in). The template holds two copies
// on a long sheet separated by a cut line; only the first is kept and the page is halved.
function generateResumenDocx(DateTimeImmutable $monday, array $services, array $settings, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($monday, $services, $settings): void {
            $headerRow = docxRows($xpath, docxTableAt($xpath, 0))[0];
            $listTable = docxTableAt($xpath, 1);
            $summaryRows = docxRows($xpath, docxTableAt($xpath, 2));
            $signatureRow = docxRows($xpath, docxTableAt($xpath, 3))[0];

            setDocxCellText($xpath, docxCell($xpath, $headerRow, 1), $settings['locale_name'], docxCell($xpath, $headerRow, 0));
            setWordText($xpath, docxCell($xpath, $headerRow, 3), weeklyReportPeriod($monday)['period']);
            setWordText($xpath, docxCell($xpath, $headerRow, 5), $monday->format('W'));

            $worshipServices = array_values(array_filter($services, 'isSuguanWorship'));
            $rows = array_slice(docxRows($xpath, $listTable), 1);
            while (count($rows) < count($worshipServices)) {
                $copy = end($rows)->cloneNode(true);
                $listTable->appendChild($copy);
                $rows[] = $copy;
            }
            $listStyle = docxCell($xpath, docxRows($xpath, $listTable)[0], 0);
            $complete = 0;
            foreach ($worshipServices as $index => $service) {
                $isComplete = $service['computer'] !== '' && $service['video'] !== '' && $service['audio'] !== '';
                $complete += $isComplete ? 1 : 0;
                $date = new DateTimeImmutable($service['date']);
                $time = DateTimeImmutable::createFromFormat('!H:i', $service['time'])->format('g:i A');
                // An incomplete service gets a salaysay letter, so its paliwanag column is answered "Opo".
                $values = [$date->format('l'), $time, $isComplete ? 'Opo' : 'Hindi po', $isComplete ? '' : 'Opo'];
                foreach ($values as $cellIndex => $value) {
                    $cell = docxCell($xpath, $rows[$index], $cellIndex);
                    setDocxCellText($xpath, $cell, $value, $listStyle);
                    setDocxParagraphProperty($xpath, $xpath->query('./w:p', $cell)->item(0), 'jc', ['val' => 'center']);
                }
            }
            widenDocxTableColumn($xpath, $listTable, 0, 3, 300);

            $total = count($worshipServices);
            $percent = $total > 0 ? (int) round($complete / $total * 100) . '%' : '';
            setDocxCellText($xpath, docxCell($xpath, $summaryRows[0], 1), (string) $total, docxCell($xpath, $summaryRows[0], 0));
            setDocxCellText($xpath, docxCell($xpath, $summaryRows[1], 1), $percent, docxCell($xpath, $summaryRows[1], 0));
            setDocxCellText($xpath, docxCell($xpath, $summaryRows[2], 1), (string) $complete, docxCell($xpath, $summaryRows[2], 0));

            foreach (['tsv_secretary' => 0, 'tsv_lead' => 2, 'pastor' => 4] as $settingKey => $cellIndex) {
                $cell = docxCell($xpath, $signatureRow, $cellIndex);
                setWordText($xpath, $cell, '');
                setDocxCellText($xpath, $cell, (string) ($settings[$settingKey] ?? ''), docxCell($xpath, $headerRow, 0));
            }

            // Drop the cut line and the second copy, plus the blank line before the cut.
            $cutLine = $xpath->query("/w:document/w:body/w:p[.//w:sym[@w:char='F022']]")->item(0);
            $removeFrom = $cutLine->previousSibling instanceof DOMElement && trim($cutLine->previousSibling->textContent) === ''
                ? $cutLine->previousSibling
                : $cutLine;
            $sectionProperties = $xpath->query('/w:document/w:body/w:sectPr')->item(0);
            while ($removeFrom && $removeFrom !== $sectionProperties) {
                $next = $removeFrom->nextSibling;
                $removeFrom->parentNode->removeChild($removeFrom);
                $removeFrom = $next;
            }

            $pageSize = $xpath->query('./w:pgSz', $sectionProperties)->item(0);
            $margins = $xpath->query('./w:pgMar', $sectionProperties)->item(0);
            $pageSize->setAttributeNS(WORD_NAMESPACE, 'w:h', (string) intdiv((int) $pageSize->getAttributeNS(WORD_NAMESPACE, 'h'), 2));
            $margins->setAttributeNS(WORD_NAMESPACE, 'w:bottom', '274');
        }
    );
}

function generateHymnfilesDeletedDocx(array $settings, DateTimeImmutable $today, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($settings, $today): void {
            $locale = placeNameText($settings['locale_name']);
            $paragraphs = iterator_to_array($xpath->query('/w:document/w:body/w:p'));
            setWordText($xpath, $paragraphs[1], 'Lokal ng ' . $locale);
            setWordText($xpath, $paragraphs[2], 'Distrito ng ' . placeNameText($settings['district_name']));
            replaceWordText($xpath, $paragraphs[5], 'FYM Avenue', $locale);
            // The current Monday–Sunday: it is printed on Saturday or, at the latest, Sunday.
            replaceWordText($xpath, $paragraphs[5], '________', weeklyReportPeriod($today)['period']);

            $signatureRows = docxRows($xpath, docxTableAt($xpath, 0));
            setWordText($xpath, docxCell($xpath, $signatureRows[1], 0), (string) ($settings['tsv_lead'] ?? ''));
            setDocxCellText(
                $xpath,
                docxCell($xpath, $signatureRows[1], 1),
                (string) ($settings['pastor'] ?? ''),
                docxCell($xpath, $signatureRows[1], 0)
            );
        }
    );
}

function suguanWeekPayload(PDO $pdo, DateTimeImmutable $monday): array
{
    $week = loadSuguanWeek($pdo, $monday);

    return [
        'week' => $monday->format('Y-m-d'),
        'label' => suguanWeekLabel($monday),
        'weekNumber' => $monday->format('W'),
        'previous' => $monday->modify('-7 days')->format('Y-m-d'),
        'next' => $monday->modify('+7 days')->format('Y-m-d'),
        'current' => suguanWeekStart(null)->format('Y-m-d'),
        'saved' => $week['saved'],
        'services' => $week['services'],
    ];
}

// Worship services in the Suguan that do not have all three operators ("Hindi" on the Resumen).
function incompleteWorshipServices(array $services): array
{
    return array_values(array_filter(
        $services,
        static fn(array $service): bool => isSuguanWorship($service)
            && ($service['computer'] === '' || $service['video'] === '' || $service['audio'] === '')
    ));
}

function salaysayTimeOfDay(string $time): string
{
    $hour = (int) substr($time, 0, 2);

    return $hour < 12 ? 'Umaga' : ($hour < 18 ? 'Hapon' : 'Gabi');
}

// The letters explaining why worship services did not have three operators: one page per service, in one file.
function generateSalaysayDocx(array $services, array $settings, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($services, $settings): void {
            $locale = placeNameText($settings['locale_name']);
            $district = placeNameText($settings['district_name']);
            // An empty setting leaves its placeholder in the letter so the missing name is easy to spot.
            $replacements = [
                'DISTRITO NG MARIKINA' => 'DISTRITO NG ' . mb_strtoupper($district),
                'LOKAL NG FYM AVENUE' => 'LOKAL NG ' . mb_strtoupper($locale),
                '(Pangalan ng Tagapangasiwa ng Distrito)' => $settings['district_supervisor'],
                '(District TSV Overseer)' => $settings['district_tsv_overseer'],
                '[TSV Lead Name]' => trim((string) $settings['tsv_lead']) === '' ? '' : 'Ka. ' . $settings['tsv_lead'],
                ', nasa kasalukuyang sapat na gulang, at maytungkulin bilang TSV Lead sa ' => ', TSV Lead ng ',
                'Lokal ng FYM Avenue, Distrito ng Marikina' => "Lokal ng {$locale}, Distrito ng {$district}",
                '[PANGALAN MO]' => $settings['tsv_lead'],
                '[PANGALAN NG PASTOR]' => $settings['pastor'],
            ];

            // Formal letter layout: justified body paragraphs with a first-line indent and space between them,
            // and room to sign above each name instead of the template's grey rules.
            foreach ($xpath->query('/w:document/w:body/w:p') as $paragraph) {
                $text = trim($paragraph->textContent);
                if ($xpath->query('.//w:pict', $paragraph)->length) {
                    foreach (iterator_to_array($xpath->query('./w:r', $paragraph)) as $run) {
                        $paragraph->removeChild($run);
                    }
                    setDocxParagraphProperty($xpath, $paragraph, 'spacing', ['before' => '0', 'after' => '0', 'line' => '1080', 'lineRule' => 'exact']);
                } elseif (preg_match('/^(Ako po|Isinasagawa|Ang naging|Lubos|Maraming salamat)/u', $text)) {
                    setDocxParagraphProperty($xpath, $paragraph, 'spacing', ['before' => '0', 'after' => '240', 'line' => '276', 'lineRule' => 'auto']);
                    setDocxParagraphProperty($xpath, $paragraph, 'ind', ['firstLine' => '720']);
                    setDocxParagraphProperty($xpath, $paragraph, 'jc', ['val' => 'both']);
                } elseif (str_starts_with($text, 'Ang inyong kapatid') || str_starts_with($text, 'Noted by')) {
                    setDocxParagraphProperty($xpath, $paragraph, 'spacing', ['before' => '240', 'after' => '0', 'line' => '276', 'lineRule' => 'auto']);
                }
            }

            $originals = docxBodyChildren($xpath);
            $sectionProperties = $xpath->query('/w:document/w:body/w:sectPr')->item(0);
            foreach (array_values($services) as $index => $service) {
                $pageNodes = cloneDocxNodes($originals, $sectionProperties);
                if ($index > 0) {
                    startDocxPageAt($xpath, $pageNodes[0]);
                }
                $serviceReplacements = $replacements + [
                    '[Petsa ng Pagsamba]' => (new DateTimeImmutable($service['date']))->format('F j, Y'),
                    '[Oras ng Pagsamba]' => DateTimeImmutable::createFromFormat('!H:i', $service['time'])->format('g:i'),
                    '[Umaga/Hapon/Gabi]' => salaysayTimeOfDay($service['time']),
                ];
                foreach ($pageNodes as $paragraph) {
                    foreach ($serviceReplacements as $needle => $value) {
                        if (trim((string) $value) !== '' && str_contains($paragraph->textContent, $needle)) {
                            replaceWordText($xpath, $paragraph, $needle, (string) $value);
                        }
                    }
                }
            }
            foreach ($originals as $node) {
                $node->parentNode->removeChild($node);
            }
            makeDocxIdsUnique($xpath);
        }
    );
}
