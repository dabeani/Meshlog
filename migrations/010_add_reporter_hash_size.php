<?php

class Migration_010 extends Migration {
    public function __construct() {
        parent::__construct(9, 10);
    }

    function migrate($pdo) {
        $pdo->exec("ALTER TABLE `reporters` ADD `hash_size` TINYINT NOT NULL DEFAULT '1' AFTER `auth`;");

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>