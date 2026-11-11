import re
import json

with open('raw_students.txt', 'r', encoding='utf-8') as f:
    lines = [line.strip() for line in f if line.strip()]

students = []
i = 0
while i < len(lines):
    if i + 8 < len(lines):
        u_name = lines[i]
        pwd = lines[i+1]
        fname = lines[i+2]
        mail = lines[i+3]
        lrn = lines[i+4]
        yr = lines[i+5]
        sec = lines[i+6]
        crs = lines[i+7]
        stat = lines[i+8]

        # Clean markdown email: e.g. [zekealvarez0522@gmail.com](mailto:zekealvarez0522@gmail.com)
        m = re.search(r'([a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+)', mail)
        if m:
            clean_email = m.group(1).strip()
        else:
            clean_email = mail.strip()

        # Clean markdown in password if any (e.g. [136886150130@Mariñas](mailto:...))
        m_pwd = re.search(r'\[([^\]]+)\]', pwd)
        if m_pwd:
            pwd = m_pwd.group(1).strip()

        # Clean name commas/spacing
        fname = re.sub(r',\s*', ', ', fname).strip()

        # Section formatting (e.g., "12 -Bouman" -> "12-Bouman")
        sec = re.sub(r'\s*-\s*', '-', sec.strip())

        dept = crs.strip()

        students.append({
            'username': u_name.strip(),
            'password': pwd.strip(),
            'full_name': fname,
            'email': clean_email,
            'id_number': lrn.strip(),
            'year_level': yr.strip(),
            'section': sec,
            'department': dept,
            'course': crs.strip(),
            'status': 'Active'
        })
        i += 9
    else:
        print(f"Remaining unprocessed lines at {i}: {lines[i:]}")
        break

total_count = len(students)
print(f"Total students parsed: {total_count}")

# Generate SQL
sql_statements = []
sql_statements.append("-- ========================================================")
sql_statements.append(f"-- TagSci Students Batch 1 Upload ({total_count} Students)")
sql_statements.append("-- ========================================================\n")

for s in students:
    u = s['username'].replace("'", "\\'")
    p = s['password'].replace("'", "\\'")
    fn = s['full_name'].replace("'", "\\'")
    em = s['email'].replace("'", "\\'")
    idn = s['id_number'].replace("'", "\\'")
    dept = s['department'].replace("'", "\\'")
    sec = s['section'].replace("'", "\\'")
    yr = s['year_level'].replace("'", "\\'")
    crs = s['course'].replace("'", "\\'")
    stat = s['status'].replace("'", "\\'")

    stmt = f"INSERT INTO `users` (`username`, `password`, `full_name`, `email`, `role`, `id_number`, `department`, `section`, `year_level`, `course`, `status`) VALUES ('{u}', '{p}', '{fn}', '{em}', 'student', '{idn}', '{dept}', '{sec}', '{yr}', '{crs}', '{stat}') ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `full_name` = VALUES(`full_name`), `id_number` = VALUES(`id_number`), `section` = VALUES(`section`), `year_level` = VALUES(`year_level`), `department` = VALUES(`department`), `course` = VALUES(`course`), `status` = 'Active';"
    sql_statements.append(stmt)

with open('database/students_batch1.sql', 'w', encoding='utf-8') as f:
    f.write("\n".join(sql_statements))

print("database/students_batch1.sql generated successfully!")

# Also generate import_students.php
json_students = json.dumps(students, indent=2, ensure_ascii=False)

php_content = """<?php
require_once __DIR__ . '/config/db_conn.php';

$json_data = <<<'JSON'
""" + json_students + """
JSON;

$students = json_decode($json_data, true);

$inserted = 0;
$updated = 0;
$errors = [];

$stmt = $pdo->prepare("INSERT INTO users 
    (username, password, full_name, email, role, id_number, department, section, year_level, course, status) 
    VALUES (?, ?, ?, ?, 'student', ?, ?, ?, ?, ?, 'Active')
    ON DUPLICATE KEY UPDATE 
        password = VALUES(password),
        full_name = VALUES(full_name),
        id_number = VALUES(id_number),
        section = VALUES(section),
        year_level = VALUES(year_level),
        department = VALUES(department),
        course = VALUES(course),
        status = 'Active'");

foreach ($students as $s) {
    try {
        $stmt->execute([
            $s['username'],
            $s['password'],
            $s['full_name'],
            $s['email'],
            $s['id_number'],
            $s['department'],
            $s['section'],
            $s['year_level'],
            $s['course']
        ]);
        if ($stmt->rowCount() == 1) {
            $inserted++;
        } else {
            $updated++;
        }
    } catch (Exception $e) {
        $errors[] = "Error for {$s['username']} ({$s['id_number']}): " . $e->getMessage();
    }
}

log_audit_action($pdo, 'System Administrator', 'ADMIN', "Imported " . count($students) . " Student Accounts (Batch 1)");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Student Data Import - i-AdminConnect</title>
  <link rel="stylesheet" href="css/style.css">
  <style>
    body { background: #f8fafc; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .card { background: #ffffff; border-radius: 16px; padding: 36px; max-width: 600px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.06); text-align: center; border: 1px solid #e2e8f0; }
    .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 24px 0; }
    .stat-box { background: #f1f5f9; padding: 16px; border-radius: 12px; }
    .stat-val { font-size: 2rem; font-weight: 800; color: #0f172a; }
    .stat-lbl { font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; margin-top: 4px; }
  </style>
</head>
<body>
  <div class="card">
    <div style="font-size: 3rem; margin-bottom: 12px;">🎉</div>
    <h1 style="font-size: 1.5rem; color: #0f172a; margin: 0 0 8px 0;">Student Import Complete!</h1>
    <p style="color: #64748b; font-size: 0.95rem; margin: 0;">Successfully processed <strong><?= count($students) ?></strong> student records into the database.</p>
    
    <div class="stats-grid">
      <div class="stat-box" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
        <div class="stat-val" style="color: #047857;"><?= $inserted + $updated ?></div>
        <div class="stat-lbl" style="color: #065f46;">Total Saved / Synced</div>
      </div>
      <div class="stat-box" style="background: #eff6ff; border: 1px solid #bfdbfe;">
        <div class="stat-val" style="color: #1d4ed8;"><?= count($students) ?></div>
        <div class="stat-lbl" style="color: #1e40af;">Records in Batch 1</div>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div style="text-align: left; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px; margin-bottom: 20px; font-size: 0.85rem; color: #b91c1c;">
        <strong>Warnings:</strong>
        <ul style="margin: 6px 0 0 16px; padding: 0;">
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px;">
      <a href="users.php" class="btn-primary-cta" style="padding: 10px 20px; text-decoration: none;">View User Directory</a>
      <a href="dashboard.php" class="btn-primary-cta" style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; box-shadow: none; padding: 10px 20px; text-decoration: none;">Go to Dashboard</a>
    </div>
  </div>
</body>
</html>
"""

with open('import_students.php', 'w', encoding='utf-8') as f:
    f.write(php_content)

print("import_students.php generated successfully!")
