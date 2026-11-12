<?php
// download_template.php - Direct CSV template download for Bulk User Upload
$type = $_GET['type'] ?? 'students';

if ($type === 'teachers') {
    $filename = "teachers_template.csv";
    $csv = "username,password,full_name,email,employee_id,department,status\n" .
           "anthony.corado,4759702@Corado,\"Anthony P. Corado\",anthony.corado001@deped.gov.ph,4759702,Senior High School,Active\n" .
           "aphol.villanueva,4561949@Villanueva,\"Aphol F. Villanueva\",apholvillanueva01@deped.gov.ph,4561949,Junior High School,Active\n" .
           "archie.nazario,5906903@Nazario,\"Archie L. Nazario\",archie.nazario@deped.gov.ph,5906903,Senior High School,Active\n";
} else {
    $filename = "students_template.csv";
    $csv = "username,password,full_name,email,LRN,year_level,section,course,status\n" .
           "nathanrhon.ado,425818190003@Ado,\"Ado, Nathan Rhon D.\",nathanrhonado5@gmail.com,425818190003,Grade 7,7-Babbage,Junior High School,Active\n" .
           "isaiahjohn.agan,488063190002@Agan,\"Agan, Isaiah John L.\",agan.isaiah04@gmail.com,488063190002,Grade 7,7-Babbage,Junior High School,Active\n" .
           "ezekielkevin.alsonado,425714190075@Alsonado,\"Alsonado, Ezekiel Kevin Q.\",aezekielkevin@gmail.com,425714190075,Grade 7,7-Babbage,Junior High School,Active\n" .
           "juan.delacruz,654651@Delacruz,\"Juan C. Dela Cruz\",juan.delacruz@gmail.com,654651,Grade 7,7-Babbage,Junior High School,Active\n" .
           "maria.santos,654652@Santos,\"Maria S. Santos\",maria.santos@gmail.com,654652,Grade 7,7-Pascal,Junior High School,Active\n" .
           "mark.reyes,654653@Reyes,\"Mark A. Reyes\",mark.reyes@gmail.com,654653,Grade 11,11-Curie,STEM Strand,Active\n";
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Microsoft Excel compatibility
echo $csv;
exit;
