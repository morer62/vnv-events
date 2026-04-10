<?php

use App\Services\LoginService;
use App\Repositories\TicketSalesRepository;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    if (!$user) {
        http_response_code(401);
        return "Unauthorized";
    }
    
    if (!in_array($user->getLevel(), [1, 2])) {
        http_response_code(403);
        return "Access denied";
    }
    
    $ticketId = $_GET['ticket_id'] ?? null;
    if (!$ticketId) {
        http_response_code(400);
        return "Ticket ID required";
    }
    
    $ticketSalesRepo = new TicketSalesRepository();
    $ticketSalesRepo->db = new \App\Repositories\Connection();
    
    $ticket = $ticketSalesRepo->getOne(['id' => $ticketId]);
    
    if (!$ticket) {
        http_response_code(404);
        return "Ticket not found";
    }
    
    $ticketCodes = json_decode($ticket->ticket_codes, true) ?? [];
    
    if (empty($ticketCodes)) {
        http_response_code(404);
        return "No ticket codes found";
    }
    
    $codeIndex = intval($_GET['code_index'] ?? 0);
    if (!isset($ticketCodes[$codeIndex])) {
        http_response_code(404);
        return "Ticket code not found";
    }
    
    $ticketCode = $ticketCodes[$codeIndex];
    
    $html = '
    <div style="display: flex; justify-content: center; align-items: center; min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); margin: -20px; padding: 20px;">
        <div style="background: white; border-radius: 20px; padding: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 100%;">
            <div style="margin-bottom: 30px;">
                <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 15px; border-radius: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 600;">🎫 Event Ticket</h3>
                </div>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="font-family: \'Courier New\', monospace; font-size: 16px; font-weight: bold; color: #495057; letter-spacing: 1px;">' . htmlspecialchars($ticketCode) . '</div>
                </div>
            </div>
            
            <div id="qrcode" style="margin: 20px 0; display: flex; justify-content: center;"></div>
            
            <div style="margin-top: 25px;">
                <div style="background: #e3f2fd; color: #1976d2; padding: 12px; border-radius: 10px; font-size: 14px; font-weight: 500;">
                    📱 Scan this QR code at the event entrance
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        setTimeout(function() {
            if (document.getElementById("qrcode")) {
                new QRCode(document.getElementById("qrcode"), {
                    text: "' . htmlspecialchars($ticketCode) . '",
                    width: 180,
                    height: 180,
                    colorDark: "#2c3e50",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            }
        }, 100);
    </script>';
    
    header('Content-Type: text/html');
    return $html;
});

$router->run();
