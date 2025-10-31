<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'conf.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}



$action = $_REQUEST['action'] ?? 'fetch';

switch ($action) {
    case 'delete':
        $id = $_POST['id'] ?? null;
        $type = $_POST['type'] ?? null;
        
        if (!$id || !$type) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing parameters']));
        }
        
        $query = "DELETE FROM Entries WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ii', $id, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Delete failed']);
        }
        break;

    case 'update':
        $id            = $_POST['id'] ?? null;
        $type          = $_POST['type'] ?? null;
        $description   = $_POST['description'] ?? null;
        $amount        = $_POST['amount'] ?? null;
        $currency      = $_POST['currency'] ?? null;
        $date          = $_POST['date'] ?? null;
        $category      = $_POST['category'] ?? null;
        $macrocategory = $_POST['macrocategory'] ?? null;
        $source        = $_POST['source'] ?? null;
        $note          = $_POST['note'] ?? null;
        
        if (!$id || !$type || !$description || !$amount || !$date) {
            http_response_code(400);
            exit(json_encode(['error' => 'Missing parameters']));
        }
                
        $query = "UPDATE Entries SET description = ?, amount = ?, currency =?, date = ?, category = ?, macrocategory = ?, source = ?, note = ? WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sdssssssii', $description, $amount, $currency, $date, $category, $macrocategory, $source, $note, $id, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'id'            => $id,
                    'description'   => $description,
                    'type'          => $type,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'date'          => $date,
                    'category'      => $category,
                    'macrocategory' => $macrocategory,
                    'source'        => $source,
                    'note'          => $note
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Update failed']);
        }
        break;

    case 'fetch':
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $type = isset($_GET['type']) ? $_GET['type'] : 'both';
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01', strtotime('-1 year'));
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

        $transactions = [];


        $query = "SELECT * FROM Entries 
          WHERE user_id = ? AND date BETWEEN ? AND ?
          ORDER BY date DESC, id DESC 
          LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('issii', $_SESSION['user_id'], $start_date, $end_date, $limit, $offset);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'transactions' => $transactions,
            'hasMore' => count($transactions) === $limit
        ]);
        break;
        
    case 'search':
    $search = isset($_GET['query']) ? '%' . $_GET['query'] . '%' : '';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01', strtotime('-1 year'));
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    
    $query = "SELECT * FROM Entries 
              WHERE user_id = ? 
              AND (
                  LOWER(description) LIKE LOWER(?) 
                  OR LOWER(category) LIKE LOWER(?)
                  OR CAST(amount AS CHAR) LIKE ?
                  OR date LIKE ?
              )
              AND date BETWEEN ? AND ?
              ORDER BY date DESC, id DESC";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param('issssss', 
        $_SESSION['user_id'], 
        $search,
        $search,
        $search,
        $search,
        $start_date,
        $end_date
    );
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'transactions' => $transactions,
        'hasMore' => false
    ]);
    break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
?>