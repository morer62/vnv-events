<?php

use App\Utils\Router;
use App\Services\LoginService;
use App\Repositories\TicketSalesRepository;
use App\Repositories\VenueEventsRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    if (!$user) {
        http_response_code(401);
        return "Unauthorized";
    }
    
    $ticketId = $_GET['ticket_id'] ?? null;
    $codeIndex = intval($_GET['code_index'] ?? 0);
    
    if (!$ticketId) {
        http_response_code(400);
        return "Ticket ID required";
    }
    
    $ticketSalesRepo = new TicketSalesRepository();
    $ticketSalesRepo->db = new \App\Repositories\Connection();
    $venueEventsRepo = new VenueEventsRepository();
    $venueEventsRepo->db = new \App\Repositories\Connection();
    
    // Get ticket using getByBuyerEmail to ensure we have venue_event_id
    $tickets = $ticketSalesRepo->getByBuyerEmail($user->getEmail());
    $ticket = null;
    
    foreach ($tickets as $t) {
        if ($t->id == $ticketId) {
            $ticket = $t;
            break;
        }
    }
    
    if (!$ticket) {
        http_response_code(404);
        return "Ticket not found";
    }
    
    $event = $venueEventsRepo->getOne(['id' => $ticket->venue_event_id]);
    if (!$event) {
        http_response_code(404);
        return "Event not found";
    }
    
    $ticketCodes = json_decode($ticket->ticket_codes, true) ?? [];
    
    if (empty($ticketCodes)) {
        http_response_code(404);
        return "No ticket codes found";
    }
    
    // Check if specific code index is requested
    if (!isset($ticketCodes[$codeIndex])) {
        http_response_code(404);
        return "Ticket code not found";
    }
    
    $specificCode = $ticketCodes[$codeIndex];
    
    // Generate QR code for the specific ticket code
    $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($specificCode);
    
    // Generate HTML content with receipt-style design
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { 
                size: 80mm auto; 
                margin: 0; 
            }
            body { 
                font-family: "Courier New", monospace; 
                margin: 0; 
                padding: 8px; 
                font-size: 12px; 
                line-height: 1.2;
                background: white;
            }
            .receipt { 
                width: 100%; 
                max-width: 70mm; 
                margin: 0 auto; 
                background: white;
            }
            .header { 
                text-align: center; 
                border-bottom: 2px dashed #333; 
                padding-bottom: 8px; 
                margin-bottom: 8px;
            }
            .event-title { 
                font-size: 16px; 
                font-weight: bold; 
                margin-bottom: 8px;
                color: #2c3e50;
                text-transform: uppercase;
            }
            .event-info { 
                font-size: 11px; 
                color: #333;
            }
            .event-name { 
                font-size: 13px; 
                font-weight: bold; 
                margin-bottom: 4px;
                color: #1a1a1a;
            }
            .separator { 
                text-align: center; 
                margin: 8px 0; 
                font-size: 12px;
                color: #666;
            }
            .details { 
                margin: 8px 0;
            }
            .detail-row { 
                margin-bottom: 2px; 
                display: flex; 
                justify-content: space-between;
            }
            .detail-label { 
                font-weight: bold;
                color: #333;
            }
            .detail-value { 
                color: #1a1a1a;
            }
            .highlight { 
                font-weight: bold; 
                color: #d32f2f;
                font-size: 13px;
            }
            .qr-section { 
                margin: 12px 0; 
                text-align: center;
            }
            .qr-title { 
                font-weight: bold; 
                margin-bottom: 8px; 
                font-size: 13px;
                color: #2c3e50;
            }
            .qr-item { 
                margin-bottom: 12px; 
                border: 2px solid #333; 
                padding: 8px; 
                border-radius: 4px;
                background: #f9f9f9;
            }
            .qr-code { 
                margin-bottom: 6px;
            }
            .ticket-code { 
                font-family: "Courier New", monospace; 
                font-size: 12px; 
                font-weight: bold; 
                margin-bottom: 4px;
                letter-spacing: 1px;
                color: #1a1a1a;
                background: #fff;
                padding: 4px;
                border-radius: 2px;
            }
            .instructions { 
                font-size: 10px; 
                color: #666;
                font-style: italic;
            }
            .footer { 
                margin-top: 12px; 
                text-align: center; 
                font-size: 9px; 
                color: #666; 
                border-top: 1px dashed #333; 
                padding-top: 8px;
            }
            .thank-you { 
                font-weight: bold; 
                margin-top: 8px; 
                font-size: 11px;
                color: #2c3e50;
            }
        </style>
    </head>
    <body>
        <div class="receipt">
            <div class="header">
                <div class="event-title">EVENT TICKET</div>
                <div class="event-info">
                    <div class="event-name">' . htmlspecialchars($event->name) . '</div>
                    <div>Date: ' . date('M j, Y g:i A', strtotime($event->start_date)) . '</div>';
    
    if ($event->location) {
        $html .= '<div>Location: ' . htmlspecialchars($event->location) . '</div>';
    }
    
    $html .= '
                </div>
            </div>
            
            <div class="separator">*******************************</div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="detail-label">Ticket Type:</span>
                    <span>' . htmlspecialchars($ticket->ticket_type_name ?? 'General') . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Quantity:</span>
                    <span>' . $ticket->quantity . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Unit Price:</span>
                    <span>$' . number_format($ticket->unit_price, 2) . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total:</span>
                    <span class="highlight">$' . number_format($ticket->total_amount, 2) . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Purchased:</span>
                    <span>' . date('M j, Y g:i A', strtotime($ticket->sold_at)) . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Buyer:</span>
                    <span>' . htmlspecialchars($ticket->buyer_name) . '</span>
                </div>
            </div>
            
            <div class="separator">*******************************</div>
            
            <div class="qr-section">
                <div class="qr-title">TICKET CODE & QR CODE</div>
                <div class="qr-item">
                    <div class="qr-code">
                        <img src="' . $qrImageUrl . '" style="width: 80px; height: 80px;" alt="QR Code">
                    </div>
                    <div class="ticket-code">' . htmlspecialchars($specificCode) . '</div>
                    <div class="instructions">Present this QR code at the event entrance</div>
                </div>
            </div>
            
            <div class="footer">
                <div>This ticket is valid only for the specified event and date.</div>
                <div>Please arrive 30 minutes before the event start time.</div>
                <div class="thank-you">THANK YOU AND ENJOY THE EVENT!</div>
            </div>
        </div>
    </body>
    </html>';
    
    // Generate PDF using DomPDF
    $options = new Options();
    $options->set('defaultFont', 'Courier New');
    $options->set('isRemoteEnabled', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('80mm', 'portrait');
    $dompdf->render();
    
    $filename = 'ticket_' . $ticketId . '_code_' . $codeIndex . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    
    // Return empty string since DomPDF handles the output
    return '';
});

$router->run();