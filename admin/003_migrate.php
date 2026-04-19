<?php

function makePdo() {
    include '../config.php';
    $host = $config['db']['host'] ?? die("Invalid db config: host");
    $name = $config['db']['database'] ?? die("Invalid db config: database");
    $user = $config['db']['user'] ?? die("Invalid db config: user");
    $pass = $config['db']['password'] ?? die("Invalid db config: password");

    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function migrate($pdo, $src, $dst, $idcol) {
    $limit = 1000;
    $offset = 0;
    $MAX_TIME = 180; // 3 min time window for dedup
    $MAX_BATCH_SIZE = 500; // Max rows per INSERT (safety for max_allowed_packet)

    $bucket = array();
    $delete_queue = array();
    
    try {
        $pdo->beginTransaction();
        
        while (true) {
            $stmt = $pdo->prepare("SELECT * FROM `$src` LIMIT :limit OFFSET :offset");
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $offset += $limit;

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                // Flush remaining bucket at end of data
                flush_bucket($pdo, $bucket, $delete_queue, $dst, $idcol, $MAX_BATCH_SIZE);
                break;
            }

            // Accumulate rows by hash within time window
            foreach ($rows as $row) {
                $hash = $row['hash'];
                if (!$hash) continue;  // skip unrecoverable rows

                $time = strtotime($row['created_at']);
                $id = intval($row['id']);

                $report_data = array(
                    intval($row['reporter_id']),
                    strval($row['path'] ?? ''),
                    intval($row['snr'] ?? 0),
                    strval($row['received_at']),
                    strval($row['created_at']),
                );

                // Check if we already have this hash in bucket
                if (isset($bucket[$hash])) {
                    $tdelta = $time - $bucket[$hash]['first_time'];
                    if ($tdelta < $MAX_TIME) {
                        // Within time window: add to existing bucket
                        $bucket[$hash]['reports'][] = $report_data;
                        $delete_queue[] = $id;
                    } else {
                        // Time window expired: flush old bucket, start new one
                        flush_bucket($pdo, array($hash => $bucket[$hash]), $delete_queue, $dst, $idcol, $MAX_BATCH_SIZE);
                        $delete_queue = array();
                        unset($bucket[$hash]);

                        // Start new bucket for this hash
                        $bucket[$hash] = array(
                            'first_time' => $time,
                            'first_id' => $id,
                            'first_hash' => $hash,
                            'reports' => array($report_data)
                        );
                    }
                } else {
                    // New hash: create bucket
                    $bucket[$hash] = array(
                        'first_time' => $time,
                        'first_id' => $id,
                        'first_hash' => $hash,
                        'reports' => array($report_data)
                    );
                }

                // Flush if batch size limit approaching
                if (count_total_reports($bucket) > $MAX_BATCH_SIZE) {
                    flush_bucket($pdo, $bucket, $delete_queue, $dst, $idcol, $MAX_BATCH_SIZE);
                    $bucket = array();
                    $delete_queue = array();
                }
            }

            echo "--- Processed offset: $offset\n";
        }

        $pdo->commit();
        echo "Migration completed successfully.\n";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "FATAL: Migration failed: " . $e->getMessage() . "\n";
        throw $e;
    }
}

function count_total_reports($bucket) {
    $total = 0;
    foreach ($bucket as $entry) {
        $total += count($entry['reports'] ?? array());
    }
    return $total;
}

function flush_bucket($pdo, $bucket, $delete_queue, $dst, $idcol, $max_batch_size) {
    if (empty($bucket)) return;

    $values = array();
    $placeholders = array();
    $row_count = 0;

    // Build INSERT values, respecting batch size limit
    foreach ($bucket as $entry) {
        foreach ($entry['reports'] as $report) {
            if ($row_count >= $max_batch_size) break 2;

            $placeholders[] = "(?, ?, ?, ?, ?, ?)";
            $values[] = $entry['first_id'];        // $idcol (e.g., advertisement_id)
            $values[] = $report[0];                 // reporter_id
            $values[] = $report[1];                 // path
            $values[] = $report[2];                 // snr
            $values[] = $report[3];                 // received_at
            $values[] = $report[4];                 // created_at
            $row_count++;
        }
    }

    if (!empty($placeholders)) {
        $sql = "INSERT INTO `$dst` (`$idcol`, `reporter_id`, `path`, `snr`, `received_at`, `created_at`) VALUES ";
        $sql .= implode(", ", $placeholders);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        echo "  Inserted " . $stmt->rowCount() . " report rows.\n";
    }

    // Delete original rows from source table
    if (!empty($delete_queue)) {
        $placeholders = rtrim(str_repeat('?,', count($delete_queue)), ',');
        $sql = "DELETE FROM `$src` WHERE id IN ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($delete_queue);
        echo "  Deleted " . $stmt->rowCount() . " source rows.\n";
    }
}

function deleteCols($pdo, $src, $cols) {
    try {
        // Drop foreign key constraints on reporter_id
        $sql = "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE ";
        $sql .= "WHERE TABLE_NAME = ? AND COLUMN_NAME = 'reporter_id' AND TABLE_SCHEMA = DATABASE()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($src));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $sql = "ALTER TABLE `$src` DROP FOREIGN KEY `$name`";
            $pdo->exec($sql);
            echo "  Dropped foreign key: $name\n";
        }

        // Drop index on reporter_id
        $sql = "ALTER TABLE `$src` DROP INDEX reporter_id";
        $pdo->exec($sql);
        echo "  Dropped index: reporter_id\n";

        // Drop obsolete columns
        if (!empty($cols)) {
            $drops = array();
            foreach ($cols as $c) {
                $drops[] = "DROP COLUMN `$c`";
            }

            $sql = "ALTER TABLE `$src` " . implode(", ", $drops);
            $pdo->exec($sql);
            echo "  Dropped columns: " . implode(", ", $cols) . "\n";
        }
    } catch (Exception $e) {
        echo "WARNING: Error dropping columns from $src: " . $e->getMessage() . "\n";
        // Don't throw; allow migration to continue (some indexes may not exist)
    }
}

// Note: Migration runs synchronously with no timeout (see set_time_limit(0) below); for very large DBs may take significant time
// Future: consider adding async flag and progress tracking endpoint for client polling

// DB Operations will take a while
set_time_limit(0); // 0 = unlimited
ini_set('max_execution_time', 0);

// run
$pdo = makePdo();

// advertisements
migrate($pdo, "advertisements", "advertisement_reports", "advertisement_id");
deleteCols($pdo, "advertisements", array('reporter_id', 'path', 'snr', 'received_at'));

// channels
migrate($pdo, "channel_messages", "channel_message_reports", "channel_message_id");
deleteCols($pdo, "channel_messages", array('reporter_id', 'path', 'received_at'));

// DMs
migrate($pdo, "direct_messages", "direct_message_reports", "direct_message_id");
deleteCols($pdo, "direct_messages", array('reporter_id', 'path', 'received_at'));


?>