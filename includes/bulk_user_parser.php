<?php
/**
 * bulk_user_parser.php
 * Robust parser for Bulk User Upload (CSV, TSV, or Vertical Text)
 * Supports Excel paste, Google Sheets paste, and standard CSV files.
 */

function parse_bulk_users_input($input, $default_role = 'student', $default_status = 'Active') {
    $rows = [];
    $input = trim($input);
    if (empty($input)) return $rows;

    // 1. Strip UTF-8 BOM
    $input = preg_replace('/^\xEF\xBB\xBF/', '', $input);

    // 2. Normalize line breaks
    $lines = preg_split("/\r\n|\n|\r/", $input);
    $clean_lines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $clean_lines[] = $trimmed;
        }
    }
    if (empty($clean_lines)) return $rows;

    // 3. Check for Vertical Multiline Format (e.g., copied from list/cards where fields are line-by-line)
    $first_chunk = array_map('strtolower', array_slice($clean_lines, 0, 15));
    $is_vertical = false;
    $v_headers = [];

    if (in_array('email', $first_chunk) && (in_array('lrn', $first_chunk) || in_array('full_name', $first_chunk) || in_array('username', $first_chunk))) {
        $known_headers = ['username', 'password', 'full_name', 'email', 'lrn', 'id_number', 'year_level', 'section', 'course', 'department', 'status', 'role'];
        $idx = 0;
        while ($idx < count($clean_lines) && in_array(strtolower($clean_lines[$idx]), $known_headers)) {
            $v_headers[] = strtolower($clean_lines[$idx]);
            $idx++;
        }
        if (count($v_headers) >= 4) {
            $is_vertical = true;
            $header_count = count($v_headers);
            $data_lines = array_slice($clean_lines, $idx);
            for ($i = 0; $i < count($data_lines); $i += $header_count) {
                $chunk = array_slice($data_lines, $i, $header_count);
                if (count($chunk) < $header_count) break;
                $row = [];
                for ($h = 0; $h < $header_count; $h++) {
                    $row[$v_headers[$h]] = $chunk[$h];
                }
                $rows[] = $row;
            }
        }
    }

    // 4. Standard CSV / TSV Parsing
    if (!$is_vertical) {
        $first_line = $clean_lines[0];
        $tab_count = substr_count($first_line, "\t");
        $comma_count = substr_count($first_line, ",");
        $semi_count = substr_count($first_line, ";");

        $delim = ',';
        if ($tab_count > $comma_count && $tab_count > $semi_count) {
            $delim = "\t";
        } elseif ($semi_count > $comma_count && $semi_count > $tab_count) {
            $delim = ";";
        }

        $parsed_table = [];
        $fp = fopen("php://temp", 'r+');
        fputs($fp, $input);
        rewind($fp);
        while (($data = fgetcsv($fp, 0, $delim)) !== false) {
            if ($data === [null] || empty($data) || (count($data) === 1 && trim($data[0] ?? '') === '')) {
                continue;
            }
            $parsed_table[] = $data;
        }
        fclose($fp);

        if (count($parsed_table) >= 2) {
            // Normalize header names: lowercase and remove special characters
            $header_row = array_map(function($h) {
                $h = strtolower(trim($h));
                return preg_replace('/[^a-z0-9_]/', '', $h);
            }, $parsed_table[0]);

            for ($i = 1; $i < count($parsed_table); $i++) {
                $data_row = $parsed_table[$i];
                $row = [];
                foreach ($header_row as $h_idx => $col_name) {
                    if (isset($data_row[$h_idx])) {
                        $row[$col_name] = trim($data_row[$h_idx]);
                    }
                }
                $rows[] = $row;
            }
        }
    }

    // 5. Clean, Sanitize, and Validate Extracted Records
    $processed = [];
    foreach ($rows as $r) {
        // ID number / LRN
        $id_num = '';
        foreach (['lrn', 'id_number', 'idnumber', 'id', 'studentid', 'employeeid', 'lrnnumber'] as $k) {
            if (!empty($r[$k])) {
                $id_num = trim($r[$k]);
                break;
            }
        }

        // Full Name
        $full_name = '';
        foreach (['full_name', 'fullname', 'name', 'studentname', 'teachername'] as $k) {
            if (!empty($r[$k])) {
                $full_name = trim($r[$k]);
                break;
            }
        }

        // Email with Regex extraction to safely handle Markdown links like [abc@gmail.com](mailto:abc@gmail.com)
        $raw_email = '';
        foreach (['email', 'email_address', 'emailaddress', 'gmail', 'deped_email', 'depedemail'] as $k) {
            if (!empty($r[$k])) {
                $raw_email = trim($r[$k]);
                break;
            }
        }

        $clean_email = '';
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $raw_email, $matches)) {
            $clean_email = strtolower(trim($matches[0]));
        }

        // Skip rows without at least a Name and a valid Email
        if (empty($full_name) || empty($clean_email)) {
            continue;
        }

        // Year Level & Section
        $year_level = '';
        foreach (['year_level', 'yearlevel', 'grade_level', 'gradelevel', 'year', 'grade'] as $k) {
            if (!empty($r[$k])) {
                $year_level = trim($r[$k]);
                break;
            }
        }

        $section = trim($r['section'] ?? '');

        // Course & Department
        $course = '';
        foreach (['course', 'strand', 'academic_strand', 'program'] as $k) {
            if (!empty($r[$k])) {
                $course = trim($r[$k]);
                break;
            }
        }

        $department = trim($r['department'] ?? '');
        if (empty($department)) {
            $department = $course ?: ($year_level ? (stripos($year_level, '11') !== false || stripos($year_level, '12') !== false ? 'Senior High School' : 'Junior High School') : '');
        }
        if (empty($course)) {
            $course = $department;
        }

        // Role
        $role = strtolower(trim($r['role'] ?? $default_role));
        if (!in_array($role, ['student', 'teacher', 'registrar', 'admin'])) {
            $role = $default_role;
        }

        // Status
        $status = ucfirst(strtolower(trim($r['status'] ?? $default_status)));
        if (!in_array($status, ['Active', 'Pending', 'Inactive', 'Dropped'])) {
            $status = $default_status;
        }

        // Extract Last Name for Password Generation
        $last_name = '';
        if (strpos($full_name, ',') !== false) {
            $last_name = trim(explode(',', $full_name)[0]);
        } else {
            $parts = preg_split('/\s+/', $full_name);
            $last_name = end($parts);
        }
        $last_name_clean = ucfirst(preg_replace('/[^a-zA-Z]/', '', $last_name));
        if (empty($last_name_clean)) $last_name_clean = 'User';

        // Auto-generate Username if not specified
        $username = trim($r['username'] ?? '');
        if (empty($username)) {
            $email_prefix = explode('@', $clean_email)[0];
            $username = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower($email_prefix));
            if (empty($username)) {
                $username = strtolower($last_name_clean) . rand(100, 999);
            }
        }

        // Auto-generate Password if not specified: LRN@LastName (e.g. 425818190003@Ado)
        $password = trim($r['password'] ?? '');
        if (empty($password)) {
            if (!empty($id_num)) {
                $password = "{$id_num}@{$last_name_clean}";
            } else {
                $password = "TagSci@2026";
            }
        }

        $processed[] = [
            'username'   => $username,
            'password'   => $password,
            'full_name'  => $full_name,
            'email'      => $clean_email,
            'id_number'  => $id_num,
            'year_level' => $year_level,
            'section'    => $section,
            'course'     => $course,
            'department' => $department,
            'role'       => $role,
            'status'     => $status
        ];
    }

    return $processed;
}
