<?php

class Migration_018 extends Migration {
    public function __construct() {
        parent::__construct(17, 18);
    }

    function migrate($pdo) {
        $this->addColumnIfMissing(
            $pdo,
            'reporters',
            'report_format',
            "VARCHAR(20) NOT NULL DEFAULT 'meshlog'",
            'hash_size'
        );
        $this->addColumnIfMissing(
            $pdo,
            'reporters',
            'iata_code',
            "VARCHAR(8) NOT NULL DEFAULT ''",
            'report_format'
        );

        $pdo->exec("UPDATE `reporters` SET `report_format` = 'meshlog' WHERE `report_format` IS NULL OR `report_format` = ''");
        $pdo->exec("UPDATE `reporters` SET `iata_code` = '' WHERE `iata_code` IS NULL");

        return array(
            'success' => true,
            'message' => ''
        );
    }

    private function addColumnIfMissing($pdo, $table, $column, $columnSql, $afterColumn) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
        );
        $stmt->execute(array(
            ':table' => $table,
            ':column' => $column,
        ));

        if ((int)$stmt->fetchColumn() > 0) return;

        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $columnSql AFTER `$afterColumn`");
    }
}

?>