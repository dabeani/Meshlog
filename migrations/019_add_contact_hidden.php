<?php

class Migration_019 extends Migration {
    public function __construct() {
        parent::__construct(18, 19);
    }

    function migrate($pdo) {
        $this->addColumnIfMissing(
            $pdo,
            'contacts',
            'hidden',
            "TINYINT(1) NOT NULL DEFAULT 0",
            'last_heard_at'
        );

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
