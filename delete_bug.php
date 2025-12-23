<?php
require_once 'config.php';
requireRole('tester');

$bugId = intval($_GET['id'] ?? 0);

// Verify bug exists, belongs to user, and is still pending
try {
    $stmt = $pdo->prepare("SELECT screenshot FROM bug_tickets WHERE id = ? AND created_by = ? AND status = 'pending'");
    $stmt->execute([$bugId, $_SESSION['user_id']]);
    $bug = $stmt->fetch();
    
    if (!$bug) {
        header('Location: my_bugs.php?error=' . urlencode('Bug not found or cannot be deleted'));
        exit();
    }
    
    // Delete the bug and related records
    $pdo->beginTransaction();
    
    // Delete related records first (foreign key constraints)
    $stmt = $pdo->prepare("DELETE FROM bug_remarks WHERE bug_id = ?");
    $stmt->execute([$bugId]);
    
    $stmt = $pdo->prepare("DELETE FROM bug_status_logs WHERE bug_id = ?");
    $stmt->execute([$bugId]);
    
    // Delete the bug ticket
    $stmt = $pdo->prepare("DELETE FROM bug_tickets WHERE id = ?");
    $stmt->execute([$bugId]);
    
    // Delete screenshot file if exists
    if ($bug['screenshot'] && file_exists(UPLOAD_DIR . $bug['screenshot'])) {
        unlink(UPLOAD_DIR . $bug['screenshot']);
    }
    
    $pdo->commit();
    
    header('Location: my_bugs.php?success=' . urlencode('Bug report deleted successfully'));
    exit();
    
} catch (PDOException $e) {
    $pdo->rollBack();
    header('Location: my_bugs.php?error=' . urlencode('Failed to delete bug report'));
    exit();
}
?>