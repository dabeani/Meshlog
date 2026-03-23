<?php

class Migration_016 extends Migration {
    public function __construct() {
        parent::__construct(15, 16);
    }

    function migrate($pdo) {
        $this->addNullableTinyIntColumn($pdo, 'advertisement_reports', 'route_type', 'scope');
        $this->addNullableTinyIntColumn($pdo, 'channel_message_reports', 'route_type', 'scope');
        $this->addNullableTinyIntColumn($pdo, 'direct_message_reports', 'route_type', 'scope');
        $this->addNullableTinyIntColumn($pdo, 'raw_packets', 'route_type', 'scope');

        return array(
            'success' => true,
            'message' => ''
        );
    }

    private function addNullableTinyIntColumn($pdo, $table, $column, $afterColumn) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
        );
        $stmt->execute(array(
            ':table' => $table,
            ':column' => $column,
        ));

        if ((int)$stmt->fetchColumn() > 0) return;

        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `$afterColumn`");
    }
}

?>