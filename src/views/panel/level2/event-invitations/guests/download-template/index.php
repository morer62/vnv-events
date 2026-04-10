<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$eventId = intval($_GET["event_id"] ?? 0);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Guest List');

$sheet->mergeCells('A1:E1');
$sheet->setCellValue('A1', '📋 Guest List Import Template');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF4CAF50');
$sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(30);

$sheet->mergeCells('A2:E2');
$sheet->setCellValue('A2', '📝 Instructions: Fill each row with one guest. Only Email is required.');
$sheet->getStyle('A2')->getFont()->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A3:E3');
$sheet->setCellValue('A3', '💡 Guest Group options: family, friends, coworkers, vip');
$sheet->getStyle('A3')->getFont()->setSize(10)->setItalic(true);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A4:E4');
$sheet->setCellValue('A4', '📞 Phone format: (305) 123-4567 or +1 (305) 123-4567');
$sheet->getStyle('A4')->getFont()->setSize(10)->setItalic(true);
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headers = ['First Name', 'Last Name', 'Email *', 'Phone', 'Guest Group'];
$sheet->fromArray($headers, null, 'A6');

$sheet->getStyle('A6:E6')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A6:E6')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF2196F3');
$sheet->getStyle('A6:E6')->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A6:E6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A6:E6')->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setWidth(20);
}

for ($i = 7; $i <= 56; $i++) {
    $sheet->getStyle("A{$i}:E{$i}")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)
        ->getColor()->setARGB('FFE0E0E0');
    
    if ($i % 2 == 0) {
        $sheet->getStyle("A{$i}:E{$i}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF5F5F5');
    }
}

$sheet->getRowDimension(6)->setRowHeight(25);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Guest_List_Template_Event_' . $eventId . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

