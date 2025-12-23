<?php
// Include the configuration file
require_once 'config.php';

// Enhanced function to get comprehensive duplicate bug analysis
function getDetailedDuplicateBugAnalysis($pdo) {
    $data = [];
    
    try {
        // Detailed duplicate bug patterns with full information
        $sql = "SELECT 
            SUBSTRING(LOWER(TRIM(title)), 1, 60) as pattern,
            COUNT(*) as total_occurrences,
            GROUP_CONCAT(DISTINCT id ORDER BY created_at DESC) as bug_ids,
            GROUP_CONCAT(DISTINCT title ORDER BY created_at DESC SEPARATOR ' ||| ') as all_titles,
            GROUP_CONCAT(DISTINCT module ORDER BY module) as affected_modules,
            GROUP_CONCAT(DISTINCT submodule ORDER BY submodule) as affected_submodules,
            COUNT(DISTINCT assigned_dev_id) as developers_involved,
            GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as developer_names,
            MIN(created_at) as first_occurrence,
            MAX(created_at) as last_occurrence,
            AVG(DATEDIFF(COALESCE(updated_at, NOW()), created_at)) as avg_resolution_time,
            GROUP_CONCAT(DISTINCT priority ORDER BY FIELD(priority, 'P1', 'P2', 'P3', 'P4')) as priority_levels,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as resolved_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count,
            GROUP_CONCAT(DISTINCT created_by ORDER BY created_at DESC) as reporter_ids,
            GROUP_CONCAT(DISTINCT r.name ORDER BY created_at DESC SEPARATOR ', ') as reporter_names,
            DATEDIFF(MAX(created_at), MIN(created_at)) as time_span_days,
            COUNT(CASE WHEN priority = 'P1' THEN 1 END) as critical_count,
            COUNT(CASE WHEN DATEDIFF(NOW(), created_at) > 7 AND status NOT IN ('approved', 'fixed') THEN 1 END) as stale_count
        FROM bug_tickets bt
        LEFT JOIN users u ON bt.assigned_dev_id = u.id
        LEFT JOIN users r ON bt.created_by = r.id
        GROUP BY pattern
        HAVING total_occurrences > 1
        ORDER BY total_occurrences DESC, last_occurrence DESC
        LIMIT 25";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['detailed_duplicates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Duplicate impact analysis
        $sql = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as total_bugs,
            COUNT(CASE WHEN pattern_count > 1 THEN 1 END) as duplicate_bugs,
            ROUND((COUNT(CASE WHEN pattern_count > 1 THEN 1 END) / COUNT(*)) * 100, 2) as duplicate_percentage,
            SUM(CASE WHEN pattern_count > 1 THEN pattern_count - 1 ELSE 0 END) as wasted_effort
        FROM (
            SELECT 
                created_at,
                COUNT(*) OVER (PARTITION BY SUBSTRING(LOWER(TRIM(title)), 1, 50)) as pattern_count
            FROM bug_tickets
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ) patterns
        GROUP BY month
        ORDER BY month";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['duplicate_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Module-wise duplicate analysis
        $sql = "SELECT 
            module,
            COUNT(*) as total_bugs,
            COUNT(CASE WHEN pattern_count > 1 THEN 1 END) as duplicate_bugs,
            ROUND((COUNT(CASE WHEN pattern_count > 1 THEN 1 END) / COUNT(*)) * 100, 2) as duplicate_percentage,
            COUNT(DISTINCT SUBSTRING(LOWER(TRIM(title)), 1, 40)) as unique_patterns,
            AVG(DATEDIFF(COALESCE(updated_at, NOW()), created_at)) as avg_resolution_time
        FROM (
            SELECT 
                module,
                title,
                created_at,
                updated_at,
                COUNT(*) OVER (PARTITION BY module, SUBSTRING(LOWER(TRIM(title)), 1, 40)) as pattern_count
            FROM bug_tickets
            WHERE module IS NOT NULL AND module != ''
        ) patterns
        GROUP BY module
        HAVING total_bugs > 3
        ORDER BY duplicate_percentage DESC, total_bugs DESC
        LIMIT 15";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['module_duplicates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Developer duplicate creation analysis
        $sql = "SELECT 
            u.name as developer_name,
            COUNT(*) as bugs_assigned,
            COUNT(CASE WHEN pattern_count > 1 THEN 1 END) as duplicate_assignments,
            ROUND((COUNT(CASE WHEN pattern_count > 1 THEN 1 END) / COUNT(*)) * 100, 2) as duplicate_assignment_rate,
            COUNT(DISTINCT SUBSTRING(LOWER(TRIM(bt.title)), 1, 40)) as unique_patterns_worked,
            AVG(DATEDIFF(COALESCE(bt.updated_at, NOW()), bt.created_at)) as avg_resolution_time
        FROM users u
        JOIN bug_tickets bt ON u.id = bt.assigned_dev_id
        JOIN (
            SELECT 
                id,
                COUNT(*) OVER (PARTITION BY SUBSTRING(LOWER(TRIM(title)), 1, 40)) as pattern_count
            FROM bug_tickets
        ) pc ON bt.id = pc.id
        WHERE u.role = 'developer'
        GROUP BY u.id, u.name
        HAVING bugs_assigned > 5
        ORDER BY duplicate_assignment_rate DESC, bugs_assigned DESC
        LIMIT 12";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['developer_duplicate_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Reporter duplicate creation analysis
        $sql = "SELECT 
            u.name as reporter_name,
            COUNT(*) as bugs_reported,
            COUNT(CASE WHEN pattern_count > 1 THEN 1 END) as duplicate_reports,
            ROUND((COUNT(CASE WHEN pattern_count > 1 THEN 1 END) / COUNT(*)) * 100, 2) as duplicate_report_rate,
            COUNT(DISTINCT SUBSTRING(LOWER(TRIM(bt.title)), 1, 40)) as unique_issues_found,
            COUNT(CASE WHEN bt.status = 'approved' THEN 1 END) as valid_reports
        FROM users u
        JOIN bug_tickets bt ON u.id = bt.created_by
        JOIN (
            SELECT 
                id,
                COUNT(*) OVER (PARTITION BY SUBSTRING(LOWER(TRIM(title)), 1, 40)) as pattern_count
            FROM bug_tickets
        ) pc ON bt.id = pc.id
        GROUP BY u.id, u.name
        HAVING bugs_reported > 3
        ORDER BY duplicate_report_rate DESC, bugs_reported DESC
        LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['reporter_duplicate_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        logError("Error in getDetailedDuplicateBugAnalysis: " . $e->getMessage());
    }
    
    return $data;
}

// Function to get developer performance on bugs
function getDeveloperBugPerformance($pdo) {
    $sql = "SELECT 
        u.id as developer_id,
        u.name as developer_name,
        COUNT(bt.id) as total_bugs,
        COUNT(CASE WHEN bt.status = 'approved' THEN 1 END) as fixed_bugs,
        COUNT(CASE WHEN bt.status = 'rejected' THEN 1 END) as rejected_bugs,
        COUNT(CASE WHEN bt.status = 'pending' THEN 1 END) as pending_bugs,
        COUNT(CASE WHEN bt.status = 'in_progress' THEN 1 END) as in_progress_bugs,
        AVG(CASE WHEN bt.status = 'approved' THEN 
            DATEDIFF(bt.updated_at, bt.created_at) 
        END) as avg_resolution_days,
        COUNT(CASE WHEN bt.priority = 'P1' THEN 1 END) as critical_bugs,
        COUNT(CASE WHEN DATEDIFF(NOW(), bt.created_at) > 7 AND bt.status NOT IN ('approved', 'fixed') THEN 1 END) as stale_bugs
    FROM users u
    LEFT JOIN bug_tickets bt ON u.id = bt.assigned_dev_id
    WHERE u.role = 'developer'
    GROUP BY u.id, u.name
    HAVING total_bugs > 0
    ORDER BY total_bugs DESC
    LIMIT 10";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        logError("Error in getDeveloperBugPerformance: " . $e->getMessage());
        return [];
    }
}

// Enhanced function to get comprehensive bug recurrence analysis
function getAdvancedBugRecurrenceAnalysis($pdo) {
    $data = [];
    
    try {
        // Find recurring bug patterns with more details
        $sql = "SELECT 
            SUBSTRING(LOWER(TRIM(title)), 1, 50) as pattern,
            COUNT(*) as occurrences,
            GROUP_CONCAT(DISTINCT module ORDER BY module) as affected_modules,
            GROUP_CONCAT(DISTINCT submodule ORDER BY submodule) as affected_submodules,
            COUNT(DISTINCT assigned_dev_id) as developers_involved,
            MIN(created_at) as first_occurrence,
            MAX(created_at) as last_occurrence,
            AVG(DATEDIFF(COALESCE(updated_at, NOW()), created_at)) as avg_resolution_time,
            GROUP_CONCAT(DISTINCT priority ORDER BY priority) as priority_levels,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as resolved_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            GROUP_CONCAT(DISTINCT CONCAT(id, ':', LEFT(title, 30)) ORDER BY created_at DESC SEPARATOR ' | ') as sample_tickets
        FROM bug_tickets
        GROUP BY pattern
        HAVING occurrences > 1
        ORDER BY occurrences DESC, last_occurrence DESC
        LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['recurring_patterns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Developer-specific recurrence patterns
        $sql = "SELECT 
            u.name as developer_name,
            COUNT(*) as total_bugs,
            COUNT(DISTINCT SUBSTRING(LOWER(TRIM(bt.title)), 1, 30)) as unique_patterns,
            COUNT(*) - COUNT(DISTINCT SUBSTRING(LOWER(TRIM(bt.title)), 1, 30)) as duplicate_work,
            ROUND(((COUNT(*) - COUNT(DISTINCT SUBSTRING(LOWER(TRIM(bt.title)), 1, 30))) / COUNT(*)) * 100, 1) as duplicate_percentage
        FROM users u
        JOIN bug_tickets bt ON u.id = bt.assigned_dev_id
        WHERE u.role = 'developer'
        GROUP BY u.id, u.name
        HAVING total_bugs > 3
        ORDER BY duplicate_percentage DESC
        LIMIT 8";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['developer_recurrence'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Severity progression analysis
        $sql = "SELECT 
            pattern,
            GROUP_CONCAT(DISTINCT priority ORDER BY created_at) as priority_progression,
            COUNT(*) as occurrences,
            CASE 
                WHEN GROUP_CONCAT(DISTINCT priority ORDER BY created_at) LIKE '%P4%P3%P2%' OR 
                     GROUP_CONCAT(DISTINCT priority ORDER BY created_at) LIKE '%P3%P2%P1%' THEN 'Escalating'
                WHEN GROUP_CONCAT(DISTINCT priority ORDER BY created_at) LIKE '%P2%P3%P4%' OR 
                     GROUP_CONCAT(DISTINCT priority ORDER BY created_at) LIKE '%P1%P2%P3%' THEN 'De-escalating'
                ELSE 'Stable'
            END as severity_trend
        FROM (
            SELECT 
                SUBSTRING(LOWER(TRIM(title)), 1, 40) as pattern,
                priority,
                created_at
            FROM bug_tickets
        ) patterns
        GROUP BY pattern
        HAVING occurrences > 1
        ORDER BY 
            CASE severity_trend 
                WHEN 'Escalating' THEN 1 
                WHEN 'Stable' THEN 2 
                WHEN 'De-escalating' THEN 3 
            END,
            occurrences DESC
        LIMIT 15";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['severity_progression'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        logError("Error in getAdvancedBugRecurrenceAnalysis: " . $e->getMessage());
    }
    
    return $data;
}

// Function to get main dashboard metrics with additional insights
function getEnhancedDashboardMetrics($pdo) {
    $data = [];
    
    try {
        // Status distribution with time tracking
        $sql = "SELECT 
            status,
            COUNT(*) as count,
            AVG(DATEDIFF(COALESCE(updated_at, NOW()), created_at)) as avg_time_in_status
        FROM bug_tickets 
        GROUP BY status";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['status_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Priority breakdown with resolution rates
        $sql = "SELECT 
            priority,
            COUNT(*) as count,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as resolved,
            ROUND((COUNT(CASE WHEN status = 'approved' THEN 1 END) / COUNT(*)) * 100, 1) as resolution_rate
        FROM bug_tickets 
        GROUP BY priority 
        ORDER BY FIELD(priority, 'P1', 'P2', 'P3', 'P4')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['priority_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enhanced monthly trends with predictions
        $sql = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as created_count,
            COUNT(CASE WHEN status IN ('approved', 'fixed') THEN 1 END) as resolved_count,
            COUNT(CASE WHEN priority = 'P1' THEN 1 END) as critical_count,
            AVG(DATEDIFF(COALESCE(updated_at, created_at), created_at)) as avg_resolution_time,
            COUNT(CASE WHEN DATEDIFF(NOW(), created_at) > 7 AND status NOT IN ('approved', 'fixed') THEN 1 END) as stale_count
        FROM bug_tickets
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['monthly_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top modules with comprehensive stats
        $sql = "SELECT 
            module,
            COUNT(*) as bug_count,
            COUNT(CASE WHEN priority = 'P1' THEN 1 END) as critical_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as resolved_count,
            AVG(DATEDIFF(COALESCE(updated_at, created_at), created_at)) as avg_resolution_time,
            COUNT(DISTINCT assigned_dev_id) as developers_involved
        FROM bug_tickets 
        GROUP BY module 
        ORDER BY bug_count DESC 
        LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['top_modules'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Bug reporter analysis
        $sql = "SELECT 
            u.name as reporter_name,
            COUNT(bt.id) as total_reports,
            COUNT(CASE WHEN bt.status = 'approved' THEN 1 END) as valid_reports,
            COUNT(CASE WHEN bt.status = 'rejected' THEN 1 END) as invalid_reports,
            ROUND((COUNT(CASE WHEN bt.status = 'approved' THEN 1 END) / COUNT(bt.id)) * 100, 1) as accuracy_rate,
            COUNT(CASE WHEN bt.priority = 'P1' THEN 1 END) as critical_reports
        FROM users u
        JOIN bug_tickets bt ON u.id = bt.created_by
        GROUP BY u.id, u.name
        HAVING total_reports > 2
        ORDER BY total_reports DESC
        LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data['top_reporters'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        logError("Error in getEnhancedDashboardMetrics: " . $e->getMessage());
    }
    
    return $data;
}

// Enhanced AI insights function
function generateAdvancedAIInsights($pdo, $data) {
    $insights = [];
    
    try {
        // AI Insight 1: Duplicate Bug Impact Analysis
        if (!empty($data['duplicates']['detailed_duplicates'])) {
            $totalDuplicates = count($data['duplicates']['detailed_duplicates']);
            $highImpactDuplicates = array_filter($data['duplicates']['detailed_duplicates'], function($dup) {
                return $dup['total_occurrences'] >= 5;
            });
            
            if (!empty($highImpactDuplicates)) {
                $topDuplicate = $highImpactDuplicates[0];
                $insights[] = [
                    'type' => 'duplicate_critical',
                    'title' => '🤖 Critical Duplicate Bug Alert',
                    'message' => "URGENT: Pattern '{$topDuplicate['pattern']}...' has {$topDuplicate['total_occurrences']} duplicates across {$topDuplicate['developers_involved']} developers, spanning {$topDuplicate['time_span_days']} days. This represents significant wasted development effort.",
                    'priority' => 'critical'
                ];
            }
            
            $totalWastedEffort = array_sum(array_map(function($dup) {
                return $dup['total_occurrences'] - 1;
            }, $data['duplicates']['detailed_duplicates']));
            
            if ($totalWastedEffort > 20) {
                $insights[] = [
                    'type' => 'efficiency_analysis',
                    'title' => '🤖 Development Efficiency Analysis',
                    'message' => "AI detected {$totalWastedEffort} redundant bug reports across {$totalDuplicates} patterns. Implementing better bug tracking and search functionality could significantly improve development efficiency.",
                    'priority' => 'high'
                ];
            }
        }
        
        // AI Insight 2: Recurrence Pattern Analysis
        if (!empty($data['recurrence']['recurring_patterns'])) {
            $topPattern = $data['recurrence']['recurring_patterns'][0];
            
            if ($topPattern['occurrences'] > 5) {
                $insights[] = [
                    'type' => 'recurrence_critical',
                    'title' => '🤖 Critical Recurrence Alert',
                    'message' => "URGENT: Pattern '{$topPattern['pattern']}...' has occurred {$topPattern['occurrences']} times across {$topPattern['developers_involved']} developers. This indicates a systemic issue requiring immediate root cause analysis.",
                    'priority' => 'critical'
                ];
            }
        }
        
        // AI Insight 3: Developer Workload Analysis
        if (!empty($data['developers'])) {
            $overloadedDevs = array_filter($data['developers'], function($dev) {
                return ($dev['pending_bugs'] + $dev['in_progress_bugs']) > 8;
            });
            
            if (!empty($overloadedDevs)) {
                $devNames = array_column($overloadedDevs, 'developer_name');
                $insights[] = [
                    'type' => 'workload_alert',
                    'title' => '🤖 Developer Workload Alert',
                    'message' => "Developers " . implode(', ', $devNames) . " are currently overloaded with active bugs. High workload can impact code quality and increase bug recurrence rates.",
                    'priority' => 'medium'
                ];
            }
        }
        
        // AI Insight 4: Module Risk Analysis
        if (!empty($data['duplicates']['module_duplicates'])) {
            $riskyModules = array_filter($data['duplicates']['module_duplicates'], function($module) {
                return $module['duplicate_percentage'] > 30;
            });
            
            if (!empty($riskyModules)) {
                $moduleNames = array_column(array_slice($riskyModules, 0, 3), 'module');
                $insights[] = [
                    'type' => 'module_risk',
                    'title' => '🤖 High-Risk Module Analysis',
                    'message' => "Modules " . implode(', ', $moduleNames) . " show high duplicate bug rates (>30%). These modules may benefit from code reviews and architectural improvements.",
                    'priority' => 'medium'
                ];
            }
        }
        
    } catch(Exception $e) {
        logError("Error in generateAdvancedAIInsights: " . $e->getMessage());
    }
    
    return $insights;
}

// Get all enhanced data
$developerPerformance = getDeveloperBugPerformance($pdo);
$bugRecurrence = getAdvancedBugRecurrenceAnalysis($pdo);
$mainMetrics = getEnhancedDashboardMetrics($pdo);
$duplicateAnalysis = getDetailedDuplicateBugAnalysis($pdo);

// Combine data for AI analysis
$combinedData = [
    'developers' => $developerPerformance,
    'recurrence' => $bugRecurrence,
    'metrics' => $mainMetrics,
    'duplicates' => $duplicateAnalysis
];

$aiInsights = generateAdvancedAIInsights($pdo, $combinedData);

// Calculate enhanced summary stats
$totalBugs = 0;
$approvedBugs = 0;
$pendingBugs = 0;
$criticalBugs = 0;
$avgResolutionTime = 0;

if (!empty($mainMetrics['status_distribution'])) {
    foreach($mainMetrics['status_distribution'] as $status) {
        $totalBugs += $status['count'];
        if($status['status'] == 'approved') $approvedBugs += $status['count'];
        if($status['status'] == 'pending') $pendingBugs += $status['count'];
    }
}

if (!empty($mainMetrics['priority_breakdown'])) {
    foreach($mainMetrics['priority_breakdown'] as $priority) {
        if($priority['priority'] == 'P1') $criticalBugs = $priority['count'];
    }
}

$resolutionRate = $totalBugs > 0 ? round(($approvedBugs / $totalBugs) * 100, 1) : 0;
$recurrenceRate = !empty($bugRecurrence['recurring_patterns']) ? 
    round((count($bugRecurrence['recurring_patterns']) / max($totalBugs, 1)) * 100, 1) : 0;

// Calculate duplicate rate
$duplicateRate = 0;
if (!empty($duplicateAnalysis['detailed_duplicates'])) {
    $totalDuplicateInstances = array_sum(array_column($duplicateAnalysis['detailed_duplicates'], 'total_occurrences'));
    $duplicateRate = $totalBugs > 0 ? round(($totalDuplicateInstances / $totalBugs) * 100, 1) : 0;
}

// Calculate average resolution time
$totalResolutionTime = 0;
$resolvedCount = 0;
if (!empty($mainMetrics['status_distribution'])) {
    foreach($mainMetrics['status_distribution'] as $status) {
        if($status['status'] === 'approved' && isset($status['avg_time_in_status'])) {
            $totalResolutionTime += $status['avg_time_in_status'];
            $resolvedCount++;
        }
    }
}
$avgResolutionTime = $resolvedCount > 0 ? round($totalResolutionTime / $resolvedCount, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Advanced AI Bug Analysis Dashboard - Enhanced Edition</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Enhanced CSS Variables for Light and Dark Modes */
        :root {
            /* Light mode colors */
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --primary-light: rgba(59, 130, 246, 0.1);
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #06b6d4;
            --background-color: #f8fafc;
            --surface-color: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --border-radius: 12px;
            --box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --box-shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --glassmorphism: rgba(255, 255, 255, 0.25);
            --backdrop-blur: blur(16px);
        }

        /* Dark mode colors */
        [data-theme="dark"] {
            --primary-color: #60a5fa;
            --primary-hover: #3b82f6;
            --primary-light: rgba(96, 165, 250, 0.1);
            --secondary-color: #94a3b8;
            --success-color: #34d399;
            --warning-color: #fbbf24;
            --error-color: #f87171;
            --info-color: #22d3ee;
            --background-color: #0f172a;
            --surface-color: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --border-color: #334155;
            --box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
            --box-shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
            --glassmorphism: rgba(30, 41, 59, 0.7);
        }

        /* Advanced CSS Reset */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--background-color) 0%, var(--primary-light) 100%);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Advanced Animation Keyframes */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes rotateIn {
            from {
                opacity: 0;
                transform: rotate(-180deg) scale(0.5);
            }
            to {
                opacity: 1;
                transform: rotate(0deg) scale(1);
            }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }

        @keyframes bounce {
            0%, 20%, 53%, 80%, 100% {
                transform: translate3d(0, 0, 0);
            }
            40%, 43% {
                transform: translate3d(0, -10px, 0);
            }
            70% {
                transform: translate3d(0, -5px, 0);
            }
            90% {
                transform: translate3d(0, -2px, 0);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        /* Dashboard Container with Advanced Styling */
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
            animation: fadeInUp 1s ease-out;
        }

        /* Glassmorphism Header */
        .dashboard-header {
            background: var(--glassmorphism);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow-lg);
            position: relative;
            overflow: hidden;
            animation: slideInLeft 0.8s ease-out;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, var(--primary-light), transparent);
            animation: float 6s ease-in-out infinite;
            z-index: -1;
        }

        .header-controls {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            z-index: 10;
        }

        /* Enhanced Controls */
        .theme-toggle, .auto-refresh-toggle, .export-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: var(--glassmorphism);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 0.9rem;
            color: var(--text-secondary);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .theme-toggle:hover, .auto-refresh-toggle:hover, .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
            background: var(--primary-color);
            color: white;
        }

        .theme-toggle::before, .auto-refresh-toggle::before, .export-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .theme-toggle:hover::before, .auto-refresh-toggle:hover::before, .export-btn:hover::before {
            left: 100%;
        }

        /* Enhanced Switches */
        .theme-switch, .refresh-switch {
            position: relative;
            width: 50px;
            height: 28px;
            background: var(--border-color);
            border-radius: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .theme-switch.active, .refresh-switch.active {
            background: var(--primary-color);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2), 0 0 0 3px var(--primary-light);
        }

        .theme-switch::before, .refresh-switch::before {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .theme-switch.active::before, .refresh-switch.active::before {
            transform: translateX(22px) rotate(180deg);
        }

        /* Enhanced Header Content */
        .header-content {
            text-align: center;
            margin-bottom: 2rem;
            animation: scaleIn 1s ease-out 0.3s both;
        }

        .dashboard-title {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
            animation: bounce 2s ease-out 1s;
        }

        .dashboard-subtitle {
            font-size: 1.2rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            position: relative;
        }

        .dashboard-subtitle::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 2px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            border-radius: 1px;
        }

        /* Enhanced Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--glassmorphism);
            backdrop-filter: var(--backdrop-blur);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.6s ease-out;
            animation-fill-mode: both;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
        .stat-card:nth-child(5) { animation-delay: 0.5s; }
        .stat-card:nth-child(6) { animation-delay: 0.6s; }
        .stat-card:nth-child(7) { animation-delay: 0.7s; }
        .stat-card:nth-child(8) { animation-delay: 0.8s; }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary-color), var(--info-color));
            border-radius: 0 2px 2px 0;
        }

        .stat-card.critical::before { 
            background: linear-gradient(180deg, var(--error-color), #fca5a5); 
        }
        .stat-card.warning::before { 
            background: linear-gradient(180deg, var(--warning-color), #fde68a); 
        }
        .stat-card.success::before { 
            background: linear-gradient(180deg, var(--success-color), #86efac); 
        }

        .stat-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--box-shadow-lg);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.05), transparent);
            transform: rotate(45deg);
            transition: all 0.5s;
            opacity: 0;
        }

        .stat-card:hover::after {
            opacity: 1;
            animation: shimmer 1.5s ease-in-out;
        }

        /* Enhanced Stat Icons */
        .stat-icon {
            font-size: 2.5rem;
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            position: relative;
            overflow: hidden;
            animation: rotateIn 0.8s ease-out;
        }

        .stat-icon::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: float 3s ease-in-out infinite;
        }

        .stat-content h3 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            background: linear-gradient(135deg, var(--text-primary), var(--primary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-content p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 500;
        }

        /* Enhanced Dashboard Sections */
        .dashboard-section {
            background: var(--glassmorphism);
            backdrop-filter: var(--backdrop-blur);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out;
        }

        .dashboard-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color), var(--success-color));
        }

        /* Enhanced Section Headers */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .section-title-group {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .section-icon {
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: bounce 2s ease-in-out infinite;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .section-badge {
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            box-shadow: var(--box-shadow);
            animation: pulse 2s infinite;
        }

        /* Enhanced Content Grids */
        .content-grid {
            display: grid;
            gap: 2rem;
        }

        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); }
        .grid-4 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }

        /* Enhanced Chart Containers */
        .chart-container {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            border: 1px solid var(--border-color);
            height: 400px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .chart-container:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .chart-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color));
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            text-align: center;
            position: relative;
        }

        .chart-title::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 2px;
            background: var(--primary-color);
            border-radius: 1px;
        }

        /* Enhanced AI Insights */
        .ai-insights {
            display: grid;
            gap: 1.5rem;
        }

        .ai-insight {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            position: relative;
            border-left: 5px solid var(--primary-color);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideInLeft 0.6s ease-out;
            animation-fill-mode: both;
        }

        .ai-insight:nth-child(1) { animation-delay: 0.1s; }
        .ai-insight:nth-child(2) { animation-delay: 0.2s; }
        .ai-insight:nth-child(3) { animation-delay: 0.3s; }
        .ai-insight:nth-child(4) { animation-delay: 0.4s; }

        .ai-insight:hover {
            transform: translateX(5px);
            box-shadow: var(--box-shadow-lg);
        }

        .ai-insight.critical {
            border-left-color: var(--error-color);
            background: linear-gradient(135deg, var(--surface-color), rgba(239, 68, 68, 0.05));
        }

        .ai-insight.high {
            border-left-color: var(--warning-color);
            background: linear-gradient(135deg, var(--surface-color), rgba(245, 158, 11, 0.05));
        }

        .ai-insight.medium {
            border-left-color: var(--primary-color);
            background: linear-gradient(135deg, var(--surface-color), var(--primary-light));
        }

        .ai-insight.low {
            border-left-color: var(--success-color);
            background: linear-gradient(135deg, var(--surface-color), rgba(16, 185, 129, 0.05));
        }

        /* Enhanced Tables */
        .table-container {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
            position: relative;
        }

        .table-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color));
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: transparent;
        }

        .data-table th {
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            position: relative;
        }

        .data-table th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: rgba(255, 255, 255, 0.3);
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .data-table tr:hover {
            background: var(--primary-light);
            transform: scale(1.01);
        }

        /* Enhanced Badges */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: var(--box-shadow);
            transition: all 0.3s ease;
        }

        .badge:hover {
            transform: scale(1.1);
        }

        .badge-success { 
            background: linear-gradient(135deg, var(--success-color), #34d399);
            color: white; 
        }
        .badge-warning { 
            background: linear-gradient(135deg, var(--warning-color), #fbbf24);
            color: white; 
        }
        .badge-danger { 
            background: linear-gradient(135deg, var(--error-color), #f87171);
            color: white; 
        }
        .badge-info { 
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            color: white; 
        }

        /* Enhanced Duplicate Analysis Styles */
        .duplicate-detail-card {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: scaleIn 0.6s ease-out;
            animation-fill-mode: both;
        }

        .duplicate-detail-card:nth-child(1) { animation-delay: 0.1s; }
        .duplicate-detail-card:nth-child(2) { animation-delay: 0.2s; }
        .duplicate-detail-card:nth-child(3) { animation-delay: 0.3s; }

        .duplicate-detail-card:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: var(--box-shadow-lg);
        }

        .duplicate-detail-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--error-color), var(--warning-color));
        }

        .duplicate-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .duplicate-pattern {
            font-weight: 700;
            color: var(--text-primary);
            font-size: 1.1rem;
            flex: 1;
            margin-right: 1rem;
        }

        .duplicate-count {
            background: linear-gradient(135deg, var(--error-color), #f87171);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            box-shadow: var(--box-shadow);
            animation: pulse 2s infinite;
        }

        .duplicate-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .duplicate-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .duplicate-meta-item i {
            color: var(--primary-color);
        }

        .duplicate-tickets {
            background: var(--background-color);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-top: 1rem;
            border: 1px solid var(--border-color);
        }

        .duplicate-tickets-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .duplicate-tickets-list {
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        /* Live Indicator */
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--success-color);
            font-weight: 500;
        }

        .live-dot {
            width: 10px;
            height: 10px;
            background: var(--success-color);
            border-radius: 50%;
            animation: pulse 2s infinite;
            box-shadow: 0 0 0 0 var(--success-color);
        }

        /* Enhanced Responsive Design */
        @media (max-width: 768px) {
            .dashboard-container { 
                padding: 1rem; 
            }
            
            .stats-grid { 
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .grid-2, .grid-3, .grid-4 { 
                grid-template-columns: 1fr; 
            }
            
            .chart-container { 
                height: 300px; 
            }
            
            .dashboard-title { 
                font-size: 2rem; 
            }
            
            .header-controls { 
                position: static; 
                justify-content: center; 
                margin-bottom: 1rem;
                flex-wrap: wrap;
            }
            
            .duplicate-meta { 
                grid-template-columns: 1fr; 
            }
            
            .duplicate-header {
                flex-direction: column;
                gap: 1rem;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--background-color);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--primary-color), var(--info-color));
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--primary-hover), var(--primary-color));
        }

        /* Loading States */
        .loading-shimmer {
            background: linear-gradient(90deg, var(--border-color), var(--primary-light), var(--border-color));
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Enhanced Header Section -->
        <header class="dashboard-header">
            <div class="header-controls">
                <button class="export-btn" onclick="exportData()">
                    <i class="fas fa-download"></i>
                    Export Report
                </button>
                <button class="auto-refresh-toggle" onclick="toggleAutoRefresh()">
                    <i class="fas fa-sync-alt" id="refresh-icon"></i>
                    <span id="refresh-text">Auto Refresh</span>
                    <div class="refresh-switch" id="refresh-switch"></div>
                </button>
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-moon" id="theme-icon"></i>
                    <span id="theme-text">Dark Mode</span>
                    <div class="theme-switch" id="theme-switch"></div>
                </button>
            </div>
            
            <div class="header-content">
                <h1 class="dashboard-title">🤖 Advanced AI Bug Analysis Dashboard</h1>
                <p class="dashboard-subtitle">Intelligent Duplicate Detection & System Analytics</p>
                <p class="last-updated">
                    <span class="live-indicator">
                        <span class="live-dot"></span>
                        Live Data
                    </span>
                    | Last Updated: <?php echo date('M j, Y \a\t g:i A'); ?>
                </p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-bug"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $totalBugs; ?></h3>
                        <p>Total Bugs</p>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $approvedBugs; ?></h3>
                        <p>Resolved</p>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $pendingBugs; ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                <div class="stat-card critical">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $criticalBugs; ?></h3>
                        <p>Critical</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $resolutionRate; ?>%</h3>
                        <p>Resolution Rate</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-redo"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $recurrenceRate; ?>%</h3>
                        <p>Recurrence Rate</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-copy"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $duplicateRate; ?>%</h3>
                        <p>Duplicate Rate</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-stopwatch"></i></div>
                    <div class="stat-content">
                        <h3><?php echo $avgResolutionTime; ?></h3>
                        <p>Avg Resolution (days)</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Enhanced AI Insights Section -->
        <?php if (!empty($aiInsights)): ?>
        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-group">
                    <i class="section-icon fas fa-brain"></i>
                    <h2 class="section-title">AI-Generated Insights</h2>
                </div>
                <div class="section-badge"><?php echo count($aiInsights); ?> Insights</div>
            </div>
            
            <div class="ai-insights">
                <?php foreach($aiInsights as $insight): ?>
                    <div class="ai-insight <?php echo $insight['priority']; ?>">
                        <div class="ai-insight-header">
                            <div class="ai-insight-title">
                                <?php echo $insight['title']; ?>
                            </div>
                            <div class="ai-priority-badge <?php echo $insight['priority']; ?>">
                                <?php echo strtoupper($insight['priority']); ?>
                            </div>
                        </div>
                        <div class="ai-insight-message">
                            <?php echo $insight['message']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Comprehensive Duplicate Bug Analysis Section -->
        <?php if (!empty($duplicateAnalysis['detailed_duplicates'])): ?>
        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-group">
                    <i class="section-icon fas fa-copy"></i>
                    <h2 class="section-title">Comprehensive Duplicate Bug Analysis</h2>
                </div>
                <div class="section-badge"><?php echo count($duplicateAnalysis['detailed_duplicates']); ?> Patterns</div>
            </div>
            
            <!-- Duplicate Trends Chart -->
            <div class="content-grid grid-3">
                <?php if (!empty($duplicateAnalysis['duplicate_trends'])): ?>
                <div class="chart-container">
                    <h3 class="chart-title">Duplicate Bug Trends</h3>
                    <canvas id="duplicateTrendsChart"></canvas>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($duplicateAnalysis['module_duplicates'])): ?>
                <div class="chart-container">
                    <h3 class="chart-title">Module Duplicate Analysis</h3>
                    <canvas id="moduleDuplicatesChart"></canvas>
                </div>
                <?php endif; ?>
                
                <div class="chart-container">
                    <h3 class="chart-title">Duplicate Impact Distribution</h3>
                    <canvas id="duplicateImpactChart"></canvas>
                </div>
            </div>
            
            <!-- Detailed Duplicate Patterns -->
            <div style="margin-top: 2rem;">
                <h3 style="font-size: 1.3rem; margin-bottom: 1.5rem; color: var(--text-primary);">
                    🔍 Detailed Duplicate Bug Patterns
                </h3>
                
                <?php foreach(array_slice($duplicateAnalysis['detailed_duplicates'], 0, 10) as $duplicate): ?>
                    <div class="duplicate-detail-card">
                        <div class="duplicate-header">
                            <div class="duplicate-pattern">
                                "<?php echo htmlspecialchars(ucfirst($duplicate['pattern'])); ?>..."
                            </div>
                            <div class="duplicate-count">
                                <?php echo $duplicate['total_occurrences']; ?> occurrences
                            </div>
                        </div>
                        
                        <div class="duplicate-meta">
                            <div class="duplicate-meta-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo $duplicate['developers_involved']; ?> developers: <?php echo htmlspecialchars($duplicate['developer_names']); ?></span>
                            </div>
                            <div class="duplicate-meta-item">
                                <i class="fas fa-layer-group"></i>
                                <span>Modules: <?php echo htmlspecialchars($duplicate['affected_modules']); ?></span>
                            </div>
                            <div class="duplicate-meta-item">
                                <i class="fas fa-clock"></i>
                                <span>Avg Resolution: <?php echo number_format($duplicate['avg_resolution_time'], 1); ?> days</span>
                            </div>
                            <div class="duplicate-meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Time Span: <?php echo $duplicate['time_span_days']; ?> days</span>
                            </div>
                            <div class="duplicate-meta-item">
                                <i class="fas fa-exclamation"></i>
                                <span>Priorities: <?php echo htmlspecialchars($duplicate['priority_levels']); ?></span>
                            </div>
                            <div class="duplicate-meta-item">
                                <i class="fas fa-user-edit"></i>
                                <span>Reporters: <?php echo htmlspecialchars($duplicate['reporter_names']); ?></span>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                            <div style="text-align: center; padding: 0.5rem; background: var(--primary-light); border-radius: 8px;">
                                <div style="font-weight: 600; color: var(--success-color);"><?php echo $duplicate['resolved_count']; ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">Resolved</div>
                            </div>
                            <div style="text-align: center; padding: 0.5rem; background: var(--primary-light); border-radius: 8px;">
                                <div style="font-weight: 600; color: var(--warning-color);"><?php echo $duplicate['pending_count']; ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">Pending</div>
                            </div>
                            <div style="text-align: center; padding: 0.5rem; background: var(--primary-light); border-radius: 8px;">
                                <div style="font-weight: 600; color: var(--info-color);"><?php echo $duplicate['in_progress_count']; ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">In Progress</div>
                            </div>
                            <div style="text-align: center; padding: 0.5rem; background: var(--primary-light); border-radius: 8px;">
                                <div style="font-weight: 600; color: var(--error-color);"><?php echo $duplicate['rejected_count']; ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">Rejected</div>
                            </div>
                        </div>
                        
                        <div class="duplicate-tickets">
                            <div class="duplicate-tickets-title">Related Bug Titles:</div>
                            <div class="duplicate-tickets-list">
                                <?php 
                                $titles = explode(' ||| ', $duplicate['all_titles']);
                                foreach(array_slice($titles, 0, 3) as $title) {
                                    echo "• " . htmlspecialchars($title) . "<br>";
                                }
                                if (count($titles) > 3) {
                                    echo "... and " . (count($titles) - 3) . " more";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Developer and Reporter Analysis Tables -->
            <div class="content-grid grid-2" style="margin-top: 2rem;">
                <?php if (!empty($duplicateAnalysis['developer_duplicate_analysis'])): ?>
                <div class="table-container">
                    <h3 class="chart-title">Developer Duplicate Assignment Analysis</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Developer</th>
                                <th>Total Assigned</th>
                                <th>Duplicates</th>
                                <th>Duplicate Rate</th>
                                <th>Unique Patterns</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($duplicateAnalysis['developer_duplicate_analysis'] as $dev): ?>
                                <?php 
                                    $rateClass = $dev['duplicate_assignment_rate'] < 15 ? 'badge-success' : 
                                               ($dev['duplicate_assignment_rate'] < 30 ? 'badge-warning' : 'badge-danger');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($dev['developer_name']); ?></strong></td>
                                    <td><?php echo $dev['bugs_assigned']; ?></td>
                                    <td><?php echo $dev['duplicate_assignments']; ?></td>
                                    <td><span class="badge <?php echo $rateClass; ?>"><?php echo $dev['duplicate_assignment_rate']; ?>%</span></td>
                                    <td><?php echo $dev['unique_patterns_worked']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($duplicateAnalysis['reporter_duplicate_analysis'])): ?>
                <div class="table-container">
                    <h3 class="chart-title">Reporter Duplicate Creation Analysis</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Reporter</th>
                                <th>Total Reports</th>
                                <th>Duplicates</th>
                                <th>Duplicate Rate</th>
                                <th>Valid Reports</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($duplicateAnalysis['reporter_duplicate_analysis'] as $reporter): ?>
                                <?php 
                                    $rateClass = $reporter['duplicate_report_rate'] < 15 ? 'badge-success' : 
                                               ($reporter['duplicate_report_rate'] < 30 ? 'badge-warning' : 'badge-danger');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($reporter['reporter_name']); ?></strong></td>
                                    <td><?php echo $reporter['bugs_reported']; ?></td>
                                    <td><?php echo $reporter['duplicate_reports']; ?></td>
                                    <td><span class="badge <?php echo $rateClass; ?>"><?php echo $reporter['duplicate_report_rate']; ?>%</span></td>
                                    <td><?php echo $reporter['valid_reports']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Enhanced Bug Recurrence Analysis Section -->
        <?php if (!empty($bugRecurrence['recurring_patterns'])): ?>
        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-group">
                    <i class="section-icon fas fa-sync-alt"></i>
                    <h2 class="section-title">Bug Recurrence Analysis</h2>
                </div>
                <div class="section-badge"><?php echo count($bugRecurrence['recurring_patterns']); ?> Patterns</div>
            </div>
            
            <div class="content-grid grid-3">
                <!-- Severity Progression Analysis -->
                <?php if (!empty($bugRecurrence['severity_progression'])): ?>
                <div class="chart-container">
                    <h3 class="chart-title">Severity Progression Trends</h3>
                    <canvas id="severityProgressionChart"></canvas>
                </div>
                <?php endif; ?>
                
                <div class="chart-container">
                    <h3 class="chart-title">Recurrence Pattern Distribution</h3>
                    <canvas id="recurrenceDistributionChart"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Resolution Efficiency</h3>
                    <canvas id="resolutionEfficiencyChart"></canvas>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Enhanced Analytics Overview -->
        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-group">
                    <i class="section-icon fas fa-chart-bar"></i>
                    <h2 class="section-title">Performance Analytics</h2>
                </div>
            </div>
            
            <div class="content-grid grid-4">
                <div class="chart-container">
                    <h3 class="chart-title">Status Distribution</h3>
                    <canvas id="statusChart"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Priority vs Resolution</h3>
                    <canvas id="priorityChart"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Monthly Trends</h3>
                    <canvas id="monthlyChart"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Top Module Issues</h3>
                    <canvas id="moduleChart"></canvas>
                </div>
            </div>
        </section>

        <!-- Enhanced Developer Performance -->
        <?php if (!empty($developerPerformance)): ?>
        <section class="dashboard-section">
            <div class="section-header">
                <div class="section-title-group">
                    <i class="section-icon fas fa-user-cog"></i>
                    <h2 class="section-title">Developer Performance Matrix</h2>
                </div>
            </div>
            
            <div class="content-grid grid-2">
                <div class="chart-container">
                    <h3 class="chart-title">Resolution Efficiency vs Workload</h3>
                    <canvas id="developerMatrix"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Developer Performance Radar</h3>
                    <canvas id="developerRadarChart"></canvas>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <script>
        // Enhanced theme and refresh functionality
        let autoRefreshEnabled = false;
        let refreshInterval;

        function toggleTheme() {
            const html = document.documentElement;
            const themeIcon = document.getElementById('theme-icon');
            const themeText = document.getElementById('theme-text');
            const themeSwitch = document.getElementById('theme-switch');
            
            if (html.getAttribute('data-theme') === 'dark') {
                html.setAttribute('data-theme', 'light');
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark Mode';
                themeSwitch.classList.remove('active');
                localStorage.setItem('theme', 'light');
            } else {
                html.setAttribute('data-theme', 'dark');
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light Mode';
                themeSwitch.classList.add('active');
                localStorage.setItem('theme', 'dark');
            }
        }

        function toggleAutoRefresh() {
            const refreshIcon = document.getElementById('refresh-icon');
            const refreshText = document.getElementById('refresh-text');
            const refreshSwitch = document.getElementById('refresh-switch');
            
            if (autoRefreshEnabled) {
                autoRefreshEnabled = false;
                clearInterval(refreshInterval);
                refreshIcon.className = 'fas fa-sync-alt';
                refreshText.textContent = 'Auto Refresh';
                refreshSwitch.classList.remove('active');
                localStorage.setItem('autoRefresh', 'false');
            } else {
                autoRefreshEnabled = true;
                refreshInterval = setInterval(() => location.reload(), 300000); // 5 minutes
                refreshIcon.className = 'fas fa-pause';
                refreshText.textContent = 'Pause Refresh';
                refreshSwitch.classList.add('active');
                localStorage.setItem('autoRefresh', 'true');
            }
        }

        function exportData() {
            const reportData = {
                timestamp: new Date().toISOString(),
                summary: {
                    totalBugs: <?php echo $totalBugs; ?>,
                    approvedBugs: <?php echo $approvedBugs; ?>,
                    pendingBugs: <?php echo $pendingBugs; ?>,
                    criticalBugs: <?php echo $criticalBugs; ?>,
                    resolutionRate: <?php echo $resolutionRate; ?>,
                    recurrenceRate: <?php echo $recurrenceRate; ?>,
                    duplicateRate: <?php echo $duplicateRate; ?>
                },
                insights: <?php echo json_encode($aiInsights); ?>,
                duplicateAnalysis: <?php echo json_encode($duplicateAnalysis['detailed_duplicates'] ?? []); ?>,
                recurringPatterns: <?php echo json_encode($bugRecurrence['recurring_patterns'] ?? []); ?>
            };
            
            const blob = new Blob([JSON.stringify(reportData, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `enhanced-bug-analysis-report-${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Load saved preferences
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const savedAutoRefresh = localStorage.getItem('autoRefresh') === 'true';
            
            // Set theme
            const html = document.documentElement;
            const themeIcon = document.getElementById('theme-icon');
            const themeText = document.getElementById('theme-text');
            const themeSwitch = document.getElementById('theme-switch');
            
            html.setAttribute('data-theme', savedTheme);
            if (savedTheme === 'dark') {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light Mode';
                themeSwitch.classList.add('active');
            }
            
            // Set auto refresh
            if (savedAutoRefresh) {
                toggleAutoRefresh();
            }
        });

        // Enhanced chart data from PHP
        const mainMetrics = <?php echo json_encode($mainMetrics); ?>;
        const developerData = <?php echo json_encode($developerPerformance); ?>;
        const recurrenceData = <?php echo json_encode($bugRecurrence); ?>;
        const duplicateData = <?php echo json_encode($duplicateAnalysis); ?>;

        // Enhanced Chart.js configuration
        Chart.defaults.font.family = 'Inter';
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.padding = 20;

        // Enhanced Color Palettes
        const colorPalette = {
            primary: ['#3b82f6', '#60a5fa', '#93c5fd', '#dbeafe'],
            success: ['#10b981', '#34d399', '#6ee7b7', '#d1fae5'],
            warning: ['#f59e0b', '#fbbf24', '#fcd34d', '#fef3c7'],
            error: ['#ef4444', '#f87171', '#fca5a5', '#fee2e2'],
            info: ['#06b6d4', '#22d3ee', '#67e8f9', '#cffafe'],
            gradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
        };

        // Status Distribution Chart with enhanced animations
        if (mainMetrics.status_distribution && mainMetrics.status_distribution.length > 0) {
            new Chart(document.getElementById('statusChart'), {
                type: 'doughnut',
                data: {
                    labels: mainMetrics.status_distribution.map(s => s.status.toUpperCase()),
                    datasets: [{
                        data: mainMetrics.status_distribution.map(s => s.count),
                        backgroundColor: colorPalette.primary.concat(colorPalette.warning, colorPalette.success, colorPalette.error),
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverBorderWidth: 5,
                        hoverOffset: 10
                    }]
                },
                options: {
                    plugins: { 
                        legend: { 
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: { size: 12, weight: 600 }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((context.parsed / total) * 100);
                                    return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 2000,
                        easing: 'easeOutBounce'
                    }
                }
            });
        }

        // Priority vs Resolution Chart
        if (mainMetrics.priority_breakdown && mainMetrics.priority_breakdown.length > 0) {
            new Chart(document.getElementById('priorityChart'), {
                type: 'bar',
                data: {
                    labels: mainMetrics.priority_breakdown.map(p => p.priority),
                    datasets: [
                        {
                            label: 'Total',
                            data: mainMetrics.priority_breakdown.map(p => p.count),
                            backgroundColor: colorPalette.primary[0],
                            borderRadius: 8,
                            borderSkipped: false,
                        },
                        {
                            label: 'Resolved',
                            data: mainMetrics.priority_breakdown.map(p => p.resolved),
                            backgroundColor: colorPalette.success[0],
                            borderRadius: 8,
                            borderSkipped: false,
                        }
                    ]
                },
                options: {
                    plugins: { 
                        legend: { 
                            position: 'top',
                            labels: { font: { weight: 600 } }
                        }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            grid: { color: 'rgba(0, 0, 0, 0.1)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutQuart'
                    }
                }
            });
        }

        // Monthly Trends Chart
        if (mainMetrics.monthly_trends && mainMetrics.monthly_trends.length > 0) {
            new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: mainMetrics.monthly_trends.map(m => m.month),
                    datasets: [
                        {
                            label: 'Created',
                            data: mainMetrics.monthly_trends.map(m => m.created_count),
                            borderColor: colorPalette.error[0],
                            backgroundColor: colorPalette.error[3],
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            pointBackgroundColor: colorPalette.error[0],
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2
                        },
                        {
                            label: 'Resolved',
                            data: mainMetrics.monthly_trends.map(m => m.resolved_count),
                            borderColor: colorPalette.success[0],
                            backgroundColor: colorPalette.success[3],
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            pointBackgroundColor: colorPalette.success[0],
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2
                        }
                    ]
                },
                options: {
                    plugins: { 
                        legend: { 
                            position: 'top',
                            labels: { font: { weight: 600 } }
                        }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            grid: { color: 'rgba(0, 0, 0, 0.1)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutCubic'
                    }
                }
            });
        }

        // Module Issues Chart
        if (mainMetrics.top_modules && mainMetrics.top_modules.length > 0) {
            new Chart(document.getElementById('moduleChart'), {
                type: 'bar',
                data: {
                    labels: mainMetrics.top_modules.slice(0, 6).map(m => m.module),
                    datasets: [{
                        label: 'Bug Count',
                        data: mainMetrics.top_modules.slice(0, 6).map(m => m.bug_count),
                        backgroundColor: colorPalette.primary[0],
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    plugins: { 
                        legend: { display: false }
                    },
                    scales: { 
                        x: { 
                            beginAtZero: true,
                            grid: { color: 'rgba(0, 0, 0, 0.1)' }
                        },
                        y: {
                            grid: { display: false }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutBounce'
                    }
                }
            });
        }

        // Duplicate Trends Chart
        if (duplicateData.duplicate_trends && duplicateData.duplicate_trends.length > 0) {
            new Chart(document.getElementById('duplicateTrendsChart'), {
                type: 'line',
                data: {
                    labels: duplicateData.duplicate_trends.map(d => d.month),
                    datasets: [
                        {
                            label: 'Total Bugs',
                            data: duplicateData.duplicate_trends.map(d => d.total_bugs),
                            borderColor: colorPalette.primary[0],
                            backgroundColor: colorPalette.primary[3],
                            tension: 0.4,
                            fill: false,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        },
                        {
                            label: 'Duplicate Bugs',
                            data: duplicateData.duplicate_trends.map(d => d.duplicate_bugs),
                            borderColor: colorPalette.error[0],
                            backgroundColor: colorPalette.error[3],
                            tension: 0.4,
                            fill: true,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }
                    ]
                },
                options: {
                    plugins: { 
                        legend: { position: 'top' }
                    },
                    scales: { 
                        y: { beginAtZero: true }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutCubic'
                    }
                }
            });
        }

        // Module Duplicates Chart
        if (duplicateData.module_duplicates && duplicateData.module_duplicates.length > 0) {
            new Chart(document.getElementById('moduleDuplicatesChart'), {
                type: 'bar',
                data: {
                    labels: duplicateData.module_duplicates.slice(0, 8).map(m => m.module),
                    datasets: [{
                        label: 'Duplicate %',
                        data: duplicateData.module_duplicates.slice(0, 8).map(m => m.duplicate_percentage),
                        backgroundColor: duplicateData.module_duplicates.slice(0, 8).map(m => 
                            m.duplicate_percentage > 30 ? colorPalette.error[0] :
                            m.duplicate_percentage > 15 ? colorPalette.warning[0] : colorPalette.success[0]
                        ),
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: { 
                            ticks: { maxRotation: 45 }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutBounce'
                    }
                }
            });
        }

        // Duplicate Impact Chart
        if (duplicateData.detailed_duplicates && duplicateData.detailed_duplicates.length > 0) {
            const impactData = duplicateData.detailed_duplicates.slice(0, 8);
            new Chart(document.getElementById('duplicateImpactChart'), {
                type: 'bubble',
                data: {
                    datasets: [{
                        label: 'Duplicate Impact',
                        data: impactData.map(d => ({
                            x: d.total_occurrences,
                            y: d.developers_involved,
                            r: Math.max(5, d.time_span_days / 5)
                        })),
                        backgroundColor: colorPalette.error[0] + '80',
                        borderColor: colorPalette.error[0],
                        borderWidth: 2
                    }]
                },
                options: {
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const item = impactData[context.dataIndex];
                                    return `Pattern: ${item.pattern.substring(0, 30)}... | Occurrences: ${context.parsed.x} | Developers: ${context.parsed.y} | Time Span: ${item.time_span_days} days`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { 
                            title: { display: true, text: 'Total Occurrences' },
                            beginAtZero: true
                        },
                        y: { 
                            title: { display: true, text: 'Developers Involved' },
                            beginAtZero: true
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutBack'
                    }
                }
            });
        }

        // Developer Performance Matrix (Enhanced Bubble Chart)
        if (developerData && developerData.length > 0) {
            const bubbleData = developerData.slice(0, 8).map((dev, index) => ({
                x: dev.total_bugs,
                y: dev.total_bugs > 0 ? Math.round((dev.fixed_bugs / dev.total_bugs) * 100) : 0,
                r: Math.max(8, (dev.avg_resolution_days || 0) * 3),
                label: dev.developer_name
            }));

            new Chart(document.getElementById('developerMatrix'), {
                type: 'bubble',
                data: {
                    datasets: [{
                        label: 'Developer Performance',
                        data: bubbleData,
                        backgroundColor: colorPalette.primary.map(c => c + '80'),
                        borderColor: colorPalette.primary,
                        borderWidth: 2
                    }]
                },
                options: {
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const dev = bubbleData[context.dataIndex];
                                    return `${dev.label}: Bugs: ${context.parsed.x}, Success: ${context.parsed.y}%, Avg Days: ${Math.round(context.parsed.r / 3)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { 
                            title: { display: true, text: 'Total Bugs', font: { weight: 600 } },
                            beginAtZero: true
                        },
                        y: { 
                            title: { display: true, text: 'Success Rate (%)', font: { weight: 600 } },
                            beginAtZero: true,
                            max: 100
                        }
                    },
                    animation: {
                        duration: 2500,
                        easing: 'easeOutElastic'
                    }
                }
            });
        }

        // Developer Radar Chart
        if (developerData && developerData.length > 0) {
            const topDev = developerData[0];
            new Chart(document.getElementById('developerRadarChart'), {
                type: 'radar',
                data: {
                    labels: ['Total Bugs', 'Resolution Rate', 'Speed', 'Critical Handling', 'Consistency'],
                    datasets: [{
                        label: topDev.developer_name,
                        data: [
                            Math.min(topDev.total_bugs / 10 * 100, 100),
                            topDev.total_bugs > 0 ? (topDev.fixed_bugs / topDev.total_bugs) * 100 : 0,
                            Math.max(0, 100 - (topDev.avg_resolution_days || 0) * 10),
                            Math.min((topDev.critical_bugs || 0) * 20, 100),
                            Math.max(0, 100 - (topDev.stale_bugs || 0) * 10)
                        ],
                        backgroundColor: colorPalette.primary[0] + '40',
                        borderColor: colorPalette.primary[0],
                        borderWidth: 2,
                        pointBackgroundColor: colorPalette.primary[0],
                        pointBorderColor: '#ffffff',
                        pointRadius: 5
                    }]
                },
                options: {
                    plugins: { 
                        legend: { position: 'top' }
                    },
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                stepSize: 20
                            }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutQuint'
                    }
                }
            });
        }

        // Severity Progression Chart
        if (recurrenceData.severity_progression && recurrenceData.severity_progression.length > 0) {
            const severityData = recurrenceData.severity_progression.slice(0, 10);
            new Chart(document.getElementById('severityProgressionChart'), {
                type: 'bar',
                data: {
                    labels: severityData.map(s => s.pattern.substring(0, 15) + '...'),
                    datasets: [{
                        label: 'Occurrences',
                        data: severityData.map(s => s.occurrences),
                        backgroundColor: severityData.map(s => 
                            s.severity_trend === 'Escalating' ? colorPalette.error[0] :
                            s.severity_trend === 'De-escalating' ? colorPalette.success[0] : colorPalette.warning[0]
                        ),
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                afterLabel: function(context) {
                                    const item = severityData[context.dataIndex];
                                    return `Trend: ${item.severity_trend}`;
                                }
                            }
                        }
                    },
                    scales: { 
                        y: { beginAtZero: true },
                        x: { 
                            ticks: { maxRotation: 45 }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutBounce'
                    }
                }
            });
        }

        // Recurrence Distribution Chart
        if (recurrenceData.recurring_patterns && recurrenceData.recurring_patterns.length > 0) {
            new Chart(document.getElementById('recurrenceDistributionChart'), {
                type: 'pie',
                data: {
                    labels: recurrenceData.recurring_patterns.slice(0, 6).map(p => p.pattern.substring(0, 20) + '...'),
                    datasets: [{
                        data: recurrenceData.recurring_patterns.slice(0, 6).map(p => p.occurrences),
                        backgroundColor: colorPalette.warning,
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverOffset: 10
                    }]
                },
                options: {
                    plugins: { 
                        legend: { 
                            position: 'bottom',
                            labels: { font: { size: 10 } }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutBounce'
                    }
                }
            });
        }

        // Resolution Efficiency Chart
        if (recurrenceData.recurring_patterns && recurrenceData.recurring_patterns.length > 0) {
            new Chart(document.getElementById('resolutionEfficiencyChart'), {
                type: 'scatter',
                data: {
                    datasets: [{
                        label: 'Resolution Efficiency',
                        data: recurrenceData.recurring_patterns.slice(0, 10).map(p => ({
                            x: p.occurrences,
                            y: p.resolved_count / p.occurrences * 100
                        })),
                        backgroundColor: colorPalette.info[0] + '80',
                        borderColor: colorPalette.info[0],
                        borderWidth: 2,
                        pointRadius: 8,
                        pointHoverRadius: 10
                    }]
                },
                options: {
                    plugins: { 
                        legend: { display: false }
                    },
                    scales: {
                        x: { 
                            title: { display: true, text: 'Occurrences' },
                            beginAtZero: true
                        },
                        y: { 
                            title: { display: true, text: 'Resolution Rate (%)' },
                            beginAtZero: true,
                            max: 100
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutBack'
                    }
                }
            });
        }
    </script>
</body>
</html>