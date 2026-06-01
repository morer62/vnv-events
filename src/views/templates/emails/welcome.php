<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido a VNV Events</title>
    <style>
        body {
            font-family: Arial, sans-serif;
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
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }
        .header {
            background: linear-gradient(135deg, #0b0b0c 0%, #247170 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 800;
        }
        .header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        .content {
            padding: 36px 30px;
        }
        .welcome-message {
            font-size: 17px;
            line-height: 1.6;
            margin-bottom: 26px;
        }
        .features {
            background-color: #f8fafc;
            padding: 26px;
            border-radius: 10px;
            margin: 28px 0;
            border: 1px solid #e5e7eb;
        }
        .features h3 {
            color: #247170;
            margin-top: 0;
            font-size: 20px;
        }
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .feature-list li {
            padding: 8px 0 8px 24px;
            position: relative;
        }
        .feature-list li:before {
            content: "✓";
            color: #0f766e;
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #6ed5d3 0%, #5ec6c4 100%);
            color: #081011;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 999px;
            font-weight: bold;
            margin: 20px 0;
        }
        .account-box {
            background-color: #eef8f8;
            padding: 20px;
            border-radius: 10px;
            margin: 28px 0;
            border: 1px solid #d7ecec;
        }
        .account-box h4 {
            color: #247170;
            margin-top: 0;
        }
        .footer {
            background-color: #f8fafc;
            padding: 28px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        .footer p {
            margin: 5px 0;
            color: #64748b;
            font-size: 14px;
        }
        .social-links {
            margin: 18px 0;
        }
        .social-links a {
            color: #247170;
            text-decoration: none;
            margin: 0 10px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Bienvenido a VNV Events</h1>
            <p>Tu espacio para eventos, ordenes y coordinacion operativa.</p>
        </div>

        <div class="content">
            <div class="welcome-message">
                <p>Hola <strong><?php echo htmlspecialchars($name ?? 'Usuario'); ?></strong>,</p>
                <p>Tu cuenta de VNV Events fue creada exitosamente. Ya puedes acceder a tu panel para revisar ordenes, eventos, mensajes y herramientas disponibles segun tu nivel de acceso.</p>
            </div>

            <div class="features">
                <h3>Que puedes hacer en VNV Events</h3>
                <ul class="feature-list">
                    <li>Revisar eventos, ordenes y solicitudes.</li>
                    <li>Comunicarte con el equipo o clientes cuando tu acceso lo permita.</li>
                    <li>Gestionar contratos, pagos y archivos relacionados con eventos.</li>
                    <li>Ver calendario, tareas, sesiones musicales o foros segun tu rol.</li>
                </ul>
            </div>

            <div style="text-align: center;">
                <a href="<?php echo htmlspecialchars($loginUrl ?? '#'); ?>" class="cta-button">Acceder a mi panel</a>
            </div>

            <div class="account-box">
                <h4>Informacion de tu cuenta</h4>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($email ?? 'No disponible'); ?></p>
                <p><strong>Fecha de registro:</strong> <?php echo htmlspecialchars($registrationDate ?? date('Y-m-d H:i:s')); ?></p>
                <p><strong>Nivel de acceso:</strong> <?php echo htmlspecialchars($userLevel ?? 'Usuario'); ?></p>
            </div>
        </div>

        <div class="footer">
            <div class="social-links">
                <a href="https://vnvevents.com">Website</a>
                <a href="https://vnvevents.com/contact">Contact</a>
            </div>
            <p><strong>VNV Events</strong> - Event planning and production.</p>
            <p>Si tienes alguna pregunta, contacta al equipo de VNV Events.</p>
            <p style="font-size: 12px; color: #94a3b8;">Este correo fue enviado automaticamente.</p>
        </div>
    </div>
</body>
</html>
