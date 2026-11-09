<?php
declare(strict_types=1);

// TSV Memories: posts (a caption and a date) with photos and videos, like a social media feed.
// Everything is stored in the database as base64 text, so the SQL backup carries it with the officer records.
// MySQL here accepts about 1 MB per statement, so:
// - the browser shrinks each photo before upload so one photo fits in one row;
// - a video is split into 512 KB pieces (memory_video_chunks), one row and one backup INSERT per piece.

const MEMORY_PHOTO_MAX_BASE64 = 720 * 1024;
const MEMORY_THUMB_MAX_BASE64 = 240 * 1024;
const MEMORY_CAPTION_MAX = 2000;
const MEMORY_PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MEMORY_VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v', 'video/ogg'];
const MEMORY_VIDEO_MAX_BYTES = 100 * 1024 * 1024;
const MEMORY_VIDEO_CHUNK_BYTES = 512 * 1024;
const MEMORY_POST_COLUMNS = ['id', 'taken_on', 'caption', 'created_at', 'updated_at'];
const MEMORY_PHOTO_COLUMNS = ['id', 'post_id', 'position', 'kind', 'checksum', 'mime', 'width', 'height', 'duration_ms', 'size_bytes', 'photo', 'thumb'];
const MEMORY_CHUNK_COLUMNS = ['media_id', 'seq', 'data'];

function ensureMemoryTables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS memory_posts (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          taken_on DATE NOT NULL,
          caption VARCHAR(2000) NOT NULL DEFAULT '',
          created_at DATETIME NOT NULL,
          updated_at DATETIME NOT NULL,
          PRIMARY KEY (id),
          KEY idx_memory_posts_taken_on (taken_on)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS memory_photos (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          post_id INT UNSIGNED NOT NULL,
          position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          kind VARCHAR(5) NOT NULL DEFAULT 'photo',
          checksum CHAR(40) NOT NULL,
          mime VARCHAR(20) NOT NULL,
          width SMALLINT UNSIGNED NOT NULL,
          height SMALLINT UNSIGNED NOT NULL,
          duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
          size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
          complete TINYINT(1) NOT NULL DEFAULT 1,
          uploaded_at DATETIME DEFAULT NULL,
          photo LONGTEXT NOT NULL,
          thumb MEDIUMTEXT NOT NULL,
          PRIMARY KEY (id),
          KEY idx_memory_photos_post (post_id, position),
          KEY idx_memory_photos_checksum (checksum)
        ) CHARACTER SET ascii COLLATE ascii_bin"
    );
    // Databases created before videos were supported get the new columns.
    $columns = array_column($pdo->query('SHOW COLUMNS FROM memory_photos')->fetchAll(), 'Field');
    $added = [
        'kind' => "ADD COLUMN kind VARCHAR(5) NOT NULL DEFAULT 'photo' AFTER position",
        'duration_ms' => 'ADD COLUMN duration_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER height',
        'size_bytes' => 'ADD COLUMN size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_ms',
        'complete' => 'ADD COLUMN complete TINYINT(1) NOT NULL DEFAULT 1 AFTER size_bytes',
        'uploaded_at' => 'ADD COLUMN uploaded_at DATETIME DEFAULT NULL AFTER complete',
    ];
    foreach ($added as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE memory_photos {$definition}");
        }
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS memory_video_chunks (
          media_id INT UNSIGNED NOT NULL,
          seq INT UNSIGNED NOT NULL,
          data MEDIUMTEXT NOT NULL,
          PRIMARY KEY (media_id, seq)
        ) CHARACTER SET ascii COLLATE ascii_bin"
    );
}

// Videos whose upload was interrupted (tab closed, connection lost) are cleared after a day.
function clearAbandonedVideos(PDO $pdo): void
{
    $ids = $pdo->query('SELECT id FROM memory_photos WHERE complete = 0 AND uploaded_at < NOW() - INTERVAL 1 DAY')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        deleteMemoryMedia($pdo, (int) $id);
    }
}

function deleteMemoryMedia(PDO $pdo, int $mediaId, ?int $postId = null): void
{
    $where = $postId === null ? 'id = :id' : 'id = :id AND post_id = :post_id';
    $parameters = $postId === null ? ['id' => $mediaId] : ['id' => $mediaId, 'post_id' => $postId];
    $statement = $pdo->prepare("DELETE FROM memory_photos WHERE {$where}");
    $statement->execute($parameters);
    if ($statement->rowCount() > 0) {
        $pdo->prepare('DELETE FROM memory_video_chunks WHERE media_id = :id')->execute(['id' => $mediaId]);
    }
}

function deleteMemoryPost(PDO $pdo, int $postId): void
{
    $statement = $pdo->prepare('SELECT id FROM memory_photos WHERE post_id = :id');
    $statement->execute(['id' => $postId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $mediaId) {
        deleteMemoryMedia($pdo, (int) $mediaId);
    }
    $pdo->prepare('DELETE FROM memory_posts WHERE id = :id')->execute(['id' => $postId]);
}

// Everything the page needs to draw the feed, gallery and calendar, without the image or video data itself.
function memoriesPayload(PDO $pdo): array
{
    $posts = [];
    foreach ($pdo->query('SELECT id, taken_on, caption, created_at, updated_at FROM memory_posts ORDER BY taken_on DESC, id DESC') as $row) {
        $posts[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'date' => $row['taken_on'],
            'caption' => $row['caption'],
            'created' => $row['created_at'],
            'edited' => $row['updated_at'] !== $row['created_at'],
            'photos' => [],
        ];
    }
    foreach ($pdo->query('SELECT id, post_id, kind, width, height, duration_ms FROM memory_photos WHERE complete = 1 ORDER BY post_id, position, id') as $row) {
        if (isset($posts[(int) $row['post_id']])) {
            $item = ['id' => (int) $row['id'], 'w' => (int) $row['width'], 'h' => (int) $row['height']];
            if ($row['kind'] === 'video') {
                $item['video'] = true;
                $item['duration'] = round((int) $row['duration_ms'] / 1000, 1);
            }
            $posts[(int) $row['post_id']]['photos'][] = $item;
        }
    }

    return array_values($posts);
}

function memoryDate(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        // No date given: the memory is dated the day it was imported.
        return (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Choose a valid date for the memory.');
    }
    if ($value > (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) {
        throw new InvalidArgumentException('A memory cannot be dated later than today.');
    }

    return $value;
}

function memoryCaption(mixed $value): string
{
    $caption = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
    if (mb_strlen($caption) > MEMORY_CAPTION_MAX) {
        throw new InvalidArgumentException('Captions must be ' . MEMORY_CAPTION_MAX . ' characters or fewer.');
    }

    return $caption;
}

// Checks a base64 JPEG/PNG/WebP and returns [binary, getimagesize info].
function memoryImage(mixed $base64, int $maxLength, string $tooLarge): array
{
    $base64 = is_string($base64) ? preg_replace('/^data:[^,]*,/', '', $base64) : '';
    if ($base64 === '' || strlen($base64) > $maxLength) {
        throw new InvalidArgumentException($tooLarge);
    }
    $binary = base64_decode($base64, true);
    $info = $binary === false ? false : @getimagesizefromstring($binary);
    if (!$info || !in_array($info['mime'], MEMORY_PHOTO_TYPES, true)) {
        throw new InvalidArgumentException('Only JPEG, PNG or WebP pictures can be added.');
    }

    return [$base64, $binary, $info];
}

// Checks one uploaded photo (already resized by the browser) and returns the row to store.
function memoryPhotoRow(mixed $photo, mixed $thumb): array
{
    [$photo, $binary, $info] = memoryImage($photo, MEMORY_PHOTO_MAX_BASE64, 'A photo was too large after resizing. Try a smaller picture.');
    [$thumb] = memoryImage($thumb, MEMORY_THUMB_MAX_BASE64, 'A photo preview was too large.');

    return [
        'kind' => 'photo',
        'checksum' => sha1($binary),
        'mime' => $info['mime'],
        'width' => min(65535, (int) $info[0]),
        'height' => min(65535, (int) $info[1]),
        'duration_ms' => 0,
        'size_bytes' => strlen($binary),
        'photo' => $photo,
        'thumb' => $thumb,
    ];
}

// The details of a video, sent before its pieces. The poster frame is made by the browser.
function memoryVideoRow(mixed $mime, mixed $size, mixed $width, mixed $height, mixed $duration, mixed $poster): array
{
    $mime = is_string($mime) ? strtolower(trim($mime)) : '';
    $size = (int) $size;
    if (!in_array($mime, MEMORY_VIDEO_TYPES, true)) {
        throw new InvalidArgumentException('Only MP4, WebM or MOV videos can be added.');
    }
    if ($size < 1 || $size > MEMORY_VIDEO_MAX_BYTES) {
        throw new InvalidArgumentException('Videos must be ' . intdiv(MEMORY_VIDEO_MAX_BYTES, 1024 * 1024) . ' MB or smaller.');
    }
    [$poster] = memoryImage($poster, MEMORY_THUMB_MAX_BASE64, 'The video preview was too large.');

    return [
        'kind' => 'video',
        'checksum' => str_repeat('0', 40),
        'mime' => $mime,
        'width' => max(1, min(65535, (int) $width)),
        'height' => max(1, min(65535, (int) $height)),
        'duration_ms' => max(0, min(86400000, (int) round((float) $duration * 1000))),
        'size_bytes' => $size,
        'photo' => '',
        'thumb' => $poster,
    ];
}

function addMemoryMedia(PDO $pdo, int $postId, array $row, bool $complete = true): int
{
    $position = (int) $pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM memory_photos WHERE post_id = ' . $postId)->fetchColumn();
    $statement = $pdo->prepare(
        'INSERT INTO memory_photos (post_id, position, kind, checksum, mime, width, height, duration_ms, size_bytes, complete, uploaded_at, photo, thumb)
         VALUES (:post_id, :position, :kind, :checksum, :mime, :width, :height, :duration_ms, :size_bytes, :complete, NOW(), :photo, :thumb)'
    );
    $statement->execute(['post_id' => $postId, 'position' => $position, 'complete' => $complete ? 1 : 0] + $row);

    return (int) $pdo->lastInsertId();
}

function pendingVideo(PDO $pdo, int $mediaId, int $postId): array
{
    $statement = $pdo->prepare("SELECT id, size_bytes FROM memory_photos WHERE id = :id AND post_id = :post_id AND kind = 'video' AND complete = 0");
    $statement->execute(['id' => $mediaId, 'post_id' => $postId]);
    $row = $statement->fetch();
    if (!$row) {
        throw new InvalidArgumentException('That video upload has expired. Please add the video again.');
    }

    return $row;
}

// Stores one 512 KB piece of a video, sent as a binary file upload.
function addVideoChunk(PDO $pdo, int $mediaId, int $postId, int $seq, mixed $upload): void
{
    $video = pendingVideo($pdo, $mediaId, $postId);
    $expected = (int) ceil((int) $video['size_bytes'] / MEMORY_VIDEO_CHUNK_BYTES);
    if ($seq < 0 || $seq >= $expected) {
        throw new InvalidArgumentException('A video piece arrived out of order. Please try again.');
    }
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('A video piece did not arrive. Please try again.');
    }
    $binary = (string) file_get_contents($upload['tmp_name']);
    $isLast = $seq === $expected - 1;
    $expectedLength = $isLast ? (int) $video['size_bytes'] - $seq * MEMORY_VIDEO_CHUNK_BYTES : MEMORY_VIDEO_CHUNK_BYTES;
    if (strlen($binary) !== $expectedLength) {
        throw new InvalidArgumentException('A video piece was damaged on the way. Please try again.');
    }
    $pdo->prepare('REPLACE INTO memory_video_chunks (media_id, seq, data) VALUES (:media_id, :seq, :data)')
        ->execute(['media_id' => $mediaId, 'seq' => $seq, 'data' => base64_encode($binary)]);
}

// Checks every piece arrived, fingerprints the whole video and makes it visible.
function finishVideo(PDO $pdo, int $mediaId, int $postId): void
{
    $video = pendingVideo($pdo, $mediaId, $postId);
    $expected = (int) ceil((int) $video['size_bytes'] / MEMORY_VIDEO_CHUNK_BYTES);
    $hash = hash_init('sha1');
    $total = 0;
    $chunk = $pdo->prepare('SELECT data FROM memory_video_chunks WHERE media_id = :id AND seq = :seq');
    for ($seq = 0; $seq < $expected; $seq++) {
        $chunk->execute(['id' => $mediaId, 'seq' => $seq]);
        $data = $chunk->fetchColumn();
        if ($data === false) {
            throw new InvalidArgumentException('Part of the video is missing. Please add it again.');
        }
        $binary = base64_decode($data);
        hash_update($hash, $binary);
        $total += strlen($binary);
    }
    if ($total !== (int) $video['size_bytes']) {
        throw new InvalidArgumentException('The video did not arrive complete. Please add it again.');
    }
    $pdo->prepare('UPDATE memory_photos SET checksum = :checksum, complete = 1 WHERE id = :id')
        ->execute(['checksum' => hash_final($hash), 'id' => $mediaId]);
}

function memoryPostExists(PDO $pdo, int $postId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM memory_posts WHERE id = :id');
    $statement->execute(['id' => $postId]);

    return (bool) $statement->fetchColumn();
}

function touchMemoryPost(PDO $pdo, int $postId): void
{
    $pdo->prepare('UPDATE memory_posts SET updated_at = GREATEST(NOW(), created_at + INTERVAL 1 SECOND) WHERE id = :id')->execute(['id' => $postId]);
}

// Streams one photo, its thumbnail, or a video's poster frame. Stored media never changes, so the browser may cache it for good.
function sendMemoryPhoto(PDO $pdo, int $id, bool $thumb): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $statement = $pdo->prepare('SELECT checksum, mime, kind, ' . ($thumb ? 'thumb' : 'photo') . ' AS data, thumb FROM memory_photos WHERE id = :id AND complete = 1');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    if (!$row) {
        http_response_code(404);
        exit;
    }
    $data = $row['kind'] === 'video' ? $row['thumb'] : $row['data'];
    $etag = '"' . $row['checksum'] . ($thumb || $row['kind'] === 'video' ? '-t' : '') . '"';
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    $binary = base64_decode($data);
    $info = @getimagesizefromstring($binary);
    header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
    header('Content-Length: ' . strlen($binary));
    if (($_GET['download'] ?? '') === '1') {
        header('Content-Disposition: attachment; filename="tsv-memory-' . $id . '.jpg"');
    }
    echo $binary;
    exit;
}

// Streams a video with HTTP range support, so the player can seek without downloading all of it.
function sendMemoryVideo(PDO $pdo, int $id): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $statement = $pdo->prepare("SELECT checksum, mime, size_bytes FROM memory_photos WHERE id = :id AND kind = 'video' AND complete = 1");
    $statement->execute(['id' => $id]);
    $video = $statement->fetch();
    if (!$video) {
        http_response_code(404);
        exit;
    }
    $total = (int) $video['size_bytes'];
    // Chrome plays H.264 .mov files when they are labelled as MP4.
    $mime = $video['mime'] === 'video/quicktime' ? 'video/mp4' : $video['mime'];
    $start = 0;
    $end = $total - 1;
    $partial = false;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) ($_SERVER['HTTP_RANGE'] ?? '')), $range) && ($range[1] !== '' || $range[2] !== '')) {
        $partial = true;
        if ($range[1] === '') {
            $start = max(0, $total - (int) $range[2]);
        } else {
            $start = (int) $range[1];
            // Open-ended requests get a few MB at a time; the player asks for more as it goes.
            $end = $range[2] === '' ? min($total - 1, $start + 4 * 1024 * 1024 - 1) : min($total - 1, (int) $range[2]);
        }
        if ($start >= $total || $start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */{$total}");
            exit;
        }
    }
    set_time_limit(0);
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: "' . $video['checksum'] . '"');
    header('Content-Length: ' . ($end - $start + 1));
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$total}");
    }
    if (($_GET['download'] ?? '') === '1') {
        $extension = ['video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/ogg' => 'ogv'][$video['mime']] ?? 'mp4';
        header('Content-Disposition: attachment; filename="tsv-memory-' . $id . '.' . $extension . '"');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }
    $chunk = $pdo->prepare('SELECT data FROM memory_video_chunks WHERE media_id = :id AND seq = :seq');
    for ($seq = intdiv($start, MEMORY_VIDEO_CHUNK_BYTES); $seq <= intdiv($end, MEMORY_VIDEO_CHUNK_BYTES); $seq++) {
        $chunk->execute(['id' => $id, 'seq' => $seq]);
        $binary = base64_decode((string) $chunk->fetchColumn());
        $chunkStart = $seq * MEMORY_VIDEO_CHUNK_BYTES;
        $from = max(0, $start - $chunkStart);
        $to = min(strlen($binary), $end - $chunkStart + 1);
        echo substr($binary, $from, $to - $from);
        flush();
        if (connection_aborted()) {
            break;
        }
    }
    exit;
}

// ---- Backup and restore ----

// Writes the memory tables into an SQL backup: one photo, or one video piece, per INSERT so each statement stays under 1 MB.
function writeMemoriesSql(PDO $pdo, callable $write): void
{
    foreach (['memory_posts', 'memory_photos', 'memory_video_chunks'] as $table) {
        $create = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
        $write("\n" . preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create) . ";\n");
    }
    $quote = static fn(mixed $value): string => $value === null ? 'NULL' : (is_int($value) ? (string) $value : $pdo->quote((string) $value));

    $posts = $pdo->query('SELECT ' . implode(', ', MEMORY_POST_COLUMNS) . ' FROM memory_posts ORDER BY id')->fetchAll();
    foreach (array_chunk($posts, 100) as $chunk) {
        $write("\nINSERT INTO `memory_posts` (`" . implode('`, `', MEMORY_POST_COLUMNS) . "`) VALUES\n"
            . implode(",\n", array_map(
                static fn(array $post): string => '(' . implode(', ', array_map($quote, array_values($post))) . ')',
                $chunk
            )) . ";\n");
    }

    $media = $pdo->prepare('SELECT ' . implode(', ', MEMORY_PHOTO_COLUMNS) . ' FROM memory_photos WHERE id = :id');
    $chunks = $pdo->prepare('SELECT seq FROM memory_video_chunks WHERE media_id = :id ORDER BY seq');
    $piece = $pdo->prepare('SELECT ' . implode(', ', MEMORY_CHUNK_COLUMNS) . ' FROM memory_video_chunks WHERE media_id = :id AND seq = :seq');
    foreach ($pdo->query('SELECT id FROM memory_photos WHERE complete = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $mediaId) {
        $media->execute(['id' => $mediaId]);
        $row = $media->fetch();
        $write("INSERT INTO `memory_photos` (`" . implode('`, `', MEMORY_PHOTO_COLUMNS) . '`) VALUES ('
            . implode(', ', array_map($quote, array_values($row))) . ");\n");
        if ($row['kind'] === 'video') {
            $chunks->execute(['id' => $mediaId]);
            foreach ($chunks->fetchAll(PDO::FETCH_COLUMN) as $seq) {
                $piece->execute(['id' => $mediaId, 'seq' => $seq]);
                $write("INSERT INTO `memory_video_chunks` (`" . implode('`, `', MEMORY_CHUNK_COLUMNS) . '`) VALUES ('
                    . implode(', ', array_map($quote, array_values($piece->fetch()))) . ");\n");
            }
        }
    }
}

// Calls $each for every row of a table's INSERT statements in an SQL backup, without executing anything.
function eachSqlRow(string $sql, string $table, array $defaultColumns, callable $each): void
{
    preg_match_all(
        '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . $table . '`?\s*(?:\(([^)]*)\))?\s*VALUES/i',
        $sql,
        $inserts,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    foreach ($inserts as $insert) {
        $columns = isset($insert[1]) && $insert[1][0] !== ''
            ? array_map(static fn(string $column): string => trim($column, " `\t\r\n"), explode(',', $insert[1][0]))
            : $defaultColumns;
        foreach (readSqlTuples($sql, $insert[0][1] + strlen($insert[0][0])) as $tuple) {
            if (count($tuple) === count($columns)) {
                $each(array_combine($columns, $tuple));
            }
        }
    }
}

// Reads the memory posts and media details out of an SQL backup. Video pieces are not loaded here;
// importMemories() reads them straight from the file so a large backup is not held in memory twice.
function parseMemorySqlDump(string $sql): array
{
    $stamp = static fn(mixed $value): string => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)
        ? $value
        : date('Y-m-d H:i:s');
    $posts = [];
    eachSqlRow($sql, 'memory_posts', MEMORY_POST_COLUMNS, static function (array $row) use (&$posts, $stamp): void {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($row['taken_on'] ?? ''));
        if (!$date || !is_numeric($row['id'] ?? null)) {
            return;
        }
        $posts[(int) $row['id']] = [
            'taken_on' => $date->format('Y-m-d'),
            'caption' => mb_substr((string) ($row['caption'] ?? ''), 0, MEMORY_CAPTION_MAX),
            'created_at' => $stamp($row['created_at'] ?? null),
            'updated_at' => $stamp($row['updated_at'] ?? $row['created_at'] ?? null),
            'photos' => [],
        ];
    });
    $counts = ['photo' => 0, 'video' => 0];
    // Older backups have no kind/duration/size columns; their rows are photos.
    eachSqlRow($sql, 'memory_photos', MEMORY_PHOTO_COLUMNS, static function (array $row) use (&$posts, &$counts): void {
        $postId = (int) ($row['post_id'] ?? 0);
        if (!isset($posts[$postId])) {
            return;
        }
        try {
            if (($row['kind'] ?? 'photo') === 'video') {
                $media = memoryVideoRow($row['mime'] ?? '', $row['size_bytes'] ?? 0, $row['width'] ?? 0, $row['height'] ?? 0, ((int) ($row['duration_ms'] ?? 0)) / 1000, $row['thumb'] ?? '');
                if (!preg_match('/^[0-9a-f]{40}$/', (string) ($row['checksum'] ?? ''))) {
                    return;
                }
                $media['checksum'] = $row['checksum'];
            } else {
                $media = memoryPhotoRow($row['photo'] ?? '', $row['thumb'] ?? '');
            }
        } catch (InvalidArgumentException) {
            return;
        }
        $posts[$postId]['photos'][] = ['old_id' => (int) ($row['id'] ?? 0), 'position' => (int) ($row['position'] ?? 0)] + $media;
        $counts[$media['kind']]++;
    });

    return ['posts' => array_values($posts), 'photo_count' => $counts['photo'], 'video_count' => $counts['video']];
}

// Replace clears the memories first; add skips media that is already stored (same file = same checksum),
// so importing the same backup twice does not duplicate anything.
function importMemories(PDO $pdo, array $posts, bool $replace, string $sql): array
{
    if ($replace) {
        $pdo->exec('DELETE FROM memory_video_chunks');
        $pdo->exec('DELETE FROM memory_photos');
        $pdo->exec('DELETE FROM memory_posts');
    }
    $known = $replace ? [] : array_flip($pdo->query('SELECT checksum FROM memory_photos WHERE complete = 1')->fetchAll(PDO::FETCH_COLUMN));
    $insertPost = $pdo->prepare(
        'INSERT INTO memory_posts (taken_on, caption, created_at, updated_at) VALUES (:taken_on, :caption, :created_at, :updated_at)'
    );
    $insertMedia = $pdo->prepare(
        'INSERT INTO memory_photos (post_id, position, kind, checksum, mime, width, height, duration_ms, size_bytes, complete, uploaded_at, photo, thumb)
         VALUES (:post_id, :position, :kind, :checksum, :mime, :width, :height, :duration_ms, :size_bytes, :complete, NOW(), :photo, :thumb)'
    );
    $added = ['posts' => 0, 'photos' => 0, 'videos' => 0];
    $videoIds = [];
    foreach ($posts as $post) {
        $media = array_values(array_filter($post['photos'], static fn(array $item): bool => !isset($known[$item['checksum']])));
        if (!$media && $post['photos']) {
            continue;
        }
        $insertPost->execute([
            'taken_on' => $post['taken_on'],
            'caption' => $post['caption'],
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
        ]);
        $postId = (int) $pdo->lastInsertId();
        $added['posts']++;
        foreach ($media as $item) {
            $oldId = $item['old_id'];
            unset($item['old_id']);
            $isVideo = $item['kind'] === 'video';
            // A video only becomes visible once all of its pieces are in.
            $insertMedia->execute(['post_id' => $postId, 'complete' => $isVideo ? 0 : 1] + $item);
            $known[$item['checksum']] = true;
            if ($isVideo) {
                $videoIds[$oldId] = ['id' => (int) $pdo->lastInsertId(), 'size' => $item['size_bytes'], 'checksum' => $item['checksum']];
            } else {
                $added['photos']++;
            }
        }
    }

    if ($videoIds) {
        $insertChunk = $pdo->prepare('REPLACE INTO memory_video_chunks (media_id, seq, data) VALUES (:media_id, :seq, :data)');
        eachSqlRow($sql, 'memory_video_chunks', MEMORY_CHUNK_COLUMNS, static function (array $row) use ($videoIds, $insertChunk): void {
            $video = $videoIds[(int) ($row['media_id'] ?? 0)] ?? null;
            $data = (string) ($row['data'] ?? '');
            if ($video && is_numeric($row['seq'] ?? null) && $data !== '' && strlen($data) <= (int) ceil(MEMORY_VIDEO_CHUNK_BYTES / 3) * 4) {
                $insertChunk->execute(['media_id' => $video['id'], 'seq' => (int) $row['seq'], 'data' => $data]);
            }
        });
        foreach ($videoIds as $video) {
            // Same fingerprint check as a fresh upload; a video with missing pieces is dropped.
            $postId = (int) $pdo->query('SELECT post_id FROM memory_photos WHERE id = ' . $video['id'])->fetchColumn();
            try {
                finishVideo($pdo, $video['id'], $postId);
                $added['videos']++;
            } catch (InvalidArgumentException) {
                deleteMemoryMedia($pdo, $video['id']);
            }
        }
    }

    return $added;
}
