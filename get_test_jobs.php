<?php
/**
 * Get valid jobs from database for testing
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'database/db.php';

// Get active jobs
$query = "SELECT job_reference, jobpoolid, url
          FROM appljobs
          WHERE job_reference IS NOT NULL
          AND job_reference != ''
          AND jobpoolid IS NOT NULL
          LIMIT 10";

$result = $db->query($query);

$jobs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $jobs[] = [
            'job_reference' => $row['job_reference'],
            'jobpoolid' => $row['jobpoolid'],
            'url' => $row['url']
        ];
    }
}

$response = [
    'success' => true,
    'count' => count($jobs),
    'jobs' => $jobs,
    'sample' => count($jobs) > 0 ? $jobs[0] : null
];

echo json_encode($response, JSON_PRETTY_PRINT);

$db->close();