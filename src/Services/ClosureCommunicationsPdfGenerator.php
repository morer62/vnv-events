<?php

namespace App\Services;

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersClosureCommunicationsRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class ClosureCommunicationsPdfGenerator
{
    /**
     * Genera un PDF con todas las comunicaciones del cliente para una orden
     */
    public static function generateAndSave(int $orderId): string
    {
        $orderRepo = new OrdersRepository();
        $commRepo = new OrdersClosureCommunicationsRepository();
        $userRepo = new UserRepository();
        $institutionRepo = new InstitutionProfileRepository();

        $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
        if (!$order) {
            throw new Exception("Order not found");
        }
        $order = (object)$order;

        $client = $userRepo->getOne(["id" => $order->id_client]);
        $institution = $institutionRepo->getByOwner($order->id_owner);
        $institution = $institution ? json_decode(json_encode($institution), true) : [];

        $communications = $commRepo->getAllByOrder($orderId);

        $logoBase64 = '';
        if (!empty($institution["logo"])) {
            $logoPath = $institution["logo"];
            if (file_exists($logoPath)) {
                $logoContent = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoContent);
            }
        }

        $institutionName = $institution["name"] ?? "Institution";
        $institutionAddress = $institution["address"] ?? "";
        $institutionPhone = $institution["phone"] ?? "";
        $institutionEmail = $institution["email"] ?? "";

        $clientName = ($client->name ?? '') . ' ' . ($client->lastname ?? '');
        $clientEmail = $client->email ?? '';

        $communicationsHtml = '';
        foreach ($communications as $index => $comm) {
            $photoHtml = '';
            if (!empty($comm->photo_path)) {
                try {
                    // Verificar si es una URL o una ruta local
                    $isUrl = filter_var($comm->photo_path, FILTER_VALIDATE_URL);
                    
                    if ($isUrl) {
                        // Es una URL remota (Cloudinary, etc.)
                        $photoContent = @file_get_contents($comm->photo_path);
                        if ($photoContent !== false) {
                            $imageInfo = @getimagesizefromstring($photoContent);
                            $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';
                            $photoBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($photoContent);
                            $photoHtml = '<div style="margin-top: 10px; text-align: center;"><img src="' . $photoBase64 . '" style="max-width: 100%; max-height: 400px; border: 1px solid #ddd; border-radius: 4px;" /></div>';
                        }
                    } elseif (file_exists($comm->photo_path)) {
                        // Es una ruta local
                        $photoContent = file_get_contents($comm->photo_path);
                        $imageInfo = @getimagesizefromstring($photoContent);
                        $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';
                        $photoBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($photoContent);
                        $photoHtml = '<div style="margin-top: 10px; text-align: center;"><img src="' . $photoBase64 . '" style="max-width: 100%; max-height: 400px; border: 1px solid #ddd; border-radius: 4px;" /></div>';
                    }
                } catch (\Exception $e) {
                    // Si hay error cargando la imagen, continuar sin ella
                    error_log("Error loading image for communication: " . $e->getMessage());
                }
            }

            $communicationsHtml .= '
                <div style="margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; page-break-inside: avoid;">
                    <div style="font-weight: bold; color: #333; margin-bottom: 10px; font-size: 14px;">
                        Communication #' . ($index + 1) . ' - ' . date("M j, Y g:i A", strtotime($comm->created_at)) . '
                    </div>
                    <div style="color: #555; line-height: 1.6; margin-bottom: 10px;">
                        ' . nl2br(htmlspecialchars($comm->description ?? '')) . '
                    </div>
                    ' . $photoHtml . '
                </div>';
        }

        if (empty($communicationsHtml)) {
            $communicationsHtml = '<div style="text-align: center; color: #999; padding: 40px;">No communications recorded.</div>';
        }

        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Client Communications - Order VNV-341' . (int)$order->id . '</title>
        </head>
        <body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; color: #333;">
            <div style="max-width: 800px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 30px; border-bottom: 3px solid #667eea; padding-bottom: 20px;">
                    ' . ($logoBase64 ? '<img src="' . $logoBase64 . '" style="max-height: 80px; margin-bottom: 10px;" />' : '') . '
                    <h1 style="margin: 10px 0; color: #667eea;">Communications Report</h1>
                    <h2 style="margin: 5px 0; font-size: 18px; color: #666;">Order VNV-341' . (int)$order->id . '</h2>
                </div>

                <div style="margin-bottom: 30px;">
                    <h3 style="color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px;">Order Information</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 8px; font-weight: bold; width: 200px;">Client:</td>
                            <td style="padding: 8px;">' . htmlspecialchars($clientName) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; font-weight: bold;">Email:</td>
                            <td style="padding: 8px;">' . htmlspecialchars($clientEmail) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; font-weight: bold;">Event Date:</td>
                            <td style="padding: 8px;">' . date("l, F j, Y", strtotime($order->event_date)) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; font-weight: bold;">Address:</td>
                            <td style="padding: 8px;">' . htmlspecialchars($order->address) . '</td>
                        </tr>
                    </table>
                </div>

                <div style="margin-bottom: 30px;">
                    <h3 style="color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px;">Recorded Communications</h3>
                    ' . $communicationsHtml . '
                </div>

                <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #eee; text-align: center; color: #999; font-size: 12px;">
                    <p>Document generated on ' . date("F j, Y \a\t g:i A") . '</p>
                    <p>' . htmlspecialchars($institutionName) . '</p>
                </div>
            </div>
        </body>
        </html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');
        $options->set('isPhpEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $content = $dompdf->output();
        return FileUtils::saveFileFromContent($content, 'closure-communications-pdf', 'pdf');
    }
}
