<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido a VNV Venue</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        .content {
            padding: 40px 30px;
        }
        .welcome-message {
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .features {
            background-color: #f8f9fa;
            padding: 30px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .features h3 {
            color: #667eea;
            margin-top: 0;
            font-size: 20px;
        }
        .feature-list {
            list-style: none;
            padding: 0;
        }
        .feature-list li {
            padding: 8px 0;
            position: relative;
            padding-left: 25px;
        }
        .feature-list li:before {
            content: "✓";
            color: #28a745;
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            margin: 20px 0;
            transition: transform 0.3s ease;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 5px 0;
            color: #6c757d;
            font-size: 14px;
        }
        .social-links {
            margin: 20px 0;
        }
        .social-links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 ¡Bienvenido a VNV Venue!</h1>
            <p>Tu plataforma de gestión de eventos</p>
        </div>
        
        <div class="content">
            <div class="welcome-message">
                <p>Hola <strong><?php echo htmlspecialchars($name ?? 'Usuario'); ?></strong>,</p>
                <p>¡Nos complace darte la bienvenida a VNV Venue! Tu cuenta ha sido creada exitosamente y ya puedes comenzar a gestionar tus eventos de manera profesional.</p>
            </div>
            
            <div class="features">
                <h3>🚀 ¿Qué puedes hacer con VNV Venue?</h3>
                <ul class="feature-list">
                    <li>Gestionar eventos y reservas</li>
                    <li>Administrar tu equipo de trabajo</li>
                    <li>Controlar inventario y servicios</li>
                    <li>Generar contratos y facturas</li>
                    <li>Gestionar clientes y CRM</li>
                    <li>Programar eventos en calendario</li>
                    <li>Control de nómina y comisiones</li>
                </ul>
            </div>
            
            <div style="text-align: center;">
                <a href="<?php echo htmlspecialchars($loginUrl ?? '#'); ?>" class="cta-button">
                    Acceder a mi Panel
                </a>
            </div>
            
            <div style="background-color: #e3f2fd; padding: 20px; border-radius: 8px; margin: 30px 0;">
                <h4 style="color: #1976d2; margin-top: 0;">📧 Información de tu cuenta:</h4>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($email ?? 'No disponible'); ?></p>
                <p><strong>Fecha de registro:</strong> <?php echo htmlspecialchars($registrationDate ?? date('Y-m-d H:i:s')); ?></p>
                <p><strong>Nivel de acceso:</strong> <?php echo htmlspecialchars($userLevel ?? 'Usuario'); ?></p>
            </div>
        </div>
        
        <div class="footer">
            <div class="social-links">
                <a href="#">Facebook</a>
                <a href="#">Instagram</a>
                <a href="#">Twitter</a>
                <a href="#">LinkedIn</a>
            </div>
            <p><strong>VNV Venue</strong> - Gestión Profesional de Eventos</p>
            <p>Si tienes alguna pregunta, no dudes en contactarnos</p>
            <p style="font-size: 12px; color: #adb5bd;">
                Este correo fue enviado automáticamente. Por favor no responder a este mensaje.
            </p>
        </div>
    </div>
</body>
</html>


