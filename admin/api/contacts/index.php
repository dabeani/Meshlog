<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors  = array();
    $results = array('status' => 'unknown');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);

        if (isset($_POST['hide'])) {
            $contact = MeshLogContact::findById($id, $meshlog);
            if (!$contact) {
                $errors[] = 'Contact not found';
            } else {
                $hidden  = intval($_POST['hidden'] ?? 0) ? 1 : 0;
                $contact->hidden = $hidden;
                if ($contact->save($meshlog)) {
                    $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                    $meshlog->auditLog(
                        \MeshLogAuditLog::EVENT_CONTACT_HIDE,
                        $actor,
                        ($hidden ? 'hid' : 'unhid') . ' contact ' . ($contact->name ?? '') . ' [' . ($contact->public_key ?? '') . ']'
                    );
                    $results = array('status' => 'OK', 'hidden' => $hidden);
                } else {
                    $errors[] = 'Failed to save: ' . $contact->getError();
                }
            }
        } elseif (isset($_POST['delete'])) {
            $contact = MeshLogContact::findById($id, $meshlog);
            if ($contact && $contact->delete(true)) {
                $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                $meshlog->auditLog(
                    \MeshLogAuditLog::EVENT_CONTACT_DELETE,
                    $actor,
                    'deleted contact ' . ($contact->name ?? '') . ' [' . ($contact->public_key ?? '') . ']'
                );
                $results = array('status' => 'OK');
            } else {
                $errors[] = 'Failed to delete: ' . ($contact ? $contact->getError() : 'Contact not found');
            }
        } else {
            $errors[] = 'Unknown action';
        }
    } else {
        // GET: return contacts with optional search
        $search = trim($_GET['search'] ?? '');
        $offset = intval($_GET['offset'] ?? 0);
        $limit  = min(intval($_GET['count'] ?? 100), 500);

        $where  = '';
        $binds  = array();

        if ($search !== '') {
            $where  = 'WHERE (c.name LIKE :search OR c.public_key LIKE :search)';
            $term   = '%' . $search . '%';
            $binds[':search'] = array($term, PDO::PARAM_STR);
        }

        $sql = "
            SELECT c.id, c.public_key, c.name, c.hash_size, c.hidden,
                   c.last_heard_at, c.created_at,
                   (SELECT COUNT(*) FROM advertisements a WHERE a.contact_id = c.id) AS adv_count,
                   (SELECT COUNT(*) FROM direct_messages dm WHERE dm.contact_id = c.id) AS msg_count,
                   (SELECT COUNT(*) FROM channel_messages cm WHERE cm.contact_id = c.id) AS pub_count
            FROM contacts c
            $where
            ORDER BY c.last_heard_at DESC, c.id DESC
            LIMIT :offset, :limit
        ";

        $stmt = $meshlog->pdo->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v[0], $v[1]);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->execute();

        $objects = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $objects[] = array(
                'id'           => intval($row['id']),
                'public_key'   => $row['public_key'],
                'name'         => $row['name'],
                'hash_size'    => intval($row['hash_size'] ?? 1),
                'hidden'       => intval($row['hidden'] ?? 0),
                'adv_count'    => intval($row['adv_count']),
                'msg_count'    => intval($row['msg_count']),
                'pub_count'    => intval($row['pub_count']),
                'last_heard_at'=> $row['last_heard_at'],
                'created_at'   => $row['created_at'],
            );
        }
        $results = array('status' => 'OK', 'objects' => $objects);
    }

    if (count($errors)) {
        $results = array('status' => 'error', 'error' => implode("\n", $errors));
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
?>
