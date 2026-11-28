<?php
// Generates and downloads standard CSV grading sheet template for Teachers and Registrar
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['teacher', 'registrar', 'admin'])) {
    http_response_code(403);
    die("Access denied");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="student_grades_upload_template.csv"');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Column Headers
fputcsv($output, [
    'LRN',
    'Student Name',
    'Grade Level',
    'Section',
    'School Year',
    'Semester',
    'Subject Code',
    'Subject Name',
    'Q1',
    'Q2',
    'Q3',
    'Q4',
    'Final Grade',
    'Remarks',
    'Teacher Name'
]);

// Sample Rows to guide teachers
fputcsv($output, [
    '136887180001',
    'Dela Cruz, Juan M.',
    'Grade 11',
    'STEM - Einstein',
    '2025-2026',
    '1st Semester',
    'OCC-11',
    'Oral Communication in Context',
    '88',
    '90',
    '',
    '',
    '89',
    'Passed',
    'Mr. Dela Cruz'
]);

fputcsv($output, [
    '136887180001',
    'Dela Cruz, Juan M.',
    'Grade 11',
    'STEM - Einstein',
    '2025-2026',
    '1st Semester',
    'GENMATH-11',
    'General Mathematics',
    '86',
    '90',
    '',
    '',
    '88',
    'Passed',
    'Mrs. Reyes'
]);

fputcsv($output, [
    '136887180002',
    'Enrico Capistrano',
    'Grade 11',
    'STEM - Einstein',
    '2025-2026',
    '1st Semester',
    'OCC-11',
    'Oral Communication in Context',
    '92',
    '94',
    '',
    '',
    '93',
    'Passed',
    'Mr. Dela Cruz'
]);

fclose($output);
exit;
