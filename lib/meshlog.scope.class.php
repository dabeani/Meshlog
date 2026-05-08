<?php

class MeshLogScope extends MeshLogEntity {
    protected static $table = "scopes";

    public $number = null;
    public $name = null;
    public $description = null;
    public $created_at = null;
    public $updated_at = null;

    public static function fromJson($data, $meshlog) {
        $m = new MeshLogScope($meshlog);

        $m->number = intval($data['number'] ?? 0);
        $m->name = trim($data['name'] ?? '');
        $m->description = trim($data['description'] ?? '');

        return $m;
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogScope($meshlog);

        $m->_id = $data['id'];
        $m->number = intval($data['number'] ?? 0);
        $m->name = $data['name'];
        $m->description = $data['description'] ?? '';
        $m->created_at = $data['created_at'];
        $m->updated_at = $data['updated_at'];

        return $m;
    }

    function isValid() {
        if ($this->number === null || $this->number === '') {
            $this->error = "Missing scope number";
            return false;
        }

        $num = intval($this->number);
        if ($num < 0 || $num > 255) {
            $this->error = "Scope number must be between 0-255";
            return false;
        }

        if ($this->name === null || trim($this->name) === '') {
            $this->error = "Missing scope name";
            return false;
        }

        return true;
    }

    public function asArray($secret = false) {
        return array(
            'id' => $this->getId(),
            'number' => intval($this->number),
            'name' => $this->name,
            'description' => $this->description ?? '',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        );
    }

    protected function getParams() {
        return array(
            "number" => array(intval($this->number), PDO::PARAM_INT),
            "name" => array($this->name, PDO::PARAM_STR),
            "description" => array($this->description ?? '', PDO::PARAM_STR),
        );
    }

    /**
     * Get scope by number
     */
    public static function getByNumber($meshlog, $number) {
        try {
            $stmt = $meshlog->pdo->prepare("SELECT * FROM scopes WHERE number = :number LIMIT 1");
            $stmt->execute(array(':number' => intval($number)));
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::fromDb($data, $meshlog);
        } catch (Exception $e) {
            error_log("Error getting scope by number: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all scopes
     */
    public static function getAll($meshlog) {
        $scopes = array();
        try {
            $stmt = $meshlog->pdo->prepare("SELECT * FROM scopes ORDER BY number ASC");
            $stmt->execute();
            while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $scope = self::fromDb($data, $meshlog);
                if ($scope) {
                    $scopes[$scope->getId()] = $scope;
                }
            }
        } catch (Exception $e) {
            error_log("Error getting all scopes: " . $e->getMessage());
        }
        return $scopes;
    }

    /**
     * Delete scope by ID
     */
    public static function deleteById($meshlog, $id) {
        try {
            $stmt = $meshlog->pdo->prepare("DELETE FROM scopes WHERE id = :id");
            $stmt->execute(array(':id' => intval($id)));
            return true;
        } catch (Exception $e) {
            error_log("Error deleting scope: " . $e->getMessage());
            return false;
        }
    }
}

?>
