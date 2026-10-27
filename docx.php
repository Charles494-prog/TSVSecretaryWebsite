<?php
declare(strict_types=1);

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
