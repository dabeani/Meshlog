<?php

class MeshLogChannel extends MeshLogEntity {
    protected static $table = "channels";

    public $hash = null;
    public $name = null;
    public $enabled = null;
    public $created_at = null;

    public static function fromJson($data, $meshlog) {
        $m = new MeshLogChannel($meshlog);

        $m->hash = $data['channel']['hash'] ?? '11';
        $m->name = $data['channel']['name'] ?? 'unknown';
        $m->enabled = true; // default

        return $m;
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogChannel($meshlog);

        $m->_id = $data['id'];
        $m->hash = $data['hash'];
        $m->name = $data['name'];
        $m->enabled = $data['enabled'];
        $m->created_at = $data['created_at'];

        return $m;
    }

    function isValid() {
        if ($this->hash == null) { $this->error = "Missing hash"; return false; }
        if ($this->name == null) { $this->error = "Missing name"; return false; }

        return true;
    }

    public function asArray($secret = false) {
        return array(
            'id' => $this->getId(),
            'hash' => $this->hash,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
            // include message count for admin UI
            'message_count' => $this->getMessageCount()
        );
    }

    protected function getParams() {
        return array(
            "hash" => array($this->hash, PDO::PARAM_STR),
            "name" => array($this->name, PDO::PARAM_STR),
            "enabled" => array($this->enabled, PDO::PARAM_INT),
        );
    }

    public function delete() {
        return $this->delete(false);
    }

    public function delete($force = false) {
        if (!$this->getId()) return false;

        try {
            $this->meshlog->pdo->beginTransaction();

            // Count messages
            $stmt = $this->meshlog->pdo->prepare("SELECT COUNT(*) AS cnt FROM channel_messages WHERE channel_id = :id");
            $stmt->bindParam(':id', $this->_id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = intval($row['cnt'] ?? 0);

            if ($count > 0 && !$force) {
                $this->meshlog->pdo->rollBack();
                $this->error = "Channel has $count messages and cannot be deleted without force";
                return false;
            }

            if ($count > 0 && $force) {
                // delete reports for messages
                $delReports = $this->meshlog->pdo->prepare(
                    "DELETE r FROM channel_message_reports r JOIN channel_messages m ON r.channel_message_id = m.id WHERE m.channel_id = :id"
                );
                $delReports->bindParam(':id', $this->_id, PDO::PARAM_INT);
                $delReports->execute();

                // delete messages
                $delMsgs = $this->meshlog->pdo->prepare("DELETE FROM channel_messages WHERE channel_id = :id");
                $delMsgs->bindParam(':id', $this->_id, PDO::PARAM_INT);
                $delMsgs->execute();
            }

            // finally delete channel
            $stmt = $this->meshlog->pdo->prepare("DELETE FROM channels WHERE id = :id");
            $stmt->bindParam(':id', $this->_id, PDO::PARAM_INT);
            $stmt->execute();

            $this->meshlog->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->meshlog->pdo->rollBack();
            $this->error = $e->getMessage();
            error_log($e->getMessage());
            return false;
        }
    }

    private function getMessageCount() {
        if (!$this->getId()) return 0;
        $stmt = $this->meshlog->pdo->prepare("SELECT COUNT(*) AS cnt FROM channel_messages WHERE channel_id = :id");
        $stmt->bindParam(':id', $this->_id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return intval($row['cnt'] ?? 0);
    }
}

?>