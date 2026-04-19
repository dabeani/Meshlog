<?php

class MeshLogContact extends MeshLogEntity {
    protected static $table = "contacts";

    public $public_key = null;
    public $enabled = null;
    public $hidden = null;
    public $name = null;
    public $hash_size = null;
    public $last_heard_at = null;
    public $created_at = null;

    public static function fromJson($data, $meshlog) {
        $m = new MeshLogContact($meshlog);
        
        if (!isset($data['contact'])) return $m;
        $m->public_key = $data['contact']['pubkey'] ?? null;
        $m->hash_size = $data['hash_size'] ?? 1;
        $m->enabled = true; // default

        return $m;
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogContact($meshlog);
        $m->_id = $data['id'];
        $m->public_key = $data['public_key'];
        $m->name = $data['name'];
        $m->enabled = $data['enabled'];
        $m->hidden = intval($data['hidden'] ?? 0);
        $m->hash_size = $data['hash_size'];
        $m->created_at = $data['created_at'];
        $m->last_heard_at = $data['last_heard_at'];

        return $m;
    }

    public function isValid() {
        if ($this->public_key == null) { $this->error = "Missing public key"; return false; };
        return parent::isValid();
    }

    public function asArray($secret = false) {
        return array(
            'id' => $this->getId(),
            'public_key' => $this->public_key,
            'name' => $this->name,
            'hash_size' => $this->hash_size,
            'hidden' => intval($this->hidden ?? 0),
            'created_at' => $this->created_at,
            'last_heard_at' => $this->last_heard_at,
        );
    }

    protected function getParams() {
        return array(
            "public_key" => array($this->public_key, PDO::PARAM_STR),
            "name" => array($this->name, PDO::PARAM_STR),
            "hash_size" => array($this->hash_size, PDO::PARAM_INT),
            "hidden" => array(intval($this->hidden ?? 0), PDO::PARAM_INT),
            "enabled" => array(intval($this->enabled ?? 1), PDO::PARAM_INT),
        );
    }

    public function updateHeardAt($meshlog) {
        $meshlog->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($this->isNew()) {
            return;
        }

        $tableStr = static::$table;
        $sql = " UPDATE $tableStr SET last_heard_at = NOW() WHERE id = :id";

        $query = $meshlog->pdo->prepare($sql);
        $query->bindValue(":id", $this->getId(), PDO::PARAM_INT);

        $result = false;
        try {
            $result = $query->execute();
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log($e->getMessage());
            return false;
        }
        return $result;
    }

    /**
     * Find a contact whose public_key starts with $hexPrefix (2 hex chars = 1 byte).
     * Used to link REQ/RESPONSE raw packets (which contain only a 1-byte src_hash)
     * back to a known contact.  Returns the first match or null when ambiguous / not found.
     */
    public static function findByHashPrefix($hexPrefix, $meshlog) {
        if (!$meshlog || strlen($hexPrefix) !== 2) return null;
        $like = strtoupper($hexPrefix) . '%';
        $query = $meshlog->pdo->prepare(
            "SELECT * FROM contacts WHERE public_key LIKE :prefix ORDER BY id ASC LIMIT 2"
        );
        $query->bindValue(':prefix', $like, PDO::PARAM_STR);
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        // Only link when exactly one contact matches — avoids false positives.
        if (count($rows) !== 1) return null;
        return static::fromDb($rows[0], $meshlog);
    }
}

?>
