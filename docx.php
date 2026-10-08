<?php
declare(strict_types=1);

function docxEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function zipFiles(array $files): string
{
    $localEntries = '';
    $centralEntries = '';
    $offset = 0;
    $dosTime = (int) (date('G') << 11) | (int) (date('i') << 5) | (int) (date('s') / 2);
    $dosDate = ((int) date('Y') - 1980) << 9 | (int) date('n') << 5 | (int) date('j');

    foreach ($files as $name => $contents) {
        $compressed = gzdeflate($contents);
        if ($compressed === false) {
            throw new RuntimeException('Could not compress a DOCX document part.');
        }

        $nameLength = strlen($name);
        $compressedLength = strlen($compressed);
        $contentsLength = strlen($contents);
        $checksum = crc32($contents);
        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $checksum,
            $compressedLength,
            $contentsLength,
            $nameLength,
            0
        );
        $localEntries .= $localHeader . $name . $compressed;

        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $checksum,
            $compressedLength,
            $contentsLength,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        );
        $centralEntries .= $centralHeader . $name;
        $offset += strlen($localHeader) + $nameLength + $compressedLength;
    }

    $entryCount = count($files);
    $endRecord = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $entryCount,
        $entryCount,
        strlen($centralEntries),
        strlen($localEntries),
        0
    );

    return $localEntries . $centralEntries . $endRecord;
}

function savePrintSelections(PDO $pdo, array $formKeys, array $selectedKeys): void
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($formKeys as $formKey) {
            $statement->execute([
                'setting_key' => 'print_form_' . $formKey,
                'setting_value' => in_array($formKey, $selectedKeys, true) ? '1' : '0',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function generateOfficerMasterlistDocxLegacy(
    array $officers,
    string $district = 'Distrito ng Marikina',
    string $locale = 'FYM AVENUE'
): string
{
    $headers = ['Blg.', 'PANGALAN', 'TUNGKULIN', 'PETSA NG PANUNUMPA', 'PETSA NG NDA SIGNING', 'KAARAWAN', 'CONTACT (ACTIVE) Telegram #', 'STATUS'];
    $columnWidths = [500, 3000, 2300, 1800, 1800, 1700, 2000, 1300];
    $tableRows = '';

    foreach (array_merge([null], $officers) as $index => $officer) {
        $values = $officer === null
            ? $headers
            : [
                (string) ($officer['masterlist_number'] ?? $index),
                (string) $officer['name'],
                (string) $officer['tungkulin'],
                (string) ($officer['oath_taking_text'] ?: ($officer['oath_taking_date'] ?? '')),
                (string) ($officer['ndi_signing_text'] ?: ($officer['ndi_signing_date'] ?? '')),
                $officer['birthday'] ? date('F j, Y', strtotime($officer['birthday'])) : '',
                (string) ($officer['contact_telegram'] ?? ''),
                (int) $officer['is_active'] === 1 ? 'Active' : 'Inactive',
            ];
        $cells = '';

        foreach ($values as $columnIndex => $value) {
            $width = $columnWidths[$columnIndex];
            $bold = $officer === null ? '<w:b/>' : '';
            $cells .= '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr>' . $bold
                . '</w:rPr><w:t xml:space="preserve">' . docxEscape($value)
                . '</w:t></w:r></w:p></w:tc>';
        }

        $tableRows .= '<w:tr>' . ($officer === null ? '<w:trPr><w:tblHeader/></w:trPr>' : '')
            . $cells . '</w:tr>';
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr>'
        . '<w:t>' . docxEscape($district) . '</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr>'
        . '<w:t>Lokal ng ' . docxEscape($locale) . '</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="32"/></w:rPr>'
        . '<w:t>MASTER LIST</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:sz w:val="20"/></w:rPr>'
        . '<w:t>Generated ' . docxEscape(date('F j, Y')) . ' | ' . count($officers) . ' officers</w:t>'
        . '</w:r></w:p>'
        . '<w:tbl><w:tblPr><w:tblW w:w="14400" w:type="dxa"/>'
        . '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="7F8C9A"/>'
        . '<w:left w:val="single" w:sz="4" w:color="7F8C9A"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="7F8C9A"/>'
        . '<w:right w:val="single" w:sz="4" w:color="7F8C9A"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="B8C0CA"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="B8C0CA"/></w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/></w:tblPr>'
        . '<w:tblGrid>';
    foreach ($columnWidths as $width) {
        $document .= '<w:gridCol w:w="' . $width . '"/>';
    }
    $document .= '</w:tblGrid>' . $tableRows . '</w:tbl>'
        . '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/>'
        . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" '
        . 'w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>',
        'word/document.xml' => $document,
    ];

    return zipFiles($files);
}
