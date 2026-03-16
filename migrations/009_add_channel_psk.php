<?php

class Migration_009 extends Migration {
    public function __construct() {
        parent::__construct(8, 9);
    }

    function migrate($pdo) {
        $pdo->exec("ALTER TABLE `channels` ADD `psk` varchar(200) NOT NULL DEFAULT '' AFTER `name`;");

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>
