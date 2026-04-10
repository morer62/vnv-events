<?php

use App\Repositories\EventsRepository;
use App\Repositories\EventGuestsRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$user = LoginService::getSession();
$eventId = intval($_GET["id"] ?? 0);

$eventsRepo = new EventsRepository();
$event = $eventsRepo->getOne(["id" => $eventId, "id_user" => $user->getId()]);

if (!$event) {
    LocationUtils::redirectInternal("panel/event-invitations");
}

$guestsRepo = new EventGuestsRepository();
$allGuests = $guestsRepo->getAllByEvent($eventId);
$guests = array_filter($allGuests, function($g) {
    return $g->rsvp_status === 'confirmed';
});
$stats = $eventsRepo->getEventStats($eventId);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Guest List');

$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', '✅ ' . $event->event_name . ' - Confirmed Guests List');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF4CAF50');
$sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(30);

$sheet->mergeCells('A2:H2');
$eventDate = date('l, F j, Y', strtotime($event->event_date));
$eventTime = date('g:i A', strtotime($event->event_time));
$sheet->setCellValue('A2', "📅 {$eventDate} at {$eventTime}");
$sheet->getStyle('A2')->getFont()->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A3:H3');
$totalConfirmed = count($guests);
$sheet->setCellValue('A3', "✅ {$totalConfirmed} Confirmed Guests | Total Attending: {$stats['total_attending']} (including +{$stats['total_plus_ones']} plus ones)");
$sheet->getStyle('A3')->getFont()->setSize(10)->setBold(true);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headers = ['First Name', 'Last Name', 'Email', 'Phone', 'Group', 'RSVP', 'Plus Ones', 'Plus One Names'];
$sheet->fromArray($headers, null, 'A5');

$sheet->getStyle('A5:H5')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A5:H5')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF2196F3');
$sheet->getStyle('A5:H5')->getFont()->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A5:H5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A5:H5')->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);

$row = 6;
foreach ($guests as $guest) {
    $plusOnesNames = '';
    if (!empty($guest->plus_ones_names)) {
        $names = json_decode($guest->plus_ones_names, true);
        if (is_array($names)) {
            $plusOnesNames = implode(', ', $names);
        }
    }
    
    $rsvpStatus = match($guest->rsvp_status) {
        'confirmed' => '✅ Confirmed',
        'declined' => '❌ Declined',
        'tentative' => '⏳ Tentative',
        default => '⏱️ Pending'
    };
    
    $data = [
        $guest->first_name,
        $guest->last_name,
        $guest->email,
        $guest->phone ?? '-',
        $guest->guest_group ?? 'General',
        $rsvpStatus,
        $guest->plus_ones > 0 ? '+' . $guest->plus_ones : '0',
        $plusOnesNames ?: '-'
    ];
    
    $sheet->fromArray($data, null, "A{$row}");
    
    $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)
        ->getColor()->setARGB('FFE0E0E0');
    
    if ($row % 2 == 0) {
        $sheet->getStyle("A{$row}:H{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF5F5F5');
    }
    
    if ($guest->rsvp_status === 'confirmed') {
        $sheet->getStyle("F{$row}")->getFont()->getColor()->setARGB('FF4CAF50');
    } elseif ($guest->rsvp_status === 'declined') {
        $sheet->getStyle("F{$row}")->getFont()->getColor()->setARGB('FFF44336');
    }
    
    $row++;
}

foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$sheet->getColumnDimension('H')->setWidth(40);

$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $event->event_name);
$filename = "Confirmed_Guests_{$filename}_" . date('Y-m-d') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

