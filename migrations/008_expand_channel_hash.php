<?php

class Migration_008 extends Migration {
    public function __construct() {
        parent::__construct(7, 8);
    }

    function migrate($pdo) {
        $pdo->exec("ALTER TABLE `channels` MODIFY `hash` varchar(64) NOT NULL;");

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>
