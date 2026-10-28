<?php
// includes/gtfs_import.php - Trình nhập, giải nén và kiểm tra (validate) bộ dữ liệu GTFS tĩnh
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/gtfs_functions.php';

/**
 * Lớp xử lý Nhập và Validate GTFS
 */
class GTFSImporter {
    private PDO $pdo;
    private array $errors = [];
    private array $parsedData = [];

    // Các tệp bắt buộc và cột bắt buộc theo chuẩn GTFS hỗ trợ
    private array $requiredFiles = [
        'agency.txt' => ['agency_id', 'agency_name', 'agency_url', 'agency_timezone'],
        'routes.txt' => ['route_id', 'route_short_name', 'route_long_name', 'route_type'],
        'stops.txt' => ['stop_id', 'stop_name'],
        'calendar.txt' => ['service_id', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date'],
        'trips.txt' => ['trip_id', 'route_id', 'service_id'],
        'stop_times.txt' => ['trip_id', 'stop_id', 'arrival_time', 'departure_time', 'stop_sequence']
    ];

    // Cột của tệp tùy chọn
    private array $optionalFiles = [
        'calendar_dates.txt' => ['service_id', 'date', 'exception_type'],
        'shapes.txt' => ['shape_id', 'shape_pt_lat', 'shape_pt_lon', 'shape_pt_sequence']
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Nhập từ file ZIP
     */
    public function importZip(string $zipFilePath, string $versionName, int $uploadedBy, ?string $notes = null): array {
        $this->errors = [];
        $this->parsedData = [];

        $stmt = $this->pdo->prepare("INSERT INTO gtfs_feed_versions (version_name, uploaded_by, status, notes) VALUES (?, ?, 'pending', ?)");
        $stmt->execute([$versionName, $uploadedBy, $notes]);
        $versionId = (int)$this->pdo->lastInsertId();

        if (!class_exists('ZipArchive')) {
            $this->logError($versionId, 'system', null, null, null, 'Máy chủ PHP chưa bật extension ZipArchive.');
            $this->finalizeVersion($versionId, 'invalid');
            return ['success' => false, 'version_id' => $versionId, 'errors' => $this->errors];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            $this->logError($versionId, 'invalid_format', null, null, null, 'Không thể mở tệp ZIP. Tệp bị hỏng hoặc sai định dạng.');
            $this->finalizeVersion($versionId, 'invalid');
            return ['success' => false, 'version_id' => $versionId, 'errors' => $this->errors];
        }

        $tempExtractDir = sys_get_temp_dir() . '/gtfs_' . uniqid() . '/';
        if (!mkdir($tempExtractDir, 0777, true)) {
            $zip->close();
            $this->logError($versionId, 'system', null, null, null, 'Không thể tạo thư mục tạm để giải nén.');
            $this->finalizeVersion($versionId, 'invalid');
            return ['success' => false, 'version_id' => $versionId, 'errors' => $this->errors];
        }

        $zip->extractTo($tempExtractDir);
        $zip->close();

        $result = $this->processExtractedDirectory($tempExtractDir, $versionId);
        $this->deleteDirectory($tempExtractDir);

        return $result;
    }

    /**
     * Nhập trực tiếp từ thư mục chứa các tệp GTFS (.txt) có sẵn
     */
    public function importDirectory(string $dirPath, string $versionName, int $uploadedBy, ?string $notes = null): array {
        $this->errors = [];
        $this->parsedData = [];

        $stmt = $this->pdo->prepare("INSERT INTO gtfs_feed_versions (version_name, uploaded_by, status, notes) VALUES (?, ?, 'pending', ?)");
        $stmt->execute([$versionName, $uploadedBy, $notes]);
        $versionId = (int)$this->pdo->lastInsertId();

        if (!is_dir($dirPath)) {
            $this->logError($versionId, 'system', null, null, null, 'Thư mục không tồn tại: ' . $dirPath);
            $this->finalizeVersion($versionId, 'invalid');
            return ['success' => false, 'version_id' => $versionId, 'errors' => $this->errors];
        }

        return $this->processExtractedDirectory($dirPath, $versionId);
    }

    /**
     * Xử lý và kiểm tra các file GTFS đã giải nén
     */
    private function processExtractedDirectory(string $dir, int $versionId): array {
        $fileMap = $this->locateGtfsFiles($dir);

        // Bước 1: Kiểm tra sự tồn tại của các tệp bắt buộc
        foreach ($this->requiredFiles as $fileName => $requiredCols) {
            if (!isset($fileMap[$fileName])) {
                $this->logError($versionId, 'missing_file', $fileName, null, null, "Thiếu tệp bắt buộc: {$fileName}");
            }
        }

        if (!empty($this->errors)) {
            $this->saveErrorsToDb();
            $this->finalizeVersion($versionId, 'invalid');
            return ['success' => false, 'version_id' => $versionId, 'errors' => $this->errors];
        }

        // Bước 2: Đọc và kiểm tra cấu trúc cột của từng file
        $allFilesToCheck = array_merge($this->requiredFiles, $this->optionalFiles);
        foreach ($allFilesToCheck as $fileName => $requiredCols) {
            if (isset($fileMap[$fileName])) {
                $this->readAndValidateCsv($fileMap[$fileName], $fileName, $requiredCols, $versionId);
            }
        }

        // Bước 3: Kiểm tra tính toàn vẹn tham chiếu (Foreign Keys logic giữa các file)
        $this->validateForeignReferences($versionId);

        // Bước 4: Kiểm tra định dạng giờ và ngày
        $this->validateDataFormats($versionId);

        if (!empty($this->errors)) {
            $this->saveErrorsToDb();
            $this->finalizeVersion($versionId, 'invalid');
            return ['success' => false, 'version_id' => $versionId, 'errors' => $this->errors];
        }

        // Bước 5: Lưu vào CSDL
        $stats = $this->saveDataToDatabase($versionId);
        $this->finalizeVersion($versionId, 'valid');

        return [
            'success' => true,
            'version_id' => $versionId,
            'errors' => [],
            'stats' => $stats
        ];
    }

    private function locateGtfsFiles(string $dir): array {
        $map = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $baseName = strtolower($file->getBasename());
                if (str_ends_with($baseName, '.txt')) {
                    $map[$baseName] = $file->getPathname();
                }
            }
        }
        return $map;
    }

    private function readAndValidateCsv(string $filePath, string $fileName, array $requiredCols, int $versionId): void {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->logError($versionId, 'file_read_error', $fileName, null, null, "Không thể đọc tệp {$fileName}");
            return;
        }

        $header = fgetcsv($handle);
        if (!$header) {
            $this->logError($versionId, 'empty_file', $fileName, null, 1, "Tệp {$fileName} hoàn toàn trống hoặc không có dòng tiêu đề");
            fclose($handle);
            return;
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        foreach ($requiredCols as $col) {
            if (!in_array($col, $header)) {
                $this->logError($versionId, 'missing_column', $fileName, $col, 1, "Tệp {$fileName} thiếu cột bắt buộc: '{$col}'");
            }
        }

        $rows = [];
        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($data) === 1 && trim($data[0]) === '') {
                continue;
            }

            if (count($data) !== count($header)) {
                $this->logError($versionId, 'invalid_format', $fileName, null, $rowNum, "Dòng {$rowNum} có số cột (" . count($data) . ") không khớp với dòng tiêu đề (" . count($header) . ")");
                continue;
            }

            $rowAssoc = array_combine($header, array_map('trim', $data));
            $rows[] = ['row_num' => $rowNum, 'data' => $rowAssoc];
        }

        fclose($handle);
        $this->parsedData[$fileName] = $rows;
    }

    private function validateForeignReferences(int $versionId): void {
        $agencyIds = [];
        if (isset($this->parsedData['agency.txt'])) {
            foreach ($this->parsedData['agency.txt'] as $item) {
                $agencyIds[$item['data']['agency_id']] = true;
            }
        }

        $routeIds = [];
        if (isset($this->parsedData['routes.txt'])) {
            foreach ($this->parsedData['routes.txt'] as $item) {
                $rId = $item['data']['route_id'];
                $routeIds[$rId] = true;

                $aId = $item['data']['agency_id'] ?? '';
                if ($aId !== '' && !isset($agencyIds[$aId])) {
                    $this->logError($versionId, 'invalid_ref', 'routes.txt', 'agency_id', $item['row_num'],
                        "Mã agency_id '{$aId}' ở tuyến '{$rId}' không tồn tại trong agency.txt");
                }
            }
        }

        $serviceIds = [];
        if (isset($this->parsedData['calendar.txt'])) {
            foreach ($this->parsedData['calendar.txt'] as $item) {
                $serviceIds[$item['data']['service_id']] = true;
            }
        }
        if (isset($this->parsedData['calendar_dates.txt'])) {
            foreach ($this->parsedData['calendar_dates.txt'] as $item) {
                $serviceIds[$item['data']['service_id']] = true;
            }
        }

        $stopIds = [];
        if (isset($this->parsedData['stops.txt'])) {
            foreach ($this->parsedData['stops.txt'] as $item) {
                $stopIds[$item['data']['stop_id']] = true;
            }
        }

        $tripIds = [];
        if (isset($this->parsedData['trips.txt'])) {
            foreach ($this->parsedData['trips.txt'] as $item) {
                $tId = $item['data']['trip_id'];
                $rId = $item['data']['route_id'];
                $sId = $item['data']['service_id'];

                $tripIds[$tId] = true;

                if (!isset($routeIds[$rId])) {
                    $this->logError($versionId, 'invalid_ref', 'trips.txt', 'route_id', $item['row_num'],
                        "Mã route_id '{$rId}' ở chuyến '{$tId}' không tồn tại trong routes.txt");
                }

                if (!isset($serviceIds[$sId])) {
                    $this->logError($versionId, 'invalid_ref', 'trips.txt', 'service_id', $item['row_num'],
                        "Mã service_id '{$sId}' ở chuyến '{$tId}' không tồn tại trong calendar.txt hoặc calendar_dates.txt");
                }
            }
        }

        if (isset($this->parsedData['stop_times.txt'])) {
            foreach ($this->parsedData['stop_times.txt'] as $item) {
                $tId = $item['data']['trip_id'];
                $sId = $item['data']['stop_id'];

                if (!isset($tripIds[$tId])) {
                    $this->logError($versionId, 'invalid_ref', 'stop_times.txt', 'trip_id', $item['row_num'],
                        "Mã trip_id '{$tId}' không tồn tại trong trips.txt");
                }

                if (!isset($stopIds[$sId])) {
                    $this->logError($versionId, 'invalid_ref', 'stop_times.txt', 'stop_id', $item['row_num'],
                        "Mã stop_id '{$sId}' không tồn tại trong stops.txt");
                }
            }
        }
    }

    private function validateDataFormats(int $versionId): void {
        // Kiểm tra định dạng shapes.txt (tùy chọn)
        if (isset($this->parsedData['shapes.txt'])) {
            foreach ($this->parsedData['shapes.txt'] as $item) {
                $d = $item['data'];
                $lat = $d['shape_pt_lat'] ?? '';
                $lon = $d['shape_pt_lon'] ?? '';
                if (!is_numeric($lat) || (float)$lat < -90 || (float)$lat > 90) {
                    $this->logError($versionId, 'invalid_format', 'shapes.txt', 'shape_pt_lat', $item['row_num'],
                        "Vĩ độ shape_pt_lat '{$lat}' không hợp lệ");
                }
                if (!is_numeric($lon) || (float)$lon < -180 || (float)$lon > 180) {
                    $this->logError($versionId, 'invalid_format', 'shapes.txt', 'shape_pt_lon', $item['row_num'],
                        "Kinh độ shape_pt_lon '{$lon}' không hợp lệ");
                }
                if (!ctype_digit((string)($d['shape_pt_sequence'] ?? ''))) {
                    $this->logError($versionId, 'invalid_format', 'shapes.txt', 'shape_pt_sequence', $item['row_num'],
                        "shape_pt_sequence '" . ($d['shape_pt_sequence'] ?? '') . "' phải là số nguyên không âm");
                }
            }
        }

        if (isset($this->parsedData['stop_times.txt'])) {
            foreach ($this->parsedData['stop_times.txt'] as $item) {
                $arr = $item['data']['arrival_time'] ?? '';
                $dep = $item['data']['departure_time'] ?? '';
                $seq = $item['data']['stop_sequence'] ?? '';

                if (!preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $arr)) {
                    $this->logError($versionId, 'invalid_format', 'stop_times.txt', 'arrival_time', $item['row_num'],
                        "Định dạng arrival_time '{$arr}' không đúng chuẩn HH:MM:SS");
                }
                if (!preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $dep)) {
                    $this->logError($versionId, 'invalid_format', 'stop_times.txt', 'departure_time', $item['row_num'],
                        "Định dạng departure_time '{$dep}' không đúng chuẩn HH:MM:SS");
                }
                if (!ctype_digit((string)$seq) || (int)$seq < 0) {
                    $this->logError($versionId, 'invalid_format', 'stop_times.txt', 'stop_sequence', $item['row_num'],
                        "stop_sequence '{$seq}' phải là số nguyên dương");
                }
            }
        }

        if (isset($this->parsedData['calendar.txt'])) {
            foreach ($this->parsedData['calendar.txt'] as $item) {
                $sDate = $this->normalizeDate($item['data']['start_date']);
                $eDate = $this->normalizeDate($item['data']['end_date']);

                if (!$sDate) {
                    $this->logError($versionId, 'invalid_format', 'calendar.txt', 'start_date', $item['row_num'],
                        "start_date '{$item['data']['start_date']}' sai định dạng (YYYYMMDD hoặc YYYY-MM-DD)");
                }
                if (!$eDate) {
                    $this->logError($versionId, 'invalid_format', 'calendar.txt', 'end_date', $item['row_num'],
                        "end_date '{$item['data']['end_date']}' sai định dạng (YYYYMMDD hoặc YYYY-MM-DD)");
                }
                if ($sDate && $eDate && $sDate > $eDate) {
                    $this->logError($versionId, 'invalid_format', 'calendar.txt', 'start_date', $item['row_num'],
                        "start_date ({$sDate}) không được lớn hơn end_date ({$eDate})");
                }
            }
        }

        if (isset($this->parsedData['calendar_dates.txt'])) {
            foreach ($this->parsedData['calendar_dates.txt'] as $item) {
                $d = $this->normalizeDate($item['data']['date']);
                $exType = $item['data']['exception_type'];

                if (!$d) {
                    $this->logError($versionId, 'invalid_format', 'calendar_dates.txt', 'date', $item['row_num'],
                        "date '{$item['data']['date']}' sai định dạng (YYYYMMDD hoặc YYYY-MM-DD)");
                }
                if (!in_array($exType, ['1', '2', 1, 2])) {
                    $this->logError($versionId, 'invalid_format', 'calendar_dates.txt', 'exception_type', $item['row_num'],
                        "exception_type '{$exType}' phải là 1 (thêm dịch vụ) hoặc 2 (hủy dịch vụ)");
                }
            }
        }
    }

    private function normalizeDate(string $raw): ?string {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $raw;
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
        }
        return null;
    }

    private function normalizeTime(string $time): string {
        $parts = explode(':', trim($time));
        if (count($parts) === 3) {
            return sprintf('%02d:%02d:%02d', (int)$parts[0], (int)$parts[1], (int)$parts[2]);
        }
        return $time;
    }

    private function saveDataToDatabase(int $versionId): array {
        $this->pdo->beginTransaction();
        $stats = [];

        try {
            // 1. agency
            $stmt = $this->pdo->prepare("INSERT INTO gtfs_agency (feed_version_id, agency_id, agency_name, agency_url, agency_timezone) VALUES (?, ?, ?, ?, ?)");
            $count = 0;
            if (isset($this->parsedData['agency.txt'])) {
                foreach ($this->parsedData['agency.txt'] as $item) {
                    $d = $item['data'];
                    $stmt->execute([
                        $versionId,
                        $d['agency_id'] ?? 'default',
                        $d['agency_name'] ?? '',
                        $d['agency_url'] ?? '',
                        $d['agency_timezone'] ?? 'Asia/Ho_Chi_Minh'
                    ]);
                    $count++;
                }
            }
            $stats['agency'] = $count;

            // 2. routes
            $stmt = $this->pdo->prepare("INSERT INTO gtfs_routes (feed_version_id, route_id, agency_id, route_short_name, route_long_name, route_type, route_color) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $count = 0;
            if (isset($this->parsedData['routes.txt'])) {
                foreach ($this->parsedData['routes.txt'] as $item) {
                    $d = $item['data'];
                    $stmt->execute([
                        $versionId,
                        $d['route_id'],
                        $d['agency_id'] ?? null,
                        $d['route_short_name'] ?? '',
                        $d['route_long_name'] ?? '',
                        (int)($d['route_type'] ?? 3),
                        $d['route_color'] ?? null
                    ]);
                    $count++;
                }
            }
            $stats['routes'] = $count;

            // 3. stops
            $stmt = $this->pdo->prepare("INSERT INTO gtfs_stops (feed_version_id, stop_id, stop_name, stop_lat, stop_lon) VALUES (?, ?, ?, ?, ?)");
            $count = 0;
            if (isset($this->parsedData['stops.txt'])) {
                foreach ($this->parsedData['stops.txt'] as $item) {
                    $d = $item['data'];
                    $stmt->execute([
                        $versionId,
                        $d['stop_id'],
                        $d['stop_name'],
                        isset($d['stop_lat']) && $d['stop_lat'] !== '' ? (float)$d['stop_lat'] : null,
                        isset($d['stop_lon']) && $d['stop_lon'] !== '' ? (float)$d['stop_lon'] : null,
                    ]);
                    $count++;
                }
            }
            $stats['stops'] = $count;

            // 4. calendar
            $stmt = $this->pdo->prepare("INSERT INTO gtfs_calendar (feed_version_id, service_id, monday, tuesday, wednesday, thursday, friday, saturday, sunday, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $count = 0;
            if (isset($this->parsedData['calendar.txt'])) {
                foreach ($this->parsedData['calendar.txt'] as $item) {
                    $d = $item['data'];
                    $stmt->execute([
                        $versionId,
                        $d['service_id'],
                        (int)$d['monday'],
                        (int)$d['tuesday'],
                        (int)$d['wednesday'],
                        (int)$d['thursday'],
                        (int)$d['friday'],
                        (int)$d['saturday'],
                        (int)$d['sunday'],
                        $this->normalizeDate($d['start_date']),
                        $this->normalizeDate($d['end_date']),
                    ]);
                    $count++;
                }
            }
            $stats['calendar'] = $count;

            // 5. calendar_dates
            $stmt = $this->pdo->prepare("INSERT INTO gtfs_calendar_dates (feed_version_id, service_id, date, exception_type) VALUES (?, ?, ?, ?)");
            $count = 0;
            if (isset($this->parsedData['calendar_dates.txt'])) {
                foreach ($this->parsedData['calendar_dates.txt'] as $item) {
                    $d = $item['data'];
                    $stmt->execute([
                        $versionId,
                        $d['service_id'],
                        $this->normalizeDate($d['date']),
                        (int)$d['exception_type']
                    ]);
                    $count++;
                }
            }
            $stats['calendar_dates'] = $count;

            // 6. trips (kèm shape_id nếu CSDL đã được nâng cấp bằng migration_add_shapes.sql)
            $hasShapeSupport = $this->hasShapeSupport();
            if ($hasShapeSupport) {
                $stmt = $this->pdo->prepare("INSERT INTO gtfs_trips (feed_version_id, trip_id, route_id, service_id, trip_headsign, direction_id, shape_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO gtfs_trips (feed_version_id, trip_id, route_id, service_id, trip_headsign, direction_id) VALUES (?, ?, ?, ?, ?, ?)");
            }
            $count = 0;
            if (isset($this->parsedData['trips.txt'])) {
                foreach ($this->parsedData['trips.txt'] as $item) {
                    $d = $item['data'];
                    $params = [
                        $versionId,
                        $d['trip_id'],
                        $d['route_id'],
                        $d['service_id'],
                        $d['trip_headsign'] ?? '',
                        isset($d['direction_id']) && $d['direction_id'] !== '' ? (int)$d['direction_id'] : null
                    ];
                    if ($hasShapeSupport) {
                        $params[] = (isset($d['shape_id']) && $d['shape_id'] !== '') ? $d['shape_id'] : null;
                    }
                    $stmt->execute($params);
                    $count++;
                }
            }
            $stats['trips'] = $count;

            // 7. stop_times
            $stmt = $this->pdo->prepare("INSERT INTO gtfs_stop_times (feed_version_id, trip_id, stop_id, arrival_time, departure_time, stop_sequence) VALUES (?, ?, ?, ?, ?, ?)");
            $count = 0;
            if (isset($this->parsedData['stop_times.txt'])) {
                foreach ($this->parsedData['stop_times.txt'] as $item) {
                    $d = $item['data'];
                    $stmt->execute([
                        $versionId,
                        $d['trip_id'],
                        $d['stop_id'],
                        $this->normalizeTime($d['arrival_time']),
                        $this->normalizeTime($d['departure_time']),
                        (int)$d['stop_sequence']
                    ]);
                    $count++;
                }
            }
            $stats['stop_times'] = $count;

            // 8. shapes (tùy chọn): nạp theo lô vì shapes.txt thường rất lớn
            if ($hasShapeSupport && isset($this->parsedData['shapes.txt'])) {
                $count = 0;
                foreach (array_chunk($this->parsedData['shapes.txt'], 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'));
                    $q = $this->pdo->prepare("INSERT INTO gtfs_shapes (feed_version_id, shape_id, shape_pt_lat, shape_pt_lon, shape_pt_sequence) VALUES {$ph}");
                    $vals = [];
                    foreach ($chunk as $item) {
                        $d = $item['data'];
                        array_push($vals, $versionId, $d['shape_id'], (float)$d['shape_pt_lat'], (float)$d['shape_pt_lon'], (int)$d['shape_pt_sequence']);
                    }
                    $q->execute($vals);
                    $count += count($chunk);
                }
                $stats['shapes'] = $count;
            }

            $this->pdo->commit();
            return $stats;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logError($versionId, 'db_error', null, null, null, 'Lỗi cơ sở dữ liệu khi lưu: ' . $e->getMessage());
            $this->saveErrorsToDb();
            return [];
        }
    }

    /**
     * Kiểm tra CSDL đã có cột gtfs_trips.shape_id và bảng gtfs_shapes chưa
     * (để vẫn chạy được với CSDL cũ chưa chạy migration_add_shapes.sql)
     */
    private function hasShapeSupport(): bool {
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM gtfs_trips LIKE 'shape_id'")->fetch();
            $tbl = $this->pdo->query("SHOW TABLES LIKE 'gtfs_shapes'")->fetch();
            return (bool)$col && (bool)$tbl;
        } catch (Exception $e) {
            return false;
        }
    }

    private function logError(int $versionId, string $errorType, ?string $fileName, ?string $colName, ?int $rowNum, string $msg): void {
        $this->errors[] = [
            'version_id' => $versionId,
            'error_type' => $errorType,
            'file_name' => $fileName,
            'column_name' => $colName,
            'row_number' => $rowNum,
            'message' => $msg
        ];
    }

    private function saveErrorsToDb(): void {
        if (empty($this->errors)) return;
        $stmt = $this->pdo->prepare("INSERT INTO gtfs_validation_errors (feed_version_id, error_type, file_name, column_name, row_number, message) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($this->errors as $err) {
            $stmt->execute([
                $err['version_id'],
                $err['error_type'],
                $err['file_name'],
                $err['column_name'],
                $err['row_number'],
                $err['message']
            ]);
        }
    }

    private function finalizeVersion(int $versionId, string $status): void {
        $stmt = $this->pdo->prepare("UPDATE gtfs_feed_versions SET status = ? WHERE id = ?");
        $stmt->execute([$status, $versionId]);
    }

    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
