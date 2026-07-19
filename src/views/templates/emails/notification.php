<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($subject ?? 'Notificación'); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 300;
        }
        .content {
            padding: 30px;
        }
        .notification-type {
            background-color: #e8f5e8;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .notification-type h3 {
            margin: 0 0 10px 0;
            color: #28a745;
            font-size: 18px;
        }
        .message {
            font-size: 16px;
            line-height: 1.6;
            margin: 20px 0;
        }
        .details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .details h4 {
            color: #495057;
            margin-top: 0;
            font-size: 16px;
        }
        .details p {
            margin: 5px 0;
            font-size: 14px;
        }
        .action-button {
            display: inline-block;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 12px 25px;
            text-decoration: none;
            border-radius: 20px;
            font-weight: bold;
            margin: 15px 0;
            transition: transform 0.3s ease;
        }
        .action-button:hover {
            transform: translateY(-2px);
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 5px 0;
            color: #6c757d;
            font-size: 14px;
        }
        .priority-high {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
        }
        .priority-medium {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        .priority-low {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header priority-<?php echo htmlspecialchars($priority ?? 'medium'); ?>">
            <h1><?php echo htmlspecialchars($icon ?? '🔔'); ?> <?php echo htmlspecialchars($title ?? 'Notificación'); ?></h1>
        </div>
        
        <div class="content">
            <div class="notification-type">
                <h3><?php echo htmlspecialchars($type ?? 'Notificación del Sistema'); ?></h3>
                <p><?php echo htmlspecialchars($description ?? 'Has recibido una nueva notificación del sistema.'); ?></p>
            </div>
            
            <div class="message">
                <p>Hola <strong><?php echo htmlspecialchars($name ?? 'Usuario'); ?></strong>,</p>
                <p><?php echo nl2br(htmlspecialchars($message ?? 'No hay mensaje adicional.')); ?></p>
            </div>
            
            <?php if (!empty($details)): ?>
            <div class="details">
                <h4>📋 Detalles:</h4>
                <?php foreach ($details as $key => $value): ?>
                    <p><strong><?php echo htmlspecialchars(ucfirst($key)); ?>:</strong> <?php echo htmlspecialchars($value); ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($actionUrl)): ?>
            <div style="text-align: center;">
                <a href="<?php echo htmlspecialchars($actionUrl); ?>" class="action-button">
                    <?php echo htmlspecialchars($actionText ?? 'Ver Detalles'); ?>
                </a>
            </div>
            <?php endif; ?>
            
            <div style="background-color: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                <p style="margin: 0; font-size: 14px; color: #856404;">
                    <strong>📅 Fecha:</strong> <?php echo htmlspecialchars($date ?? date('Y-m-d H:i:s')); ?>
                </p>
                <?php if (!empty($expiresAt)): ?>
                <p style="margin: 5px 0 0 0; font-size: 14px; color: #856404;">
                    <strong>⏰ Expira:</strong> <?php echo htmlspecialchars($expiresAt); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>VNV-Events</strong> - Sistema de Notificaciones</p>
            <p>Para gestionar tus notificaciones, accede a tu panel de usuario</p>
            <p style="font-size: 12px; color: #adb5bd;">
                Este correo fue enviado automáticamente. Por favor no responder a este mensaje.<br>
                Visítanos en <a href="https://vnvevents.com/">VNV Events</a>
            </p>
        </div>
    </div>
</body>
</html>


