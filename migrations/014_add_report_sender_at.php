<?php

class Migration_014 extends Migration {
    public function __construct() {
        parent::__construct(13, 14);
    }

    function migrate($pdo) {
        $this->addNullableDateTimeColumn($pdo, 'advertisement_reports', 'sender_at', 'received_at');
        $this->addNullableDateTimeColumn($pdo, 'channel_message_reports', 'sender_at', 'received_at');
        $this->addNullableDateTimeColumn($pdo, 'direct_message_reports', 'sender_at', 'received_at');

        return array(
            'success' => true,
            'message' => ''
        );
    }

    private function addNullableDateTimeColumn($pdo, $table, $column, $afterColumn) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
        );
        $stmt->execute(array(
            ':table' => $table,
            ':column' => $column,
        ));

        if ((int)$stmt->fetchColumn() > 0) return;

        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` DATETIME(3) NULL DEFAULT NULL AFTER `$afterColumn`");
    }
}

?>