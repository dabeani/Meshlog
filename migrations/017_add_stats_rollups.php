<?php

class Migration_017 extends Migration {
    public function __construct() {
        parent::__construct(16, 17);
    }

    private function tableExists($pdo, $tableName) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name"
        );
        $stmt->execute(array(':table_name' => $tableName));

        return intval($stmt->fetchColumn()) > 0;
    }

    private function getBucketExpr($columnName) {
        return "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP($columnName) / 300) * 300)";
    }

    private function backfillCollectorRollup($pdo, $tableName, $packetType) {
        if (!$this->tableExists($pdo, $tableName)) {
            return;
        }

        $pdo->exec(
            "INSERT INTO `stats_collector_packets_rollup` (
                `bucket_start`,
                `reporter_id`,
                `packet_type`,
                `packet_count`
            )
            SELECT
                " . $this->getBucketExpr('created_at') . " AS bucket_start,
                `reporter_id`,
                '" . $packetType . "' AS packet_type,
                COUNT(*) AS packet_count
            FROM `" . $tableName . "`
            GROUP BY bucket_start, `reporter_id`"
        );
    }

    function migrate($pdo) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `stats_adv_reports_rollup` (
                `bucket_start` DATETIME NOT NULL,
                `report_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `direct_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `flood_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `unknown_route_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `relayed_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `no_hop_count` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`bucket_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `stats_adv_entities_rollup` (
                `bucket_start` DATETIME NOT NULL,
                `advertisement_id` INT(11) NOT NULL,
                `contact_id` INT(11) NOT NULL,
                PRIMARY KEY (`bucket_start`, `advertisement_id`),
                KEY `stats_adv_entities_contact_idx` (`bucket_start`, `contact_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `stats_collector_packets_rollup` (
                `bucket_start` DATETIME NOT NULL,
                `reporter_id` INT(11) NOT NULL,
                `packet_type` VARCHAR(8) NOT NULL,
                `packet_count` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`bucket_start`, `reporter_id`, `packet_type`),
                KEY `stats_collector_packets_reporter_idx` (`bucket_start`, `reporter_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec("TRUNCATE TABLE `stats_adv_reports_rollup`");
        $pdo->exec("TRUNCATE TABLE `stats_adv_entities_rollup`");
        $pdo->exec("TRUNCATE TABLE `stats_collector_packets_rollup`");

        if ($this->tableExists($pdo, 'advertisement_reports') && $this->tableExists($pdo, 'advertisements')) {
            $pdo->exec(
                "INSERT INTO `stats_adv_reports_rollup` (
                    `bucket_start`,
                    `report_count`,
                    `direct_count`,
                    `flood_count`,
                    `unknown_route_count`,
                    `relayed_count`,
                    `no_hop_count`
                )
                SELECT
                    " . $this->getBucketExpr('ar.created_at') . " AS bucket_start,
                    COUNT(*) AS report_count,
                    SUM(CASE WHEN ar.route_type IN (2, 3) THEN 1 ELSE 0 END) AS direct_count,
                    SUM(CASE WHEN ar.route_type IN (0, 1) THEN 1 ELSE 0 END) AS flood_count,
                    SUM(CASE WHEN ar.route_type IS NULL THEN 1 ELSE 0 END) AS unknown_route_count,
                    SUM(CASE WHEN ar.path IS NOT NULL AND ar.path != '' THEN 1 ELSE 0 END) AS relayed_count,
                    SUM(CASE WHEN ar.path IS NULL OR ar.path = '' THEN 1 ELSE 0 END) AS no_hop_count
                FROM `advertisement_reports` ar
                GROUP BY bucket_start"
            );

            $pdo->exec(
                "INSERT INTO `stats_adv_entities_rollup` (
                    `bucket_start`,
                    `advertisement_id`,
                    `contact_id`
                )
                SELECT DISTINCT
                    " . $this->getBucketExpr('ar.created_at') . " AS bucket_start,
                    ar.advertisement_id,
                    a.contact_id
                FROM `advertisement_reports` ar
                INNER JOIN `advertisements` a ON a.id = ar.advertisement_id
                WHERE a.contact_id IS NOT NULL"
            );
        }

        $this->backfillCollectorRollup($pdo, 'advertisement_reports', 'ADV');
        $this->backfillCollectorRollup($pdo, 'direct_message_reports', 'DIR');
        $this->backfillCollectorRollup($pdo, 'channel_message_reports', 'PUB');
        $this->backfillCollectorRollup($pdo, 'telemetry', 'TEL');
        $this->backfillCollectorRollup($pdo, 'system_reports', 'SYS');
        $this->backfillCollectorRollup($pdo, 'raw_packets', 'RAW');

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>
